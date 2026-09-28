"""A release's install script puts the host's build into the working directory.

build/release-files.py writes a release from the executable under test. A local
HTTP server serves it, and the case runs the documented one-liner against it:
curl piped to sh on Linux and macOS, irm piped to iex in PowerShell on Windows.
"""

import functools
import hashlib
import http.server
import json
import os
import platform
import re
import shutil
import sys
import threading
from pathlib import Path

import harness

RELEASE_FILES = Path(__file__).resolve().parents[2] / "build" / "release-files.py"
ARCHITECTURES = {"x86_64": "amd64", "amd64": "amd64", "aarch64": "arm64", "arm64": "arm64"}
VERSION = "0.0.0-install-case"


def sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


class QuietHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, format, *args):
        pass


class InstallScript(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)
    RECIPE = False

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        harness.fresh_dir(cls.class_dir)
        cls.name = harness.SITE["name"]
        cls.windows = harness.current_platform() == harness.WINDOWS
        cls.extension = ".exe" if cls.windows else ""
        cls.target = f"{harness.current_platform()}-{ARCHITECTURES[platform.machine().lower()]}"
        cls.releases = harness.fresh_dir(cls.class_dir / "releases")
        server = http.server.ThreadingHTTPServer(
            ("127.0.0.1", 0), functools.partial(QuietHandler, directory=str(cls.releases)))
        threading.Thread(target=server.serve_forever, daemon=True).start()
        cls.addClassCleanup(server.server_close)
        cls.addClassCleanup(server.shutdown)
        cls.url = f"http://127.0.0.1:{server.server_address[1]}"
        cls.glibc_interpreter = cls.class_dir / "ld-linux.so"
        cls.glibc_interpreter.write_bytes(b"")

    def release(self, name, *builds, glibc_host=True):
        """Writes a release of builds under name, and returns its directory. Its shell script
        tests for a fixture in place of the glibc interpreter, present when glibc_host."""
        output = self.releases / name
        result = harness.run([sys.executable, str(RELEASE_FILES), "--output", str(output), "--version", VERSION,
                              "--base-url", f"{self.url}/{name}", "--commit", "install-case", *map(str, builds)],
                             capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds)
        self.assertEqual(result.returncode, 0, result.stderr)
        interpreter = self.glibc_interpreter if glibc_host else self.class_dir / "no-such-interpreter"
        script = output / f"install-{self.name}.sh"
        text, count = re.subn(r"interpreter=\S+ ;;", f"interpreter='{interpreter}' ;;", script.read_text())
        self.assertEqual(count, 2, "the script names no glibc interpreter per architecture")
        script.write_text(text)
        return output

    def linux_release(self, name, libcs=("glibc", "musl"), glibc_host=True):
        """Writes a release holding a fixture build per libc for this host's architecture."""
        if harness.current_platform() != harness.LINUX:
            self.skipTest("only a Linux release publishes a build per C library")
        directory = harness.fresh_dir(self.class_dir / f"{name}-builds")
        builds = []
        for libc in libcs:
            build = directory / f"{self.name}-{self.target}{'-musl' if libc == 'musl' else ''}"
            build.write_bytes(f"a {libc} build".encode())
            builds.append(build)
        return self.release(name, *builds, glibc_host=glibc_host)

    def host_build(self):
        build = harness.fresh_dir(self.class_dir / "host-build") / f"{self.name}-{self.target}{self.extension}"
        shutil.copyfile(harness.BINARY, build)
        return build

    def install(self, release, libc=None):
        """Runs the one-liner for this host against release in an empty directory, with
        DRUPACK_LIBC set to libc when given, and returns the result and the directory."""
        directory = harness.fresh_dir(self.class_dir / f"install-{release.name}")
        env = dict(os.environ)
        env.pop("DRUPACK_LIBC", None)
        if libc is not None:
            env["DRUPACK_LIBC"] = libc
        if self.windows:
            # Windows PowerShell wraps an uncaught error at the console width, even when redirected.
            command = ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command",
                       f"try {{ irm {self.url}/{release.name}/install-{self.name}.ps1 | iex }} "
                       "catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }"]
        else:
            command = ["sh", "-c", 'curl -fsSL "$1" | sh', "sh", f"{self.url}/{release.name}/install-{self.name}.sh"]
        result = harness.run(command, cwd=directory, capture_output=True, text=True, env=env,
                             timeout=harness.WAITS["drush"].seconds)
        return result, directory

    def test_the_release_names_each_build_with_its_version(self):
        musl = harness.fresh_dir(self.class_dir / "musl-build") / f"{self.name}-linux-amd64-musl"
        musl.write_bytes(b"a musl build")
        release = self.release("names", self.host_build(), musl)
        executables = [f"{self.name}-{VERSION}-{self.target}{self.extension}", f"{self.name}-{VERSION}-linux-amd64-musl"]
        self.assertEqual(sorted(path.name for path in release.iterdir()), sorted(
            [*executables, "checksums.txt", "release.json", f"install-{self.name}.ps1", f"install-{self.name}.sh"]))
        checksums = (release / "checksums.txt").read_text()
        assets = {asset["asset"]: asset for asset in json.loads((release / "release.json").read_text())["assets"]}
        for executable in executables:
            self.assertIn(f"{sha256(release / executable)}  {executable}\n", checksums)
        self.assertEqual(assets[executables[1]]["target"], "linux-amd64-musl")

    def test_the_step_refuses_a_file_named_for_no_target(self):
        stray = self.class_dir / "site.json"
        stray.write_text("{}")
        result = harness.run([sys.executable, str(RELEASE_FILES), "--output", str(self.class_dir / "stray"),
                              "--version", VERSION, "--base-url", self.url, "--commit", "install-case", str(stray)],
                             capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("site.json is not named NAME-TARGET", result.stderr)

    def test_the_step_refuses_a_version_holding_a_path_separator(self):
        result = harness.run([sys.executable, str(RELEASE_FILES), "--output", str(self.class_dir / "separator"),
                              "--version", "release/1.0", "--base-url", self.url, "--commit", "install-case",
                              str(self.host_build())],
                             capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("'release/1.0' holds a character", result.stderr)

    def test_the_script_writes_through_no_link_planted_in_the_working_directory(self):
        if self.windows:
            self.skipTest("a Windows link needs a privilege this case does not assume")
        release = self.release("planted", self.host_build())
        directory = harness.fresh_dir(self.class_dir / f"install-{release.name}")
        victim = self.class_dir / "victim"
        victim.write_text("left alone")
        (directory / f".{self.name}.download").symlink_to(victim)
        result = harness.run(["sh", "-c", 'curl -fsSL "$1" | sh', "sh", f"{self.url}/{release.name}/install-{self.name}.sh"],
                             cwd=directory, capture_output=True, text=True, timeout=harness.WAITS["drush"].seconds)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(victim.read_bytes(), b"left alone")
        self.assertEqual(sha256(directory / self.name), sha256(harness.BINARY))

    def test_the_script_installs_the_host_build_into_the_working_directory(self):
        result, directory = self.install(self.release("installs", self.host_build()))
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        installed = directory / f"{self.name}{self.extension}"
        self.assertEqual(sha256(installed), sha256(harness.BINARY))
        self.assertEqual([path.name for path in directory.iterdir()], [installed.name])
        self.assertIn(f"Installed {self.name} {VERSION}", result.stdout)

    def test_the_script_refuses_a_download_whose_sha256_differs(self):
        release = self.release("tampered", self.host_build())
        (release / f"{self.name}-{VERSION}-{self.target}{self.extension}").write_bytes(b"tampered")
        result, directory = self.install(release)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Nothing was installed", result.stderr)
        self.assertEqual(list(directory.iterdir()), [])

    def test_the_script_names_the_builds_of_a_release_without_one_for_the_host(self):
        other = "linux-amd64" if self.windows else "windows-amd64"
        build = harness.fresh_dir(self.class_dir / "other-build") / f"{self.name}-{other}"
        build.write_bytes(b"another platform's build")
        result, directory = self.install(self.release("elsewhere", build))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(f"has no {self.target} build. It has: {other}.", result.stderr)
        self.assertEqual(list(directory.iterdir()), [])

    def test_the_script_picks_the_glibc_build_where_the_glibc_interpreter_is(self):
        for glibc_host, libc in ((True, "glibc"), (False, "musl")):
            with self.subTest(libc=libc):
                result, directory = self.install(self.linux_release(f"pick-{libc}", glibc_host=glibc_host))
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual((directory / self.name).read_bytes(), f"a {libc} build".encode())

    def test_drupack_libc_overrides_the_pick(self):
        for glibc_host, libc in ((True, "musl"), (False, "glibc")):
            with self.subTest(libc=libc):
                result, directory = self.install(self.linux_release(f"override-{libc}", glibc_host=glibc_host), libc)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual((directory / self.name).read_bytes(), f"a {libc} build".encode())

    def test_an_unknown_drupack_libc_stops_before_any_download(self):
        result, directory = self.install(self.linux_release("unknown-libc"), "gnu")
        self.assertNotEqual(result.returncode, 0)
        for named in ("DRUPACK_LIBC=gnu", "glibc", "musl"):
            self.assertIn(named, result.stderr)
        self.assertNotIn("Downloading", result.stdout)
        self.assertEqual(list(directory.iterdir()), [])

    def test_a_release_without_the_picked_build_lists_the_builds_it_has(self):
        result, directory = self.install(self.linux_release("glibc-only", libcs=("glibc",), glibc_host=False))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(f"has no {self.target}-musl build. It has: {self.target}.", result.stderr)
        self.assertEqual(list(directory.iterdir()), [])

    def test_the_script_refuses_a_musl_download_whose_sha256_differs(self):
        release = self.linux_release("tampered-musl", glibc_host=False)
        (release / f"{self.name}-{VERSION}-{self.target}-musl").write_bytes(b"tampered")
        result, directory = self.install(release)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Nothing was installed", result.stderr)
        self.assertEqual(list(directory.iterdir()), [])
