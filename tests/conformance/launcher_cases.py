"""The launcher's own cache: cold starts, fixtures, and the cache states they build.

Ported from tests/launcher.sh cases 1-11 (case 0 moved in phase 2). The Go tests in
packaging/launcher cover the cache-root fallbacks and the concurrent cold start, which
need no built executable. Most classes stay Linux only; ColdWarmStart also runs on
Windows, and WindowsLauncherCases holds the cache states only Windows can produce.
"""

import ctypes
import json
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

# The line app.go writes to standard error, once per unpacked release.
APPLICATION_PATTERN = re.compile(
    r"^Unpacking the Drupack application\. This happens once for each release\.$", re.MULTILINE
)

# One progress report, which names how far the unpacking has read and the whole payload.
PROGRESS_PATTERN = re.compile(r"^  (\d+) of (\d+) MB$", re.MULTILINE)

# The real launcher's entry file inside a cache directory: the Linux distribution's own
# multicall binary, or FrankenPHP on Windows, matching what each platform's build packs.
ENTRY = "frankenphp.exe" if harness.current_platform() == harness.WINDOWS else "drupack"

# debian, pinned the way network_cases.py pins its own copy of the same image:
# docker buildx imagetools inspect debian --format '{{.Manifest.Digest}}'
DEBIAN_IMAGE = "debian@sha256:88200866dfff7ea7f5cbcb6ec7c8a701889efe6fe859fe64d6990e4b07ea4171"


def entry_count(cache_root):
    """Count cache_root's immediate subdirectories: one cache entry each."""
    return sum(1 for path in cache_root.iterdir() if path.is_dir())


def run(case_dir, executable, name, *args, env=None):
    """Run executable, capturing stdout and stderr into case_dir/name.{out,err} like the
    old script's own redirections, so a failure points straight at the same two files.
    harness.run() pins standard input closed, since executable is sometimes the real
    product binary (ColdWarmStart's own --help calls).
    """
    out_path = case_dir / f"{name}.out"
    err_path = case_dir / f"{name}.err"
    with open(out_path, "wb") as out_handle, open(err_path, "wb") as err_handle:
        result = harness.run(
            [str(executable), *args], cwd=case_dir, stdout=out_handle, stderr=err_handle,
            env=env, timeout=harness.WAITS["unpack"].seconds,
        )
    return result.returncode, out_path, err_path


