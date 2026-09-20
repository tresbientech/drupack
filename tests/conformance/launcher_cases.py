"""The launcher's own cache: cold starts, fixtures, and the cache states they build.

Ported from tests/launcher.sh cases 1-11 (case 0 moved in phase 2). Every case here is
Linux only, matching where the old file ran in CI.
"""

import os
import re
import signal
import subprocess
import time
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import urlopen

import harness

# The only line cache.go writes to standard error, once per unpacked version.
UNPACKING_PATTERN = re.compile(
    r"^Unpacking Drupack .+\. This happens once for each version\.$", re.MULTILINE
)
# The line Prepare writes when a corrupted payload falls back to the cached version.
FALLBACK_PATTERN = re.compile(
    r"^Could not unpack Drupack [^:]+: .*Using the runtime already in the cache\.$", re.MULTILINE
)

# The Linux distribution packs the drupack multicall binary as its entry; phase 10 packs
# frankenphp.exe for Windows through the same harness.pack_fixture.
ENTRY = "drupack"

# debian, pinned the way network_cases.py pins its own copy of the same image:
# docker buildx imagetools inspect debian --format '{{.Manifest.Digest}}'
DEBIAN_IMAGE = "debian@sha256:88200866dfff7ea7f5cbcb6ec7c8a701889efe6fe859fe64d6990e4b07ea4171"


def entry_count(cache_root):
    """Count cache_root's immediate subdirectories: one cache entry each."""
    return sum(1 for path in cache_root.iterdir() if path.is_dir())


def run(case_dir, executable, name, *args, env=None):
    """Run executable, capturing stdout and stderr into case_dir/name.{out,err} like the
    old script's own redirections, so a failure points straight at the same two files.
    """
    out_path = case_dir / f"{name}.out"
    err_path = case_dir / f"{name}.err"
    with open(out_path, "wb") as out_handle, open(err_path, "wb") as err_handle:
        result = subprocess.run(
            [str(executable), *args], cwd=case_dir, stdout=out_handle, stderr=err_handle,
            env=env, timeout=harness.WAITS["unpack"].seconds,
        )
    return result.returncode, out_path, err_path


class ColdWarmStart(harness.ConformanceCase):
    """Case 1 (cold start) and case 2 (warm start), against one private cache."""

    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)
        self.cache = harness.reserved_dir(self.case_dir / "cache")
        self.env = dict(os.environ, DRUPACK_CACHE_DIR=str(self.cache))

    def test_cold_start_then_warm_start(self):
        code, out, err = run(self.case_dir, harness.BINARY, "cold", "--help", env=self.env)
        self.assertEqual(code, 0, f"a cold start exited non-zero: inspect {err}")
        cold_err = err.read_text(errors="replace")
        self.assertEqual(
            len(UNPACKING_PATTERN.findall(cold_err)), 1,
            "a cold start did not print exactly one unpacking line to standard error",
        )
        self.assertEqual(
            len(UNPACKING_PATTERN.findall(out.read_text(errors="replace"))), 0,
            "a cold start leaked the unpacking line to standard output",
        )
        self.assertEqual(entry_count(self.cache), 1, "a cold start did not leave exactly one cache entry")
        key = next(path for path in self.cache.iterdir() if path.is_dir()).name
        self.assertTrue((self.cache / key / "manifest.json").is_file(), "the cache entry has no manifest.json")
        self.assertTrue((self.cache / key / "drupack").is_file(), "the cache entry has no drupack executable")

        code, out, err = run(self.case_dir, harness.BINARY, "warm", "--help", env=self.env)
        self.assertEqual(code, 0, f"a warm start exited non-zero: inspect {err}")
        self.assertEqual(
            len(UNPACKING_PATTERN.findall(err.read_text(errors="replace"))), 0,
            "a warm start printed an unpacking line",
        )
        self.assertEqual(entry_count(self.cache), 1, "a warm start changed the number of cache entries")
        self.assertTrue((self.cache / key).is_dir(), "a warm start replaced the cache entry")


