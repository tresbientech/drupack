"""build/upstream.py against copies of the real pin files and recorded indexes.
Run with:

    python3 -m unittest discover -s tests/upstream
"""

import importlib.util
import json
import re
import shutil
import sys
import tempfile
import unittest
import urllib.error
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("upstream", ROOT / "build" / "upstream.py")
upstream = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upstream)

PINNED = [upstream.BUILDER_INPUTS, upstream.WINDOWS_BUILD]
COMMIT = "c0ffee" * 6 + "c0ff"
SPC_COMMIT = "5bc" * 13 + "5"


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

    def serve_frankenphp(self, *tags, annotated=True, spc=SPC_COMMIT):
        self.indexes[upstream.SPC_BRANCH] = json.dumps({"commit": {"sha": spc}})
        api = upstream.FRANKENPHP_API
        self.indexes[f"{api}/releases?per_page=100"] = json.dumps(
            [{"tag_name": tag, "draft": False, "prerelease": False} for tag in tags])
        for tag in tags:
            if annotated:
                self.indexes[f"{api}/git/ref/tags/{tag}"] = json.dumps({"object": {"type": "tag", "sha": "a" * 40}})
                self.indexes[f"{api}/git/tags/{'a' * 40}"] = json.dumps({"object": {"type": "commit", "sha": COMMIT}})
            else:
                self.indexes[f"{api}/git/ref/tags/{tag}"] = json.dumps({"object": {"type": "commit", "sha": COMMIT}})

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
        path = self.tree / upstream.WINDOWS_BUILD
        path.write_text(re.sub(r"^\$phpVersion = .*$", "", path.read_text(), flags=re.MULTILINE))
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
        self.assertIn(f"\nspc_commit={SPC_COMMIT}\n", inputs)
        self.assertEqual(summary.rows[1][0::3], ("static-php-cli", "bumped"))

    def test_a_current_frankenphp_leaves_static_php_cli_alone(self):
        current = upstream.read(upstream.BUILDER_INPUTS, r"^frankenphp_version=(.+)$")
        pinned = upstream.read(upstream.BUILDER_INPUTS, upstream.SPC_PIN)
        self.serve_frankenphp(f"v{current}")
        self.run_bumper(upstream.frankenphp)
        self.assertEqual(upstream.read(upstream.BUILDER_INPUTS, upstream.SPC_PIN), pinned)

    def test_a_static_php_cli_head_that_is_no_commit_stops_the_run(self):
        self.serve_frankenphp("v1.99.0", spc="main")
        with self.assertRaises(SystemExit):
            self.run_bumper(upstream.frankenphp)

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

    def test_a_skipped_frankenphp_is_reported_and_not_taken(self):
        current = upstream.read(upstream.BUILDER_INPUTS, r"^frankenphp_version=(.+)$")
        self.serve_frankenphp("v1.13.0", f"v{current}")
        summary = self.run_bumper(upstream.frankenphp)
        self.assertEqual(summary.rows, [("FrankenPHP", current, current, "current")])
        self.assertEqual(summary.notes, [f"SKIPPED FrankenPHP 1.13.0: {upstream.FRANKENPHP_SKIPS['1.13.0']}"])


def build_script(case, name):
    """build/NAME.py as a module. Its own imports find build/ on sys.path."""
    sys.path.insert(0, str(ROOT / "build"))
    case.addCleanup(sys.path.remove, str(ROOT / "build"))
    spec = importlib.util.spec_from_file_location(name.replace("-", "_"), ROOT / "build" / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class NextVersion(unittest.TestCase):
    def setUp(self):
        self.module = build_script(self, "next-version")

    def test_a_prerelease_raises_its_prerelease_number(self):
        self.assertEqual(self.module.next_version(["0.9.0", "1.0.0-alpha1", "1.0.0-alpha2"]), "1.0.0-alpha3")

    def test_a_stable_release_raises_its_patch(self):
        self.assertEqual(self.module.next_version(["1.0.0-rc2", "1.0.0", "0.9.0"]), "1.0.1")

    def test_a_prerelease_number_past_nine_counts_on(self):
        self.assertEqual(self.module.next_version(["1.0.0-beta9", "1.0.0-beta10"]), "1.0.0-beta11")

    def test_a_named_version_above_every_tag_is_taken(self):
        self.assertEqual(self.module.named_version("1.0.0-beta1", ["0.9.0", "1.0.0-alpha11"]), "1.0.0-beta1")

    def test_a_named_version_at_or_below_a_tag_stops_the_run(self):
        for version in ("1.0.0-alpha11", "1.0.0-alpha2", "0.9.1"):
            with self.subTest(version), self.assertRaises(SystemExit):
                self.module.named_version(version, ["0.9.0", "1.0.0-alpha11"])

    def test_a_named_version_no_tag_run_would_publish_stops_the_run(self):
        for version in ("v1.0.0", "1.0", "1.0.0-ALPHA12", "1.0.0-dev", "1.0.0-alpha", "1.0.0 "):
            with self.subTest(version), self.assertRaises(SystemExit):
                self.module.named_version(version, ["0.9.0"])


class PinDocs(unittest.TestCase):
    def setUp(self):
        self.module = build_script(self, "pin-docs")
        self.guide = (ROOT / self.module.GUIDE).read_text()

    def test_every_line_naming_the_version_takes_the_new_one(self):
        current = re.search(self.module.PIN, self.guide, re.MULTILINE)[1]
        pinned = self.module.pin(self.guide, "9.8.7")
        self.assertNotIn(current, pinned)
        for line in ("drupack-build:9.8.7", "build.yml@9.8.7", "ref: 9.8.7", "version: 9.8.7"):
            self.assertIn(line, pinned)
        self.assertEqual(pinned.count("9.8.7"), self.guide.count(current))

    def test_a_guide_without_the_workflow_ref_stops_the_run(self):
        with self.assertRaises(SystemExit):
            self.module.pin("no pin here\n", "9.8.7")


class ReleaseNode(unittest.TestCase):
    def setUp(self):
        self.module = build_script(self, "release-node")

    def test_the_highest_tag_missing_from_packagist_is_named(self):
        self.assertEqual(self.module.unlisted(["1.0.0-alpha5", "1.0.0-alpha10", "0.9.0"], ["0.9.0", "1.0.0-alpha5"]),
                         "1.0.0-alpha10")

    def test_a_listed_highest_tag_names_nothing(self):
        self.assertIsNone(self.module.unlisted(["1.0.0-alpha5", "1.0.0-alpha6"], ["1.0.0-alpha6", "1.0.0-alpha5"]))

    def test_an_older_tag_without_a_node_names_nothing(self):
        self.assertIsNone(self.module.unlisted(["1.0.0-alpha5", "1.0.0-alpha6"], ["1.0.0-alpha6"]))


if __name__ == "__main__":
    unittest.main()
