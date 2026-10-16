"""build/native-pack.sh, run against a release a local HTTP server serves. The runtime is a
stub script, so no case needs a compiled PHP. Run with:

    python3 -m unittest discover -s tests/conformance -p test_native_pack.py
"""

import functools
import hashlib
import http.server
import json
import shutil
import subprocess
import tempfile
import threading
import unittest
from pathlib import Path

ENGINE = Path(__file__).resolve().parents[2]
SCRIPT = ENGINE / "build" / "native-pack.sh"
VERSION = "1.2.3"
TARGET = "linux-amd64"
FILE = f"runtime-{VERSION}-{TARGET}.tar.zst"


class QuietHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, format, *args):
        pass


class NativePack(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.scratch = Path(scratch.name)
        self.release = self.scratch / "release" / VERSION
        self.release.mkdir(parents=True)
        server = http.server.ThreadingHTTPServer(
            ("127.0.0.1", 0), functools.partial(QuietHandler, directory=str(self.scratch / "release")))
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.base_url = f"http://127.0.0.1:{server.server_address[1]}/{VERSION}"

        runtime = self.scratch / "runtime"
        runtime.mkdir()
        (runtime / "drupack").write_bytes(b"#!/bin/sh\necho stub runtime\n")
        subprocess.run(["tar", "-I", "zstd -19 -T0", "-C", runtime, "-cf", self.release / FILE, "."], check=True)
        self.publish_checksum(hashlib.sha256((self.release / FILE).read_bytes()).hexdigest())

        self.payload = self.scratch / "payload"
        self.payload.mkdir()
        (self.payload / "app-payload.tar").write_bytes(b"an application")
        (self.payload / "app_checksum.txt").write_text("0" * 64 + "\n")
        (self.payload / "site.json").write_text(json.dumps({"name": "acme"}))
        self.output = self.scratch / "out" / "acme-linux-amd64"

    def publish_checksum(self, sha256):
        (self.release / "checksums.txt").write_text(f"{sha256}  {FILE}\n{'1' * 64}  other-file\n")

    def pack(self, target=TARGET):
        return subprocess.run(
            ["bash", SCRIPT, "--base-url", self.base_url, "--target", target, "--payload", self.payload,
             "--output", self.output, "--site-version", "2026.10.1"],
            capture_output=True, text=True)

    @unittest.skipUnless(shutil.which("go"), "packing builds the launcher with Go")
    def test_a_verified_runtime_file_packs_the_payload(self):
        result = self.pack()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(self.output.stat().st_size > 0)
        self.assertEqual(self.output.read_bytes()[:4], b"\x7fELF")

    @unittest.skipUnless(shutil.which("go"), "packing builds the launcher with Go")
    def test_the_launcher_takes_the_architecture_the_target_names(self):
        # ELF stores the machine at byte 18: 62 is x86-64, 183 is AArch64.
        lines = []
        for target in ("linux-amd64", "linux-arm64"):
            file = f"runtime-{VERSION}-{target}.tar.zst"
            if target != TARGET:
                shutil.copyfile(self.release / FILE, self.release / file)
            lines.append(f"{hashlib.sha256((self.release / file).read_bytes()).hexdigest()}  {file}\n")
        (self.release / "checksums.txt").write_text("".join(lines))
        for target, machine in (("linux-amd64", 62), ("linux-arm64", 183)):
            with self.subTest(target=target):
                result = self.pack(target)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(int.from_bytes(self.output.read_bytes()[18:20], "little"), machine)

    def test_an_altered_byte_stops_the_build_naming_the_file(self):
        content = bytearray((self.release / FILE).read_bytes())
        content[-1] ^= 1
        (self.release / FILE).write_bytes(content)
        result = self.pack()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(FILE, result.stderr)
        self.assertIn("SHA-256", result.stderr)
        self.assertFalse(self.output.exists())

    def test_a_missing_runtime_file_stops_the_build_naming_the_file(self):
        (self.release / FILE).unlink()
        result = self.pack()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(f"cannot download {FILE}", result.stderr)

    def test_a_missing_checksums_file_stops_the_build_naming_the_file(self):
        (self.release / "checksums.txt").unlink()
        result = self.pack()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("cannot download checksums.txt", result.stderr)

    def test_a_checksums_file_without_the_runtime_stops_the_build_naming_the_file(self):
        (self.release / "checksums.txt").write_text(f"{'1' * 64}  other-file\n")
        result = self.pack()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(f"checksums.txt lists no {FILE}", result.stderr)


if __name__ == "__main__":
    unittest.main()