class ConcurrentColdStart(harness.ConformanceCase):
    """Case 3: two cold starts racing the same private cache."""

    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_two_simultaneous_cold_starts(self):
        cache = harness.reserved_dir(self.case_dir / "cache")
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(cache))
        started = []
        for label in ("a", "b"):
            out_handle = open(self.case_dir / f"{label}.out", "wb")
            err_handle = open(self.case_dir / f"{label}.err", "wb")
            process = subprocess.Popen(
                [str(harness.BINARY), "--help"], cwd=self.case_dir,
                stdout=out_handle, stderr=err_handle, env=env,
            )
            started.append((label, process, out_handle, err_handle))

        codes = {}
        try:
            for label, process, out_handle, err_handle in started:
                try:
                    codes[label] = process.wait(timeout=harness.WAITS["unpack"].seconds)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
                    self.fail(f"the {label} cold start exceeded its unpack budget and was killed")
        finally:
            for _, _, out_handle, err_handle in started:
                out_handle.close()
                err_handle.close()

        self.assertEqual(codes["a"], 0, "the first of two simultaneous cold starts exited non-zero")
        self.assertEqual(codes["b"], 0, "the second of two simultaneous cold starts exited non-zero")
        # One process wins the lock and stages; the other finds the entry already warm.
        combined_err = (self.case_dir / "a.err").read_text(errors="replace") \
            + (self.case_dir / "b.err").read_text(errors="replace")
        self.assertEqual(
            len(UNPACKING_PATTERN.findall(combined_err)), 1,
            "two simultaneous cold starts did not print exactly one unpacking line between them",
        )
        self.assertEqual(entry_count(cache), 1, "two simultaneous cold starts did not leave exactly one cache entry")
        self.assertEqual(
            list(cache.glob("*.staging-*")), [],
            "a staging directory survived two simultaneous cold starts",
        )


class InstalledSiteLauncherCases(harness.ConformanceCase):
    """Cases 4-6: dr, the serving process and SIGINT, against one installed site.

    These share the run cache, like every other suite case, rather than a cache of their own.
    """

    PLATFORMS = (harness.LINUX,)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)
        cls.data = cls.class_dir / "data"
        install_dir = cls.class_dir / "install"
        install_dir.mkdir()
        site = harness.Site(harness.BINARY, install_dir)
        site.start(cls.data, "--admin-user", "launcher-admin", "--admin-password", "Launcher.test.password.2026")
        site.stop()

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_dr_on_an_unknown_command_refuses(self):
        result = harness.run_dr(harness.BINARY, self.case_dir, self.data, "this-command-does-not-exist")
        self.assertNotEqual(result.returncode, 0, "dr with an unknown Drush command exited zero")

    def test_serving_process_and_sigint(self):
        site = harness.Site(harness.BINARY, self.case_dir)
        site.start(self.data)
        try:
            active_key = (Path(os.environ["DRUPACK_CACHE_DIR"]) / "active").read_text().strip()
            expected_prefix = f"{os.environ['DRUPACK_CACHE_DIR']}/{active_key}/drupack"
            args = subprocess.run(
                ["ps", "-o", "args=", "-p", str(site.process.pid)],
                capture_output=True, text=True, timeout=harness.WAITS["probe"].seconds,
            ).stdout.strip()
            # runtime/launch.php re-execs through DRUPACK_RUNTIME_BINARY, so the served
            # process names the cache entry's drupack, never the path this test invoked.
            self.assertTrue(
                args.startswith(expected_prefix), "the serving process does not run the cache entry executable"
            )
            # The server waits for its own first response, so a start leaves no helper behind.
            children = subprocess.run(
                ["pgrep", "-c", "-P", str(site.process.pid)],
                capture_output=True, text=True, timeout=harness.WAITS["probe"].seconds,
            ).stdout.strip()
            self.assertEqual(int(children or "0"), 0, "the serving process spawned a child")

            port = site.port
            os.kill(site.process.pid, signal.SIGINT)
            deadline = time.monotonic() + harness.WAITS["stop"].seconds
            while time.monotonic() < deadline and site.process.poll() is None:
                time.sleep(0.25)
            self.assertIsNotNone(
                site.process.poll(),
                f"SIGINT did not stop the server within {harness.WAITS['stop'].seconds}s",
            )
            site.process.wait()
            # HTTPError is a URLError subclass but means the port answered, with an error
            # status: curl with no -f, what the old script ran, called that a live port too,
            # so it must fail the case rather than pass as a closed one.
            try:
                urlopen(f"http://localhost:{port}/", timeout=harness.WAITS["port_closed"].seconds)
            except HTTPError as error:
                self.fail(f"the port still answers (HTTP {error.code}) after SIGINT stopped the server")
            except (URLError, ConnectionError, TimeoutError):
                pass
            else:
                self.fail("the port still answers after SIGINT stopped the server")
        finally:
            site.stop()


