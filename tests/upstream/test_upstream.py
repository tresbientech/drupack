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
import urllib.error
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("upstream", ROOT / "build" / "upstream.py")
upstream = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upstream)

DEMO_LOCK = "examples/drupacked-demo/composer.lock"
PINNED = [upstream.BUILDER_INPUTS, upstream.WINDOWS_BUILD, upstream.DEMO_COMPOSER, DEMO_LOCK, upstream.DEMO_SITE]
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
        self.addCleanup(setattr, upstream, "composer_update", upstream.composer_update)
        upstream.ROOT = self.tree
        self.php = upstream.read(upstream.BUILDER_INPUTS, r"^php_version=(.+)$")
        self.indexes = {}
        upstream.fetch = self.fetch

    def fetch(self, url):
        if url not in self.indexes:
            raise urllib.error.HTTPError(url, 404, "Not Found", {}, None)
        return self.indexes[url]

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

    def serve_composer(self, **listed):
        """Each require entry's metadata: the versions `listed` names, else its pin alone."""
        require = json.loads(self.text(upstream.DEMO_COMPOSER))["require"]
        for name, constraint in require.items():
            base = upstream.DRUPAL_METADATA if name.startswith("drupal/") else upstream.PACKAGIST_METADATA
            versions = listed.get(name, [constraint])
            self.indexes[f"{base}{name}.json"] = json.dumps({"packages": {name: [{"version": v} for v in versions]}})
            if "#" in constraint:
                self.indexes[f"{base}{name}~dev.json"] = json.dumps({"packages": {name: [
                    {"version": "dev-2.0.x", "source": {"reference": "bac7f817f055c11a14a84235c9c4777c3984498f"}}]}})
        self.updated = False
        upstream.composer_update = lambda: setattr(self, "updated", True)

    def pin(self, name):
        return json.loads(self.text(upstream.DEMO_COMPOSER))["require"][name]

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


    def test_an_alpha_moves_to_a_newer_beta(self):
        self.serve_composer(**{"drupal/wordpal": ["1.0.0-beta1", "1.0.0-alpha3", "1.0.0-alpha2"]})
        summary = self.run_bumper(upstream.composer)
        self.assertIn(("drupal/wordpal", "1.0.0-alpha2", "1.0.0-beta1", "bumped"), summary.rows)
        self.assertEqual(self.pin("drupal/wordpal"), "1.0.0-beta1")
        self.assertTrue(self.updated)

    def test_a_stable_pin_never_moves_to_a_prerelease(self):
        self.serve_composer(**{"drupal/canvas": ["1.13.0-beta1", "1.12.0"]})
        summary = self.run_bumper(upstream.composer)
        self.assertIn(("drupal/canvas", "1.12.0", "1.12.0", "current"), summary.rows)
        self.assertEqual(self.pin("drupal/canvas"), "1.12.0")

    def test_a_new_major_is_reported_and_not_taken(self):
        self.serve_composer(**{"drupal/canvas": ["2.0.0", "1.12.1", "1.12.0"]})
        summary = self.run_bumper(upstream.composer)
        self.assertEqual(self.pin("drupal/canvas"), "1.12.1")
        self.assertIn("MAJOR drupal/canvas 2.0.0 is out, outside the 1.x line", summary.notes)

    def test_a_held_package_is_reported_with_its_reason_and_left_alone(self):
        self.serve_composer(**{"twig/twig": ["v4.0.0-alpha1", "v3.30.0", "v3.29.0"]})
        summary = self.run_bumper(upstream.composer)
        self.assertEqual(self.pin("twig/twig"), "3.29.0")
        self.assertIn(f"HELD twig/twig 3.29.0 (3.30.0): {upstream.HOLDS['twig/twig']}", summary.notes)
        self.assertNotIn("twig/twig", [row[0] for row in summary.rows])

    def test_a_held_branch_pin_names_the_branch_head(self):
        self.serve_composer()
        summary = self.run_bumper(upstream.composer)
        self.assertIn(f"HELD drupal/ui_patterns 2.0.x-dev (bac7f81): {upstream.HOLDS['drupal/ui_patterns']}",
                      summary.notes)
        self.assertTrue(self.pin("drupal/ui_patterns").startswith("2.0.x-dev#"))

    def test_composer_update_moves_the_lock_and_each_move_is_listed(self):
        self.serve_composer()
        lock = self.tree / DEMO_LOCK

        def update():
            content = json.loads(lock.read_text())
            core = next(package for package in content["packages"] if package["name"] == "drupal/core")
            core["version"] = "11.99.0"
            lock.write_text(json.dumps(content))
        upstream.composer_update = update
        summary = self.run_bumper(upstream.composer)
        self.assertEqual([row[0] for row in summary.rows if row[3] == "locked"], ["drupal/core"])
        self.assertEqual(next(row for row in summary.rows if row[0] == "drupal/core")[2], "11.99.0")


    def test_a_drupal_package_absent_from_drupal_org_comes_from_packagist(self):
        self.serve_composer()
        name = "drupal/mercury_demo"
        del self.indexes[f"{upstream.DRUPAL_METADATA}{name}.json"]
        self.indexes[f"{upstream.PACKAGIST_METADATA}{name}.json"] = json.dumps(
            {"packages": {name: [{"version": "1.1.1"}, {"version": "1.1.0"}]}})
        summary = self.run_bumper(upstream.composer)
        self.assertIn((name, "1.1.0", "1.1.1", "bumped"), summary.rows)

    def test_a_package_no_repository_lists_stops_the_run(self):
        self.serve_composer()
        del self.indexes[f"{upstream.DRUPAL_METADATA}drupal/canvas.json"]
        with self.assertRaises(SystemExit):
            self.run_bumper(upstream.composer)


if __name__ == "__main__":
    unittest.main()
