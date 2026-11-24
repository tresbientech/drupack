"""build/release/digest.py, on folders the test writes.

    python3 -m unittest discover -s tests/conformance -p test_digest.py
"""

import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("digest", ROOT / "build" / "release" / "digest.py")
digest = importlib.util.module_from_spec(spec)
spec.loader.exec_module(digest)


class Digest(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.root = Path(scratch.name)

    def artifact(self, name, files):
        for path, content in files.items():
            (self.root / name / path).parent.mkdir(parents=True, exist_ok=True)
            (self.root / name / path).write_bytes(content)
        return self.root / name

    def test_a_file_records_as_the_folder_that_holds_it_and_a_changed_byte_or_name_changes_the_record(self):
        folder = self.artifact("drupack-linux-amd64", {"drupack": b"engine"})
        record = digest.digest(folder)
        self.assertEqual(record, digest.digest(folder / "drupack"))
        self.assertNotEqual(record, digest.digest(self.artifact("changed", {"drupack": b"other"})))
        self.assertNotEqual(record, digest.digest(self.artifact("renamed", {"drupack.exe": b"engine"})))

    def test_a_link_a_dot_file_or_a_path_with_no_file_has_no_record(self):
        with self.assertRaisesRegex(SystemExit, "no file"):
            digest.digest(self.root / "missing")
        folder = self.artifact("runtime", {"out/drupack": b"runtime"})
        (folder / "linked").symlink_to(folder / "out")
        with self.assertRaisesRegex(SystemExit, "linked"):
            digest.digest(folder)
        with self.assertRaisesRegex(SystemExit, "cache"):
            digest.digest(self.artifact("hidden", {".cache/list": b""}))

    def test_a_check_stops_on_a_changed_file_on_a_folder_with_no_record_and_on_a_missing_artifact(self):
        engine = self.artifact("drupack-linux-amd64", {"drupack": b"engine"})
        runtime = self.artifact("downloaded", {"out/drupack": b"runtime", "runtime-libraries.txt": b"zlib\n"})
        records = {"drupack-linux-amd64": digest.digest(engine), "runtime-macos-arm64": digest.digest(runtime)}
        folders = [str(engine), f"runtime-macos-arm64={runtime}"]
        self.assertEqual([], digest.check(records, folders, exactly=True))
        self.assertEqual([], digest.check(records, folders[:1], exactly=False))
        self.assertEqual(["runtime-macos-arm64: no artifact"], digest.check(records, folders[:1], exactly=True))
        (runtime / "out" / "drupack").write_bytes(b"swapped")
        self.assertEqual(["runtime-macos-arm64: its files differ from the record"], digest.check(records, folders, exactly=True))
        self.assertEqual(["downloaded: no record"], digest.check(records, [str(runtime)], exactly=False))


if __name__ == "__main__":
    unittest.main()