class FixtureCacheCases(harness.ConformanceCase):
    """Cases 7, 8, 10 and 11, against fixture launchers the harness packs from a Go stub,
    so none of them needs the 400 MB production executable.
    """

    PLATFORMS = (harness.LINUX,)
    TOOLS = ("go",)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_drupack_cache_dir_takes_precedence_over_default_root(self):
        fixture = harness.pack_fixture(ENTRY, "7.0.0", self.case_dir / "fixture")
        cache = harness.reserved_dir(self.case_dir / "cache")
        xdg = harness.fresh_dir(self.case_dir / "xdg")
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(cache), XDG_CACHE_HOME=str(xdg))
        code, _, err = run(self.case_dir, fixture, "run", "--help", env=env)
        self.assertEqual(code, 0, f"the fixture start exited non-zero: inspect {err}")
        self.assertEqual(entry_count(cache), 1, "DRUPACK_CACHE_DIR did not receive the unpacked runtime")
        self.assertFalse(
            (xdg / "Drupack").exists(),
            "the default cache root gained an entry although DRUPACK_CACHE_DIR was set",
        )

    def test_a_home_that_refuses_writes_falls_back_to_tmpdir(self):
        if os.getuid() == 0:
            self.skipTest("running as root, which ignores directory mode bits")
        fixture = harness.pack_fixture(ENTRY, "8.0.0", self.case_dir / "fixture")
        home = harness.fresh_dir(self.case_dir / "home")
        tmp = harness.fresh_dir(self.case_dir / "tmp")
        home.chmod(0o500)
        # DRUPACK_CACHE_DIR is exported for the whole run; this case is about Root()'s own
        # fallback, so it must run with neither that nor XDG_CACHE_HOME set.
        env = {key: value for key, value in os.environ.items()
               if key not in ("DRUPACK_CACHE_DIR", "XDG_CACHE_HOME")}
        env["HOME"] = str(home)
        env["TMPDIR"] = str(tmp)
        try:
            code, _, err = run(self.case_dir, fixture, "run", "--help", env=env)
        finally:
            home.chmod(0o700)
        self.assertEqual(code, 0, f"the launcher did not exit 0 when HOME refuses writes: inspect {err}")
        # The temporary directory is shared, so the launcher names its root per uid.
        runtime_root = tmp / f"Drupack-{os.getuid()}" / "runtime"
        self.assertEqual(
            entry_count(runtime_root), 1, "the runtime did not land under TMPDIR when HOME refuses writes"
        )

    def test_two_versions_leave_one_active_entry(self):
        fixture_a = harness.pack_fixture(ENTRY, "10.0.0", self.case_dir / "fixture-v1")
        fixture_b = harness.pack_fixture(ENTRY, "10.0.1", self.case_dir / "fixture-v2")
        cache = harness.reserved_dir(self.case_dir / "cache")
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(cache))

        code, _, err = run(self.case_dir, fixture_a, "first", "--help", env=env)
        self.assertEqual(code, 0, f"the first version exited non-zero: inspect {err}")
        key_a = (cache / "active").read_text().strip()
        self.assertTrue((cache / key_a).is_dir(), "the first version did not stage a cache entry")

        code, _, err = run(self.case_dir, fixture_b, "second", "--help", env=env)
        self.assertEqual(code, 0, f"the second version exited non-zero: inspect {err}")
        key_b = (cache / "active").read_text().strip()
        self.assertNotEqual(key_b, key_a, "the second version did not become active")
        self.assertFalse(
            (cache / key_a).exists(), "the first version's entry survived a second version's start"
        )
        self.assertEqual(entry_count(cache), 1, "two versions did not leave exactly one cache entry")

    def test_a_corrupted_payload_falls_back_to_the_cached_version(self):
        fixture = harness.pack_fixture(ENTRY, "11.0.0", self.case_dir / "fixture-v1")
        cache = harness.reserved_dir(self.case_dir / "cache")
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(cache))
        code, _, err = run(self.case_dir, fixture, "install", "--help", env=env)
        self.assertEqual(code, 0, f"installing version one exited non-zero: inspect {err}")
        self.assertEqual(entry_count(cache), 1, "installing version one did not leave one cache entry")

        broken = harness.pack_corrupted_fixture(ENTRY, "11.0.1", self.case_dir / "fixture-v2")
        code, out, err = run(self.case_dir, broken, "fallback", "--help", env=env)
        self.assertEqual(code, 0, "a corrupted payload with a valid cached version did not exit 0")
        self.assertIn(
            "fixture 11.0.0", out.read_text(errors="replace"), "the fallback did not run the cached version"
        )
        self.assertRegex(
            err.read_text(errors="replace"), FALLBACK_PATTERN, "the fallback warning was not written to standard error"
        )
        self.assertEqual(entry_count(cache), 1, "the fallback start changed the number of cache entries")


