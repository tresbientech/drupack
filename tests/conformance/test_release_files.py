"""The Release files build/release/release-files.py writes, checked on small builds and a small runtime directory.
Run with:

    python3 -m unittest discover -s tests/conformance -p test_release_files.py
"""

import hashlib
import json
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


if __name__ == "__main__":
    unittest.main()