class ColdWarmStart(harness.ConformanceCase):
    """Case 1 (cold start) and case 2 (warm start), against one private cache."""

    PLATFORMS = (harness.LINUX, harness.WINDOWS)

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
        self.assertTrue((self.cache / key / ENTRY).is_file(), f"the cache entry has no {ENTRY} executable")
        self.assertEqual(
            (self.cache / "active").read_text().strip(), key, "a cold start did not activate the runtime"
        )

        code, out, err = run(self.case_dir, harness.BINARY, "warm", "--help", env=self.env)
        self.assertEqual(code, 0, f"a warm start exited non-zero: inspect {err}")
        self.assertEqual(
            len(UNPACKING_PATTERN.findall(err.read_text(errors="replace"))), 0,
            "a warm start printed an unpacking line",
        )
        self.assertEqual(entry_count(self.cache), 1, "a warm start changed the number of cache entries")
        self.assertTrue((self.cache / key).is_dir(), "a warm start replaced the cache entry")
        self.assertEqual(
            (self.cache / "active").read_text().strip(), key, "a warm start changed the active entry"
        )

    def test_cold_start_reports_unpacking_progress(self):
        code, out, err = run(self.case_dir, harness.BINARY, "cold", "--help", env=self.env)
        self.assertEqual(code, 0, f"a cold start exited non-zero: inspect {err}")
        cold_err = err.read_text(errors="replace")
        self.assertEqual(
            len(APPLICATION_PATTERN.findall(cold_err)), 1,
            "a cold start did not print exactly one application unpacking line",
        )
        reports = PROGRESS_PATTERN.findall(cold_err)
        self.assertGreaterEqual(
            len(reports), 2, f"a cold start reported progress {len(reports)} times: inspect {err}"
        )
        totals = {total for _, total in reports}
        self.assertEqual(len(totals), 1, f"the reports name different totals: {totals}")
        read, total = reports[-1]
        self.assertEqual(read, total, "the closing report does not name the whole payload")

        code, out, err = run(self.case_dir, harness.BINARY, "warm", "--help", env=self.env)
        self.assertEqual(code, 0, f"a warm start exited non-zero: inspect {err}")
        self.assertEqual(
            len(PROGRESS_PATTERN.findall(err.read_text(errors="replace"))), 0,
            "a warm start reported unpacking progress",
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
        install_dir.mkdir(exist_ok=True)
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


def _active_entry(cache):
    """The cache entry cache's 'active' pointer file names, as an absolute path."""
    return cache / (cache / "active").read_text().strip()


def _quoted_args(*args):
    """Match Go's %q rendering of a []string, whose only special character in these fixtures
    is a Windows path's backslash, which %q escapes by doubling.
    """
    return " ".join('"' + arg.replace("\\", "\\\\") + '"' for arg in args)


# Win32 CreateFileW constants for _open_locked: GENERIC_READ and OPEN_EXISTING match an
# already-running process reading its own executable; the share mode varies per case.
_GENERIC_READ = 0x80000000
_OPEN_EXISTING = 3
_FILE_SHARE_READ = 0x00000001
_FILE_SHARE_DELETE = 0x00000004
_INVALID_HANDLE_VALUE = ctypes.c_void_p(-1).value

if harness.current_platform() == harness.WINDOWS:
    # ctypes.windll's default restype truncates a 64-bit HANDLE to a 32-bit int; binding
    # through this WinDLL instance instead carries CreateFileW and CloseHandle's real signature.
    _kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    _kernel32.CreateFileW.restype = ctypes.c_void_p
    _kernel32.CreateFileW.argtypes = [
        ctypes.c_wchar_p, ctypes.c_uint32, ctypes.c_uint32, ctypes.c_void_p,
        ctypes.c_uint32, ctypes.c_uint32, ctypes.c_void_p,
    ]
    _kernel32.CloseHandle.argtypes = [ctypes.c_void_p]


def _open_locked(path, share):
    """Hold path open with an explicit Win32 share mode; io.open exposes no such control."""
    handle = _kernel32.CreateFileW(str(path), _GENERIC_READ, share, None, _OPEN_EXISTING, 0, None)
    if handle == _INVALID_HANDLE_VALUE:
        raise ctypes.WinError(ctypes.get_last_error())
    return handle


def _close_locked(handle):
    _kernel32.CloseHandle(handle)


class WindowsLauncherCases(harness.ConformanceCase):
    """Cache states only Windows can produce: forwarding to the runtime, two starts racing
    different releases, and a re-stage past a handle Windows' loader keeps open.
    """

    PLATFORMS = (harness.WINDOWS,)
    TOOLS = ("go",)

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.class_dir = harness.RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)

    def test_forwards_arguments_environment_and_phprc_then_restages_past_locked_handles(self):
        cache = harness.reserved_dir(self.case_dir / "cache")
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(cache), DRUPACK_TEST_VALUE="forwarded")
        fixture = harness.pack_fixture(ENTRY, "test-v1", self.case_dir / "fixture")
        data = self.case_dir / "data"

        code, out, err = run(self.case_dir, fixture, "first", "--data-dir", str(data), "alpha", "beta", env=env)
        self.assertEqual(code, 0, f"a first start exited non-zero: inspect {err}")
        entry1 = _active_entry(cache)
        expected_first = (
            f'fixture test-v1 args=[{_quoted_args("--data-dir", str(data), "alpha", "beta")}] '
            f"env=forwarded phprc={entry1}"
        )
        self.assertEqual(
            out.read_text(errors="replace"), expected_first,
            "a first start did not forward arguments, environment and PHPRC",
        )
        self.assertFalse(data.exists(), "a first start wrote Site data")
        self.assertTrue((entry1 / ENTRY).is_file(), "a first start did not extract the runtime")
        manifest = json.loads((entry1 / "manifest.json").read_text())
        self.assertEqual(manifest["version"], "test-v1", "a first start did not persist the stored manifest")

        code, out, err = run(self.case_dir, fixture, "second", "restart", env=env)
        self.assertEqual(code, 0, f"a second start exited non-zero: inspect {err}")
        expected_second = f'fixture test-v1 args=[{_quoted_args("restart")}] env=forwarded phprc={entry1}'
        self.assertEqual(
            out.read_text(errors="replace"), expected_second, "a second start did not use the same entry"
        )
        self.assertEqual(_active_entry(cache), entry1, "a second start changed the active entry")

        # A crash between writing a pending activation file and renaming it over 'active'
        # leaves a stray per-process pending file behind; a warm start touches neither.
        stray_pending = cache / "active.pending.999999"
        stray_pending.write_text("stale")
        code, out, err = run(self.case_dir, fixture, "third", "stray", env=env)
        self.assertEqual(code, 0, f"a start with a stray pending file exited non-zero: inspect {err}")
        expected_third = f'fixture test-v1 args=[{_quoted_args("stray")}] env=forwarded phprc={entry1}'
        self.assertEqual(
            out.read_text(errors="replace"), expected_third,
            "a start with a stray pending file did not use the active entry",
        )
        self.assertTrue(stray_pending.exists(), "a start removed a stray pending activation file")
        self.assertEqual(
            _active_entry(cache), entry1, "a start with a stray pending file changed the active entry"
        )

        with open(entry1 / ENTRY, "ab") as handle:
            handle.write(b"x")
        code, out, err = run(self.case_dir, fixture, "resized", "resized", env=env)
        self.assertEqual(code, 0, f"a start past a resized entry exited non-zero: inspect {err}")
        entry2 = _active_entry(cache)
        self.assertNotEqual(entry2, entry1, "a re-stage wrote over the entry that was there")
        expected_resized = f'fixture test-v1 args=[{_quoted_args("resized")}] env=forwarded phprc={entry2}'
        self.assertEqual(
            out.read_text(errors="replace"), expected_resized,
            "a changed file size did not force a working re-stage",
        )
        self.assertFalse(entry1.exists(), "a re-stage left the stale entry behind")

        # A running site holds its executable with shared read and delete access, the way
        # the Windows loader does; the re-stage must claim a name of its own instead.
        with open(entry2 / ENTRY, "ab") as handle:
            handle.write(b"y")
        held = _open_locked(entry2 / ENTRY, _FILE_SHARE_READ | _FILE_SHARE_DELETE)
        try:
            code, out, err = run(self.case_dir, fixture, "held", "held", env=env)
            self.assertEqual(code, 0, f"a start past a held entry exited non-zero: inspect {err}")
            entry3 = _active_entry(cache)
            self.assertNotEqual(entry3, entry2, "a re-stage wrote over the entry a handle held")
            expected_held = f'fixture test-v1 args=[{_quoted_args("held")}] env=forwarded phprc={entry3}'
            self.assertEqual(
                out.read_text(errors="replace"), expected_held, "a re-stage with an open file did not run"
            )
        finally:
            _close_locked(held)

        # An exclusive handle shares nothing at all, blocking both a rename and a delete;
        # the start still serves, since it never touches the entry the handle holds.
        with open(entry3 / ENTRY, "ab") as handle:
            handle.write(b"z")
        exclusive = _open_locked(entry3 / ENTRY, 0)
        try:
            code, out, err = run(self.case_dir, fixture, "exclusive", "exclusive", env=env)
            self.assertEqual(code, 0, f"a start past an exclusive entry exited non-zero: inspect {err}")
            entry4 = _active_entry(cache)
            self.assertNotEqual(entry4, entry3, "a re-stage wrote over the entry an exclusive handle held")
            expected_exclusive = (
                f'fixture test-v1 args=[{_quoted_args("exclusive")}] env=forwarded phprc={entry4}'
            )
            self.assertEqual(
                out.read_text(errors="replace"), expected_exclusive,
                "a re-stage past an exclusive handle did not run",
            )
        finally:
            _close_locked(exclusive)

    def test_concurrent_starts_of_different_releases_each_run_their_own_runtime(self):
        cache = harness.reserved_dir(self.case_dir / "cache")
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(cache))
        fixture_c = harness.pack_fixture(ENTRY, "test-v4", self.case_dir / "fixture-c")
        fixture_d = harness.pack_fixture(ENTRY, "test-v5", self.case_dir / "fixture-d")

        started = []
        for label, fixture, arg in (("c", fixture_c, "from-c"), ("d", fixture_d, "from-d")):
            out_handle = open(self.case_dir / f"{label}.out", "wb")
            err_handle = open(self.case_dir / f"{label}.err", "wb")
            process = subprocess.Popen(
                [str(fixture), arg], cwd=self.case_dir, stdout=out_handle, stderr=err_handle, env=env,
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
                    self.fail(f"the {label} release exceeded its unpack budget and was killed")
        finally:
            for _, _, out_handle, err_handle in started:
                out_handle.close()
                err_handle.close()

        self.assertEqual(codes["c"], 0, "a release did not start alongside a concurrent different release")
        self.assertEqual(codes["d"], 0, "a release did not start alongside a concurrent different release")
        self.assertIn(
            "fixture test-v4", (self.case_dir / "c.out").read_text(errors="replace"),
            "a release did not run its own runtime",
        )
        self.assertIn(
            "fixture test-v5", (self.case_dir / "d.out").read_text(errors="replace"),
            "a release did not run its own runtime",
        )
        active = (cache / "active").read_text().strip()
        self.assertTrue(
            active.startswith("test-v4-") or active.startswith("test-v5-"),
            f"active named neither concurrent release: {active}",
        )
        self.assertTrue((cache / active / ENTRY).is_file(), "the active release directory is incomplete")

    def test_corrupted_payload_with_unreadable_stored_manifest_exits_nonzero(self):
        cache = harness.reserved_dir(self.case_dir / "cache")
        env = dict(os.environ, DRUPACK_CACHE_DIR=str(cache))
        good = harness.pack_fixture(ENTRY, "test-v6", self.case_dir / "fixture-good")
        code, _, err = run(self.case_dir, good, "install", "--help", env=env)
        self.assertEqual(code, 0, f"installing the good version exited non-zero: inspect {err}")
        good_key = (cache / "active").read_text().strip()

        # An unreadable stored manifest disqualifies the active entry as a fallback target.
        (cache / good_key / "manifest.json").write_text("{}")

        broken = harness.pack_corrupted_fixture(ENTRY, "test-v7", self.case_dir / "fixture-broken")
        code, out, err = run(self.case_dir, broken, "untrusted", "untrusted", env=env)
        self.assertNotEqual(code, 0, "a corrupted payload with an unreadable stored manifest exited zero")
        self.assertNotIn(
            "fixture", out.read_text(errors="replace"),
            "a corrupted payload with an unreadable stored manifest forwarded arguments to a runtime",
        )


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
