"""runtime/builder/check-downloads.php, run on downloads folders it builds. Run with:

    python3 -m unittest discover -s tests/conformance -p test_check_downloads.py
"""

import hashlib
import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

CHECKER = Path(__file__).resolve().parents[2] / "runtime" / "builder" / "check-downloads.php"


def archive(file, version="v1"):
    return {"source": {"cache_type": "archive", "filename": file, "version": version}, "binary": []}


class CheckDownloads(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        root = Path(scratch.name)
        # The checker reads the record beside itself.
        shutil.copy(CHECKER, root)
        self.checker = root / CHECKER.name
        self.downloads = root / "downloads"
        self.downloads.mkdir()
        (self.downloads / "zlib.tar.gz").write_bytes(b"zlib")
        (root / "library-versions.txt").write_text(f"zlib v1.4 zlib.tar.gz {hashlib.sha256(b'zlib').hexdigest()}\n")

    def check(self, cache, *flags):
        (self.downloads / ".cache.json").write_text(json.dumps(cache))
        return subprocess.run(["php", str(self.checker), *flags, str(self.downloads)], capture_output=True, text=True)

    def test_a_recorded_file_passes(self):
        self.assertEqual(self.check({"zlib": archive("zlib.tar.gz")}).returncode, 0)

    def test_exempt_sources_pass_beside_a_recorded_one(self):
        cache = {"zlib": archive("zlib.tar.gz") | {"binary": {"linux-x86_64": {"cache_type": "file", "filename": "z.bin"}}},
                 "ext-yaml": {"source": {"cache_type": "local", "dirname": "/pins/ext-yaml"}, "binary": []},
                 "go-xcaddy": {"source": None, "binary": {"linux-x86_64": {"cache_type": "archive", "filename": "go.tgz"}}},
                 "llvm-compiler-rt": archive("compiler-rt.tar.xz"), "re2c": archive("re2c.tar.xz")}
        self.assertEqual(self.check(cache).returncode, 0)

    def test_a_source_with_no_line_fails(self):
        self.assertEqual(self.check({"zlib": archive("zlib.tar.gz"), "xz": archive("xz.tar.xz")}).returncode, 1)

    def test_a_report_names_each_difference_and_passes(self):
        (self.downloads / "zlib.tar.gz").write_bytes(b"zlib again")
        reported = self.check({"zlib": archive("zlib.tar.gz"), "xz": archive("xz.tar.xz")}, "--report")
        self.assertEqual(reported.returncode, 0)
        self.assertEqual(reported.stderr.splitlines(), ["zlib: zlib.tar.gz differs from the SHA-256 library-versions.txt records",
                                                        "xz: no line in library-versions.txt"])

    def test_other_bytes_another_file_or_a_missing_file_fail_and_each_is_named(self):
        (self.downloads / "zlib.tar.gz").write_bytes(b"zlib again")
        self.assertIn("zlib: zlib.tar.gz differs", self.check({"zlib": archive("zlib.tar.gz")}).stderr)
        (self.downloads / "zlib.tar.gz").unlink()
        self.assertEqual(self.check({"zlib": archive("zlib.tar.gz")}).returncode, 1)
        result = self.check({"zlib": archive("zlib-1.4.tar.gz"), "xz": archive("xz.tar.xz")})
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stderr.splitlines(), ['zlib: static-php-cli holds "zlib-1.4.tar.gz", the record names zlib.tar.gz',
                                                      "xz: no line in library-versions.txt"])

    def test_a_git_source_fails(self):
        cache = {"zlib": {"source": {"cache_type": "git", "dirname": "zlib", "version": None}, "binary": []}}
        self.assertEqual(self.check(cache).returncode, 1)

    def test_a_cache_with_no_recorded_source_fails(self):
        for cache in ({}, [], "zlib", {"ext-yaml": {"source": {"cache_type": "local", "dirname": "/pins"}, "binary": []}}):
            with self.subTest(cache):
                self.assertEqual(self.check(cache).returncode, 1)
        (self.downloads / ".cache.json").unlink()
        self.assertEqual(subprocess.run(["php", str(self.checker), str(self.downloads)], capture_output=True).returncode, 1)
