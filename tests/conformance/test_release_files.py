"""The Release files build/release/release-files.py writes, checked on small builds and a small runtime directory.
Run with:

    python3 -m unittest discover -s tests/conformance -p test_release_files.py
"""

import hashlib
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

RELEASE_FILES = Path(__file__).resolve().parents[2] / "build" / "release" / "release-files.py"
BASE_URL = "https://example.test/1.2.3"


class RuntimeReleaseFiles(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.scratch = Path(scratch.name)
        self.build = self.scratch / "drupack-macos-arm64"
        self.build.write_bytes(b"an engine build")
        self.output = self.scratch / "release"

    def runtime(self, name):
        directory = self.scratch / name
        (directory / "ext").mkdir(parents=True)
        (directory / "drupack").write_bytes(b"the entry executable")
        (directory / "ext" / "php_gd.dll").write_bytes(b"an extension")
        return directory

    def write(self, runtime):
        return subprocess.run([sys.executable, RELEASE_FILES, "--output", self.output, "--version", "1.2.3",
                               "--base-url", BASE_URL, "--commit", "release-case", "--runtime", runtime, self.build],
                              capture_output=True, text=True)

    def test_a_runtime_directory_becomes_an_archive_the_release_lists(self):
        result = self.write(self.runtime("runtime-macos-arm64"))
        self.assertEqual(result.returncode, 0, result.stderr)
        archive = self.output / "runtime-1.2.3-macos-arm64.tar.zst"
        sha256 = hashlib.sha256(archive.read_bytes()).hexdigest()
        self.assertIn(f"{sha256}  {archive.name}\n", (self.output / "checksums.txt").read_text())
        assets = {asset["asset"]: asset for asset in json.loads((self.output / "release.json").read_text())["assets"]}
        self.assertEqual(assets[archive.name], {
            "name": "runtime", "asset": archive.name, "target": "macos-arm64",
            "url": f"{BASE_URL}/{archive.name}", "sha256": sha256, "size": archive.stat().st_size})
        unpacked = self.scratch / "unpacked"
        unpacked.mkdir()
        subprocess.run(["tar", "--zstd", "-C", unpacked, "-xf", archive], check=True)
        self.assertEqual((unpacked / "drupack").read_bytes(), b"the entry executable")
        self.assertEqual((unpacked / "ext" / "php_gd.dll").read_bytes(), b"an extension")

    def test_no_install_script_names_a_runtime(self):
        self.write(self.runtime("runtime-macos-arm64"))
        self.assertEqual(sorted(path.name for path in self.output.iterdir()),
                         ["checksums.txt", "drupack-1.2.3-macos-arm64", "install-drupack.sh", "release.json",
                          "runtime-1.2.3-macos-arm64.tar.zst"])
        self.assertNotIn("runtime", (self.output / "install-drupack.sh").read_text())

    def test_the_step_refuses_a_runtime_directory_named_for_no_published_target(self):
        result = self.write(self.runtime("runtime-linux-amd64"))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("runtime-linux-amd64 is not named runtime-TARGET", result.stderr)


class SiteReleaseFiles(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.scratch = Path(scratch.name)
        self.output = self.scratch / "release"

    def build(self, name):
        path = self.scratch / name
        path.write_bytes(f"the {name} build".encode())
        return path

    def write(self, version, *builds):
        return subprocess.run([sys.executable, RELEASE_FILES, "--output", self.output, "--version", version,
                               "--base-url", BASE_URL, "--commit", "release-case", *builds],
                              capture_output=True, text=True)

    def test_the_release_names_each_build_with_its_version(self):
        result = self.write("1.2.3", self.build("demo-linux-amd64"), self.build("demo-linux-amd64-musl"),
                            self.build("demo-windows-amd64.exe"))
        self.assertEqual(result.returncode, 0, result.stderr)
        executables = ["demo-1.2.3-linux-amd64", "demo-1.2.3-linux-amd64-musl", "demo-1.2.3-windows-amd64.exe"]
        self.assertEqual(sorted(path.name for path in self.output.iterdir()),
                         sorted([*executables, "checksums.txt", "install-demo.sh", "install-demo.ps1", "release.json"]))
        checksums = (self.output / "checksums.txt").read_text()
        for executable in executables:
            sha256 = hashlib.sha256((self.output / executable).read_bytes()).hexdigest()
            self.assertIn(f"{sha256}  {executable}\n", checksums)
        assets = {asset["asset"]: asset for asset in json.loads((self.output / "release.json").read_text())["assets"]}
        self.assertEqual(assets["demo-1.2.3-linux-amd64-musl"]["target"], "linux-amd64-musl")

    def test_the_step_refuses_a_file_named_for_no_target(self):
        stray = self.scratch / "site.json"
        stray.write_text("{}")
        result = self.write("1.2.3", stray)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("site.json is not named NAME-TARGET", result.stderr)

    def test_the_step_refuses_a_version_holding_a_path_separator(self):
        result = self.write("release/1.0", self.build("demo-linux-amd64"))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("'release/1.0' holds a character", result.stderr)


# Answers the script's download from the release directory, and logs the request.
CURL = """#!/bin/sh
echo "$4" >>"$CURL_LOG"
cp "$RELEASE/${4##*/}" "$3"
"""
UNAME = """#!/bin/sh
case $1 in -s) echo Linux ;; -m) echo x86_64 ;; esac
"""


@unittest.skipIf(os.name == "nt", "the shell script runs on Linux and macOS")
class InstallScriptRun(unittest.TestCase):
    """install-demo.sh on a stubbed Linux amd64 host, picking a build through DRUPACK_LIBC."""

    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.scratch = Path(scratch.name)
        self.release = self.scratch / "release"
        self.project = self.scratch / "project"
        self.project.mkdir()
        self.installed = self.project / "demo"
        self.log = self.scratch / "curl.log"
        self.tools = self.scratch / "tools"
        self.tools.mkdir()
        for name, text in (("curl", CURL), ("uname", UNAME)):
            (self.tools / name).write_text(text)
            (self.tools / name).chmod(0o755)
        builds = []
        for suffix in ("", "-musl"):
            build = self.scratch / f"demo-linux-amd64{suffix}"
            build.write_bytes(f"the linux-amd64{suffix} build".encode())
            builds.append(build)
        subprocess.run([sys.executable, RELEASE_FILES, "--output", self.release, "--version", "1.2.3",
                        "--base-url", BASE_URL, "--commit", "release-case", *builds], check=True)
        self.glibc = b"the linux-amd64 build"
        self.musl = b"the linux-amd64-musl build"

    def install(self, libc="glibc"):
        env = dict(os.environ, PATH=f"{self.tools}{os.pathsep}{os.environ['PATH']}", DRUPACK_LIBC=libc,
                   RELEASE=str(self.release), CURL_LOG=str(self.log))
        result = subprocess.run(["sh", self.release / "install-demo.sh"], cwd=self.project, env=env,
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout

    def place(self, contents, mode=0o755):
        self.installed.write_bytes(contents)
        self.installed.chmod(mode)

    def assert_replaced(self, contents):
        self.assertTrue(self.log.exists(), "the script downloaded nothing")
        self.assertFalse(self.installed.is_symlink())
        self.assertEqual(self.installed.read_bytes(), contents)
        self.assertEqual(self.installed.stat().st_mode & 0o777, 0o755)

    def test_a_matching_executable_is_left_alone_without_a_download(self):
        self.place(self.glibc)
        before = self.installed.stat()
        output = self.install()
        self.assertFalse(self.log.exists())
        self.assertEqual((self.installed.stat().st_ino, self.installed.stat().st_mtime_ns),
                         (before.st_ino, before.st_mtime_ns))
        self.assertIn("demo 1.2.3 is already in", output)
        self.assertTrue(output.endswith("\n\n    ./demo\n"), output)
        self.assertEqual(sorted(path.name for path in self.project.iterdir()), ["demo"])

    def test_a_link_to_a_matching_executable_stays_a_link(self):
        target = self.scratch / "kept"
        target.write_bytes(self.glibc)
        target.chmod(0o755)
        self.installed.symlink_to(target)
        self.install()
        self.assertFalse(self.log.exists())
        self.assertTrue(self.installed.is_symlink())

    def test_a_link_to_another_build_gives_way_and_its_target_stays(self):
        target = self.scratch / "kept"
        target.write_bytes(self.musl)
        target.chmod(0o755)
        self.installed.symlink_to(target)
        self.install()
        self.assert_replaced(self.glibc)
        self.assertEqual(target.read_bytes(), self.musl)

    def test_a_matching_file_that_cannot_run_is_replaced(self):
        self.place(self.glibc, 0o644)
        self.install()
        self.assert_replaced(self.glibc)

    def test_the_other_c_library_build_is_replaced(self):
        self.place(self.glibc)
        self.install("musl")
        self.assert_replaced(self.musl)

    def test_a_file_from_another_platform_is_replaced(self):
        self.place(b"a macOS build")
        self.install()
        self.assert_replaced(self.glibc)


if __name__ == "__main__":
    unittest.main()
