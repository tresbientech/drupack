"""build/upstream.py against copies of the real pin files and recorded indexes.
Run with:

    python3 -m unittest discover -s tests/upstream
"""

import importlib.util
import json
import re
import shutil
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("upstream", ROOT / "build" / "upstream.py")
upstream = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upstream)

PINNED = [upstream.BUILDER_INPUTS, upstream.WINDOWS_BUILD, upstream.DEMO_COMPOSER, upstream.DEMO_SITE]
COMMIT = "c0ffee" * 6 + "c0ff"


def checksums(*names):
    return "".join(f"{'ab' * 32} *{name}\n" for name in names)


class Bumper(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.tree = Path(scratch.name)
        for path in PINNED:
            (self.tree / path).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(ROOT / path, self.tree / path)
        self.addCleanup(setattr, upstream, "ROOT", upstream.ROOT)
        self.addCleanup(setattr, upstream, "fetch", upstream.fetch)
        upstream.ROOT = self.tree
        self.php = upstream.read(upstream.BUILDER_INPUTS, r"^php_version=(.+)$")
        self.indexes = {}
        upstream.fetch = lambda url: self.indexes[url]

    def serve_php(self, line_version, major_version=None, windows=None):
        self.indexes[upstream.PHP_RELEASES + "8.5"] = json.dumps({"version": line_version})
        self.indexes[upstream.PHP_RELEASES + "8"] = json.dumps({"version": major_version or line_version})
        names = windows if windows is not None else [
            f"php-{line_version}-Win32-vs17-x64.zip", f"php-devel-pack-{line_version}-Win32-vs17-x64.zip"]
        self.indexes[upstream.WINDOWS_CHECKSUMS] = checksums(*names)

    def run_bumper(self, source=upstream.php):
        summary = upstream.Summary()
        source(summary)
        return summary

    def serve_frankenphp(self, *tags, annotated=True):
        api = upstream.FRANKENPHP_API
        self.indexes[f"{api}/releases?per_page=100"] = json.dumps(
            [{"tag_name": tag, "draft": False, "prerelease": False} for tag in tags])
        for tag in tags:
            if annotated:
                self.indexes[f"{api}/git/ref/tags/{tag}"] = json.dumps({"object": {"type": "tag", "sha": "a" * 40}})
                self.indexes[f"{api}/git/tags/{'a' * 40}"] = json.dumps({"object": {"type": "commit", "sha": COMMIT}})
            else:
                self.indexes[f"{api}/git/ref/tags/{tag}"] = json.dumps({"object": {"type": "commit", "sha": COMMIT}})

    def serve_node(self, *releases):
        self.indexes[upstream.NODE_RELEASES] = json.dumps(
            [{"version": f"v{version}", "lts": lts} for version, lts in releases])

    def text(self, path):
        return (self.tree / path).read_text()

    def newer_patch(self):
        major, minor, patch = self.php.split(".")
        return f"{major}.{minor}.{int(patch) + 1}"

    def test_a_newer_patch_rewrites_every_php_pin(self):
        newer = self.newer_patch()
        self.serve_php(newer)
        summary = self.run_bumper()
        self.assertEqual(summary.rows, [("PHP", self.php, newer, "bumped")])
        self.assertIn(f"\nphp_version={newer}\n", self.text(upstream.BUILDER_INPUTS))
        windows = self.text(upstream.WINDOWS_BUILD)
        self.assertIn(f"$phpVersion = '{newer}'", windows)
        self.assertEqual(windows.count(f"Sha256 = '{'ab' * 32}'"), 2)
        self.assertIn(f'"platform": {{"php": "{newer}"}}', self.text(upstream.DEMO_COMPOSER))

    def test_the_current_version_writes_nothing(self):
        self.serve_php(self.php)
        before = {path: self.text(path) for path in PINNED}
        summary = self.run_bumper()
        self.assertEqual(summary.rows, [("PHP", self.php, self.php, "current")])
        self.assertEqual({path: self.text(path) for path in PINNED}, before)

    def test_a_missing_windows_zip_holds_php_back(self):
        newer = self.newer_patch()
        self.serve_php(newer, windows=[f"php-{newer}-Win32-vs17-x64.zip"])
        before = self.text(upstream.BUILDER_INPUTS)
        summary = self.run_bumper()
        self.assertEqual(summary.rows, [("PHP", self.php, newer, "waits for the Windows zips")])
        self.assertEqual(self.text(upstream.BUILDER_INPUTS), before)

    def test_a_newer_minor_is_reported_as_a_major(self):
        self.serve_php(self.php, major_version="8.6.0")
        summary = self.run_bumper()
        self.assertEqual(summary.notes, ["MAJOR PHP 8.6.0 is out, outside the 8.5 line"])
        self.assertIn("MAJOR PHP 8.6.0", summary.render())

    def test_a_malformed_version_from_php_net_stops_the_run(self):
        self.serve_php("8.5.x<script>")
        with self.assertRaises(SystemExit):
            self.run_bumper()

    def test_a_pin_matching_twice_stops_the_run(self):
        path = self.tree / upstream.BUILDER_INPUTS
        path.write_text(path.read_text() + f"php_version={self.php}\n")
        self.serve_php(self.newer_patch())
        with self.assertRaises(SystemExit):
            self.run_bumper()

    def test_a_pin_matching_nowhere_stops_the_run(self):
        path = self.tree / upstream.DEMO_COMPOSER
        path.write_text(re.sub(r'"platform": \{[^}]*\}', '"platform": {}', path.read_text()))
        self.serve_php(self.newer_patch())
        with self.assertRaises(SystemExit):
            self.run_bumper()


    def test_a_newer_frankenphp_rewrites_version_and_commit_in_both_builds(self):
        self.serve_frankenphp("v1.99.0", "v1.12.7")
        summary = self.run_bumper(upstream.frankenphp)
        self.assertEqual(summary.rows[0][2:], ("1.99.0", "bumped"))
        inputs = self.text(upstream.BUILDER_INPUTS)
        self.assertIn("\nfrankenphp_version=1.99.0\n", inputs)
        self.assertIn(f"\nfrankenphp_commit={COMMIT}\n", inputs)
        windows = self.text(upstream.WINDOWS_BUILD)
        self.assertIn("$frankenphpVersion = '1.99.0'", windows)
        self.assertIn(f"$frankenphpCommit = '{COMMIT}'", windows)

    def test_a_lightweight_frankenphp_tag_names_its_commit_directly(self):
        self.serve_frankenphp("v1.99.0", annotated=False)
        self.run_bumper(upstream.frankenphp)
        self.assertIn(f"\nfrankenphp_commit={COMMIT}\n", self.text(upstream.BUILDER_INPUTS))

    def test_frankenphp_2_is_reported_and_not_taken(self):
        current = upstream.read(upstream.BUILDER_INPUTS, r"^frankenphp_version=(.+)$")
        self.serve_frankenphp("v2.0.0", f"v{current}")
        summary = self.run_bumper(upstream.frankenphp)
        self.assertEqual(summary.rows, [("FrankenPHP", current, current, "current")])
        self.assertEqual(summary.notes, ["MAJOR FrankenPHP 2.0.0 is out, outside the 1 line"])

    def test_a_newer_node_lts_rewrites_the_demo_pin(self):
        self.serve_node(("25.9.0", False), ("24.99.1", "Krypton"), ("24.21.0", "Krypton"))
        summary = self.run_bumper(upstream.node)
        self.assertEqual(summary.rows, [("Node", "24.21.0", "24.99.1", "bumped")])
        self.assertIn("\nnode: 24.99.1\n", self.text(upstream.DEMO_SITE))

    def test_node_26_lts_is_reported_and_not_taken(self):
        self.serve_node(("26.1.0", "Next"), ("24.21.0", "Krypton"))
        summary = self.run_bumper(upstream.node)
        self.assertEqual(summary.rows, [("Node", "24.21.0", "24.21.0", "current")])
        self.assertEqual(summary.notes, ["MAJOR Node 26.1.0 LTS is out, outside the 24 line"])


if __name__ == "__main__":
    unittest.main()
