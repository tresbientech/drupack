"""A release's install script puts the host's build into the working directory.

build/release-files.py writes a release from the executable under test. A local
HTTP server serves it, and the case runs the documented one-liner against it:
curl piped to sh on Linux and macOS, irm piped to iex in PowerShell on Windows.
"""

import functools
import hashlib
import http.server
import platform
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
        cls.case_dir = harness.fresh_dir(harness.RESULTS / cls.__name__)
        cls.name = harness.SITE["name"]
        cls.windows = harness.current_platform() == harness.WINDOWS
        cls.extension = ".exe" if cls.windows else ""
        cls.target = f"{harness.current_platform()}-{ARCHITECTURES[platform.machine().lower()]}"
        cls.releases = harness.fresh_dir(cls.case_dir / "releases")
        server = http.server.ThreadingHTTPServer(
            ("127.0.0.1", 0), functools.partial(QuietHandler, directory=str(cls.releases)))
        threading.Thread(target=server.serve_forever, daemon=True).start()
        cls.addClassCleanup(server.server_close)
        cls.addClassCleanup(server.shutdown)
        cls.url = f"http://127.0.0.1:{server.server_address[1]}"

    def release(self, name, *builds):
        """Writes a release of builds under name, and returns its directory."""
        output = self.releases / name
        result = harness.run([sys.executable, str(RELEASE_FILES), "--output", str(output), "--version", VERSION,
                              "--base-url", f"{self.url}/{name}", "--commit", "install-case", *map(str, builds)],
                             capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds)
        self.assertEqual(result.returncode, 0, result.stderr)
        return output

    def host_build(self):
        build = harness.fresh_dir(self.case_dir / "host-build") / f"{self.name}-{self.target}{self.extension}"
        shutil.copyfile(harness.BINARY, build)
        return build

    def install(self, release):
        """Runs the one-liner for this host against release in an empty directory, and returns
        the result and the directory."""
        directory = harness.fresh_dir(self.case_dir / f"install-{release.name}")
        if self.windows:
            # Windows PowerShell wraps an uncaught error at the console width, even when redirected.
            command = ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command",
                       f"try {{ irm {self.url}/{release.name}/install-{self.name}.ps1 | iex }} "
                       "catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }"]
        else:
            command = ["sh", "-c", 'curl -fsSL "$1" | sh', "sh", f"{self.url}/{release.name}/install-{self.name}.sh"]
        result = harness.run(command, cwd=directory, capture_output=True, text=True,
                             timeout=harness.WAITS["drush"].seconds)
        return result, directory

    def test_the_release_names_each_build_with_its_version(self):
        release = self.release("names", self.host_build())
        executable = f"{self.name}-{VERSION}-{self.target}{self.extension}"
        self.assertEqual(sorted(path.name for path in release.iterdir()), sorted(
            [executable, "checksums.txt", "release.json", f"install-{self.name}.ps1", f"install-{self.name}.sh"]))
        self.assertIn(f"{sha256(release / executable)}  {executable}\n", (release / "checksums.txt").read_text())

    def test_the_step_refuses_a_file_named_for_no_target(self):
        stray = self.case_dir / "site.json"
        stray.write_text("{}")
        result = harness.run([sys.executable, str(RELEASE_FILES), "--output", str(self.case_dir / "stray"),
                              "--version", VERSION, "--base-url", self.url, "--commit", "install-case", str(stray)],
                             capture_output=True, text=True, timeout=harness.WAITS["php_cli"].seconds)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("site.json is not named NAME-TARGET", result.stderr)

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
        build = harness.fresh_dir(self.case_dir / "other-build") / f"{self.name}-{other}"
        build.write_bytes(b"another platform's build")
        result, directory = self.install(self.release("elsewhere", build))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(f"has no {self.target} build. It has: {other}.", result.stderr)
        self.assertEqual(list(directory.iterdir()), [])