class CacheRootFull(harness.ConformanceCase):
    """Case 9: a cache root with no room for the runtime, run as a 4 MB tmpfs in a container."""

    PLATFORMS = (harness.LINUX,)
    TOOLS = ("docker",)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def test_a_cache_root_with_no_room_refuses(self):
        data = harness.fresh_dir(self.class_dir / "data")
        out_path = self.class_dir / "run.out"
        err_path = self.class_dir / "run.err"
        container_name = f"drupack-cache-full-{os.getpid()}"
        command = [
            "docker", "run", "--rm", "--tmpfs", "/cache:size=4m",
            "--name", container_name,
            "-e", "DRUPACK_CACHE_DIR=/cache",
            "--workdir", "/site",
            "--mount", f"type=bind,src={harness.BINARY},dst=/artifact/drupack,readonly",
            "--mount", f"type=bind,src={data},dst=/site",
            DEBIAN_IMAGE, "/artifact/drupack", "--help",
        ]
        with open(out_path, "wb") as out_handle, open(err_path, "wb") as err_handle:
            try:
                result = subprocess.run(
                    command, stdout=out_handle, stderr=err_handle,
                    timeout=harness.WAITS["cache_full"].seconds,
                )
            except subprocess.TimeoutExpired:
                # SIGKILL above lands on the docker client, not the daemon: the container
                # keeps running past the budget, holding its mounts, unless removed by name.
                try:
                    subprocess.run(
                        ["docker", "rm", "-f", container_name],
                        capture_output=True, timeout=harness.WAITS["cache_full_kill"].seconds,
                    )
                except subprocess.TimeoutExpired:
                    pass
                self.fail(
                    f"the cache-full container exceeded its {harness.WAITS['cache_full'].seconds}s "
                    f"budget and was killed: inspect {err_path}"
                )
        self.assertNotEqual(
            result.returncode, 0, f"the launcher exited 0 with no room to unpack the runtime: inspect {err_path}"
        )
        self.assertIn(
            "/cache", err_path.read_text(errors="replace"), "the failure message did not name the cache path"
        )
        self.assertFalse(
            (data / "data").exists(), "a Site data directory was written despite the unpacking failure"
        )
