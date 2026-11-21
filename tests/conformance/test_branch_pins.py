"""runtime/builder/fetch-branch-pins.sh, run against local origins. Run with:

    python3 -m unittest discover -s tests/conformance -p test_branch_pins.py
"""

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

BUILDER = Path(__file__).resolve().parents[2] / "runtime" / "builder"


def git(*arguments):
    return subprocess.run(["git", *arguments], check=True, capture_output=True, text=True).stdout.strip()


class BranchPins(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.root = Path(scratch.name)
        # Each script finds its neighbours and the pin list beside itself.
        self.builder = self.root / "builder"
        self.builder.mkdir()
        for script in ("fetch-branch-pins.sh", "fetch-commit.sh"):
            shutil.copy(BUILDER / script, self.builder)
        self.pinned = {name: self.origin(name) for name in ("every", "windowsonly")}

    def origin(self, name):
        """An origin with two commits, the pin on the first, so a branch head is not what lands."""
        origin = self.root / "origins" / name
        git("init", "--quiet", str(origin))
        commits = []
        for content in ("pinned\n", "head\n"):
            (origin / "file.txt").write_text(content)
            git("-C", str(origin), "add", "file.txt")
            git("-C", str(origin), "-c", "user.name=test", "-c", "user.email=test@example.com",
                "commit", "--quiet", "-m", content.strip())
            commits.append(git("-C", str(origin), "rev-parse", "HEAD"))
        return commits[0]

    def write_pins(self, pinned):
        (self.builder / "branch-pins.txt").write_text("# NAME PLATFORMS URL REF COMMIT\n" + "".join(
            f"{name} {'windows' if name == 'windowsonly' else 'all'} {(self.root / 'origins' / name).as_uri()} master {commit}\n"
            for name, commit in pinned.items()))

    def fetch(self, platform):
        return subprocess.run(["bash", str(self.builder / "fetch-branch-pins.sh"), platform, str(self.root / "pins")],
                              capture_output=True, text=True, check=False)

    def test_each_line_of_the_platform_lands_at_its_commit(self):
        self.write_pins(self.pinned)
        linux = self.fetch("linux")
        self.assertEqual(linux.stdout.splitlines(), [f"every:{self.root / 'pins' / 'every'}"], linux.stderr)
        self.assertFalse((self.root / "pins" / "windowsonly").exists())
        windows = self.fetch("windows")
        self.assertEqual(windows.stdout.splitlines(), [f"{name}:{self.root / 'pins' / name}" for name in self.pinned],
                         windows.stderr)
        for name, commit in self.pinned.items():
            self.assertEqual(git("-C", str(self.root / "pins" / name), "rev-parse", "HEAD"), commit)
            self.assertEqual((self.root / "pins" / name / "file.txt").read_text(), "pinned\n")

    def test_a_kept_checkout_is_reset_without_a_fetch(self):
        self.write_pins(self.pinned)
        self.assertEqual(self.fetch("linux").returncode, 0)
        checkout = self.root / "pins" / "every"
        (checkout / "file.txt").write_text("edited by a build\n")
        (checkout / "build.o").write_text("output\n")
        # A fetch would fail now, so a pass shows the checkout was kept.
        shutil.rmtree(self.root / "origins" / "every")
        result = self.fetch("linux")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((checkout / "file.txt").read_text(), "pinned\n")
        self.assertFalse((checkout / "build.o").exists())

    def test_a_commit_the_origin_lacks_stops_the_fetch(self):
        self.write_pins({"every": "0" * 40})
        result = self.fetch("linux")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, "")


if __name__ == "__main__":
    unittest.main()
