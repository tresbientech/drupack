"""The application archive build/app-payload.sh writes, checked on a small application.
Run with:

    python3 -m unittest discover -s tests/conformance -p test_app_payload.py
"""

import subprocess
import tarfile
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class AppPayload(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.application = Path(scratch.name) / "app"
        self.output = Path(scratch.name) / "payload"
        self.module = self.application / "web" / "modules" / "contrib" / "example"
        (self.module / ".git").mkdir(parents=True)
        (self.module / ".git" / "HEAD").write_text("ref: refs/heads/1.x\n")
        (self.module / "example.info.yml").write_text("name: Example\n")

    def pack(self, *docroot):
        subprocess.run(["bash", ROOT / "build" / "app-payload.sh", self.application, self.output, *docroot],
                       check=True)
        return (self.output / "app_checksum.txt").read_text()

    def test_a_package_installed_from_source_packs_without_its_git_clone(self):
        self.pack()
        with tarfile.open(self.output / "app-payload.tar") as archive:
            names = archive.getnames()
        self.assertIn("./web/modules/contrib/example/example.info.yml", names)
        self.assertEqual([name for name in names if ".git" in Path(name).parts], [])

    def test_the_checksum_ignores_a_git_clone(self):
        before = self.pack()
        (self.module / ".git" / "HEAD").write_text("ref: refs/heads/main\n")
        self.assertEqual(self.pack(), before)

    def test_the_checksum_keeps_a_file_the_payload_leaves_out(self):
        excluded = self.module / "tests" / "ExampleTest.php"
        excluded.parent.mkdir()
        excluded.write_text("one")
        before = self.pack("web")
        excluded.write_text("two")
        self.assertNotEqual(self.pack("web"), before)
        with tarfile.open(self.output / "app-payload.tar") as archive:
            self.assertEqual([name for name in archive.getnames() if "tests" in Path(name).parts], [])

    def test_only_a_payload_with_a_docroot_leaves_out_the_drupal_module_sources(self):
        source = self.application / "web" / "modules" / "contrib" / "canvas" / "ui" / "src" / "app.js"
        source.parent.mkdir(parents=True)
        source.write_text("x")
        name = "./web/modules/contrib/canvas/ui/src/app.js"
        self.pack("web")
        with tarfile.open(self.output / "app-payload.tar") as archive:
            self.assertNotIn(name, archive.getnames())
        self.pack()
        with tarfile.open(self.output / "app-payload.tar") as archive:
            self.assertIn(name, archive.getnames())


if __name__ == "__main__":
    unittest.main()
