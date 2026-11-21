"""build/release/upstream.py against copies of the real pin files and recorded indexes.
Run with:

    python3 -m unittest discover -s tests/upstream
"""

import base64
import contextlib
import hashlib
import importlib.util
import io
import json
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
import urllib.error
import urllib.request
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("upstream", ROOT / "build" / "release" / "upstream.py")
upstream = importlib.util.module_from_spec(spec)
spec.loader.exec_module(upstream)

PINNED = [upstream.BUILDER_INPUTS, upstream.WINDOWS_BUILD, upstream.RELEASE_WORKFLOW, upstream.DOCKERFILE, *upstream.GO_MODS,
          upstream.LAUNCHER_SUM, upstream.CA_BUNDLE, "build/test/go-tests.sh", upstream.LIBRARY_VERSIONS,
          upstream.EXTENSIONS_LIST, *upstream.EXTENSION_LISTS, upstream.SQLITE_PATCH, upstream.BRANCH_PINS]
# Each source whose move starts a release, by its function in upstream.py.
CARRIED = ("php", "composer", "frankenphp", "go", "ca_bundle", "sqlite", "libraries", "libaom")
COMMIT = "c0ffee" * 6 + "c0ff"
SPC_COMMIT = "5bc" * 13 + "5"
TOOL_SHA = "70" * 32
IMAGE_DIGEST = "d1" * 32


def rsa_key(directory, name):
    """An RSA private key at directory/name.pem and its public half at directory/name.pub."""
    private, public = directory / f"{name}.pem", directory / f"{name}.pub"
    subprocess.run(["openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048", "-out", private],
                   check=True, capture_output=True)
    subprocess.run(["openssl", "pkey", "-in", private, "-pubout", "-out", public], check=True, capture_output=True)
    return private, public


class Bumper(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        keys = tempfile.TemporaryDirectory()
        cls.addClassCleanup(keys.cleanup)
        cls.signer, cls.composer_key = rsa_key(Path(keys.name), "composer")
        cls.stranger, _ = rsa_key(Path(keys.name), "stranger")

    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.tree = Path(scratch.name)
        for path in PINNED:
            (self.tree / path).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(ROOT / path, self.tree / path)
        (self.tree / upstream.COMPOSER_KEY).parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(self.composer_key, self.tree / upstream.COMPOSER_KEY)
        self.addCleanup(setattr, upstream, "ROOT", upstream.ROOT)
        self.addCleanup(setattr, upstream, "fetch", upstream.fetch)
        self.addCleanup(setattr, upstream, "download", upstream.download)
        self.addCleanup(setattr, upstream, "head", upstream.head)
        self.addCleanup(setattr, upstream, "run_go", upstream.run_go)
        self.addCleanup(setattr, upstream, "run_spc", upstream.run_spc)
        upstream.ROOT = self.tree
        self.php = upstream.read(upstream.BUILDER_INPUTS, r"^php_version=(.+)$")
        self.indexes = {}
        self.archives = {}
        upstream.fetch = self.fetch
        upstream.download = self.download
        upstream.head = self.head
        self.digest = "sha256:" + IMAGE_DIGEST
        self.manifests = []

    def fetch(self, url):
        if url not in self.indexes:
            raise urllib.error.HTTPError(url, 404, "Not Found", {}, None)
        return self.indexes[url]

    def download(self, url):
        if url not in self.archives:
            raise urllib.error.HTTPError(url, 404, "Not Found", {}, None)
        return self.archives[url]

    def head(self, url, headers):
        if headers.get("Authorization") != "Bearer pull-token":
            raise urllib.error.HTTPError(url, 401, "Unauthorized", {}, None)
        self.manifests.append(url.removeprefix(upstream.DOCKER_MANIFESTS))
        return {"Docker-Content-Digest": self.digest}

    def serve_php(self, line_version, major_version=None):
        self.indexes[upstream.PHP_RELEASES + "8.5"] = json.dumps({"version": line_version})
        self.indexes[upstream.PHP_RELEASES + "8"] = json.dumps({"version": major_version or line_version})

    def run_bumper(self, source=upstream.php):
        summary = upstream.Summary()
        source(summary)
        return summary

    def serve_frankenphp(self, *tags, annotated=True, spc=SPC_COMMIT, go_mod="go 1.1\n"):
        self.indexes[upstream.SPC_BRANCH] = json.dumps({"commit": {"sha": spc}})
        self.indexes[f"{upstream.FRANKENPHP_RAW}/{COMMIT}/caddy/go.mod"] = f"module example\n\n{go_mod}"
        api = upstream.FRANKENPHP_API
        self.indexes[f"{api}/releases?per_page=100"] = json.dumps(
            [{"tag_name": tag, "draft": False, "prerelease": False} for tag in tags])
        for tag in tags:
            if annotated:
                self.indexes[f"{api}/git/ref/tags/{tag}"] = json.dumps({"object": {"type": "tag", "sha": "a" * 40}})
                self.indexes[f"{api}/git/tags/{'a' * 40}"] = json.dumps({"object": {"type": "commit", "sha": COMMIT}})
            else:
                self.indexes[f"{api}/git/ref/tags/{tag}"] = json.dumps({"object": {"type": "commit", "sha": COMMIT}})

    def serve_go(self, *versions, go_mod="go 1.1\n", stable=True):
        """Lists `versions` on go.dev, and `go_mod` as the caddy/go.mod of the pinned FrankenPHP commit."""
        pinned = upstream.read(upstream.BUILDER_INPUTS, upstream.FRANKENPHP_COMMIT_PIN)
        self.indexes[f"{upstream.FRANKENPHP_RAW}/{pinned}/caddy/go.mod"] = f"module example\n\n{go_mod}"
        self.indexes[upstream.GO_RELEASES] = json.dumps([{"version": v, "stable": stable} for v in versions])
        self.indexes[upstream.DOCKER_AUTH] = json.dumps({"token": "pull-token"})

    def go_pins(self):
        return {path: upstream.read(path, upstream.GO_LINE) for path in upstream.GO_MODS} | {
            upstream.RELEASE_WORKFLOW: upstream.read(upstream.RELEASE_WORKFLOW, upstream.GO_PIN),
            upstream.DOCKERFILE: upstream.read(upstream.DOCKERFILE, upstream.GO_IMAGE_PIN),
        }

    def text(self, path):
        return (self.tree / path).read_text()

    def newer_patch(self):
        major, minor, patch = self.php.split(".")
        return f"{major}.{minor}.{int(patch) + 1}"

    def test_a_newer_patch_rewrites_the_php_pin(self):
        newer = self.newer_patch()
        self.serve_php(newer)
        summary = self.run_bumper()
        self.assertEqual(summary.rows, [("PHP", self.php, newer, "bumped")])
        self.assertIn(f"\nphp_version={newer}\n", self.text(upstream.BUILDER_INPUTS))

    def test_the_current_version_writes_nothing(self):
        self.serve_php(self.php)
        before = {path: self.text(path) for path in PINNED}
        summary = self.run_bumper()
        self.assertEqual(summary.rows, [("PHP", self.php, self.php, "current")])
        self.assertEqual({path: self.text(path) for path in PINNED}, before)

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
        path = self.tree / upstream.BUILDER_INPUTS
        path.write_text(re.sub(r"^php_version=.*$", "", path.read_text(), flags=re.MULTILINE))
        self.serve_php(self.newer_patch())
        with self.assertRaises(SystemExit):
            self.run_bumper()

    def test_a_newer_frankenphp_rewrites_version_and_commit(self):
        self.serve_frankenphp("v1.99.0", "v1.12.7")
        summary = self.run_bumper(upstream.frankenphp)
        self.assertEqual(summary.rows[0][2:], ("1.99.0", "bumped"))
        inputs = self.text(upstream.BUILDER_INPUTS)
        self.assertIn("\nfrankenphp_version=1.99.0\n", inputs)
        self.assertIn(f"\nfrankenphp_commit={COMMIT}\n", inputs)
        self.assertIn(f"\nspc_commit={SPC_COMMIT}\n", inputs)
        self.assertEqual(summary.rows[1][0::3], ("static-php-cli", "bumped"))

    def newer_go(self):
        current = upstream.read(upstream.RELEASE_WORKFLOW, upstream.GO_PIN)
        line, patch = current.rsplit(".", 1)
        return current, f"{line}.{int(patch) + 1}"

    def test_a_newer_go_patch_rewrites_every_go_pin(self):
        current, newer = self.newer_go()
        self.serve_go(f"go{newer}", f"go{current}")
        summary = self.run_bumper(upstream.go)
        self.assertEqual(set(self.go_pins().values()), {newer})
        self.assertEqual(upstream.read(upstream.DOCKERFILE, upstream.GO_IMAGE_DIGEST_PIN), IMAGE_DIGEST)
        self.assertEqual(self.manifests, [f"{newer}-bookworm"])
        self.assertEqual(summary.rows, [("Go", current, newer, "bumped")])

    def test_a_newer_go_line_in_frankenphp_takes_its_newest_patch(self):
        self.serve_go("go1.99.5", "go1.99.4", go_mod="go 1.99.3\n")
        self.run_bumper(upstream.go)
        self.assertEqual(set(self.go_pins().values()), {"1.99.5"})

    def test_a_go_line_without_a_patch_admits_its_first_release(self):
        self.serve_go("go1.99.0", go_mod="go 1.99\n")
        self.run_bumper(upstream.go)
        self.assertEqual(set(self.go_pins().values()), {"1.99.0"})

    def test_the_newest_listed_go_writes_nothing(self):
        current, _ = self.newer_go()
        self.serve_go(f"go{current}")
        before = self.go_pins()
        summary = self.run_bumper(upstream.go)
        self.assertEqual(self.go_pins(), before)
        self.assertEqual(summary.rows, [("Go", current, current, "current")])

    def test_unstable_and_malformed_go_releases_are_skipped(self):
        current, newer = self.newer_go()
        self.serve_go(f"go{current}")
        listed = json.loads(self.indexes[upstream.GO_RELEASES])
        listed += [{"version": f"go{newer}", "stable": False}, {"version": f"go{newer}rc1", "stable": True},
                   {"version": f"go{newer}.1", "stable": True}, {"stable": True}]
        self.indexes[upstream.GO_RELEASES] = json.dumps(listed)
        before = self.go_pins()
        self.run_bumper(upstream.go)
        self.assertEqual(self.go_pins(), before)

    def test_no_listed_release_at_the_frankenphp_floor_stops_the_run(self):
        self.serve_go("go1.99.2", go_mod="go 1.99.3\n")
        before = self.go_pins()
        with self.assertRaises(SystemExit):
            self.run_bumper(upstream.go)
        self.assertEqual(self.go_pins(), before)

    def test_a_malformed_go_line_stops_the_run(self):
        for go_mod in ("go 1.99rc1\n", "", "go 1.99.3\ngo 1.99.4\n"):
            with self.subTest(go_mod), self.assertRaises(SystemExit):
                self.serve_go("go1.99.4", go_mod=go_mod)
                self.run_bumper(upstream.go)

    def test_a_malformed_image_digest_stops_the_run_before_any_write(self):
        _, newer = self.newer_go()
        self.serve_go(f"go{newer}")
        before = self.go_pins()
        for digest in (None, "sha256:" + "d1" * 31, "sha512:" + "d1" * 32):
            with self.subTest(digest), self.assertRaises(SystemExit):
                self.digest = digest
                self.run_bumper(upstream.go)
            self.assertEqual(self.go_pins(), before)

    def serve_tools(self, **versions):
        """Answers each Go tool's latest version: `versions` by name, its pin for any other."""
        for name, module, path in upstream.GO_TOOLS:
            version = versions.get(name, "v" + upstream.read(path, upstream.tool_pin(name, module)))
            self.indexes[f"{upstream.GO_PROXY}{module}/@latest"] = json.dumps({"Version": version})

    def tool_pins(self):
        return {name: upstream.read(path, upstream.tool_pin(name, module)) for name, module, path in upstream.GO_TOOLS}

    def test_a_newer_tool_release_rewrites_its_pin(self):
        current = self.tool_pins()
        self.serve_tools(govulncheck="v1.99.0")
        summary = self.run_bumper(upstream.tools)
        self.assertEqual(self.tool_pins(), current | {"govulncheck": "1.99.0"})
        self.assertEqual(summary.rows, [
            ("golangci-lint", current["golangci-lint"], current["golangci-lint"], "current"),
            ("govulncheck", current["govulncheck"], "1.99.0", "bumped")])

    def test_current_tool_releases_write_nothing(self):
        before = self.tool_pins()
        self.serve_tools()
        self.run_bumper(upstream.tools)
        self.assertEqual(self.tool_pins(), before)

    def test_a_malformed_tool_version_stops_the_run(self):
        for version in ("v2.99.0-rc.1", "2.99.0", None):
            with self.subTest(version), self.assertRaises(SystemExit):
                self.serve_tools(**{"golangci-lint": version})
                self.run_bumper(upstream.tools)

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

    def serve_composer(self, *versions, signer=None, sig=None):
        """Lists `versions` as Composer's stable releases, each with a phar signed by `signer`,
        the kept key's own by default, or with `sig` as its composer.phar.sig."""
        self.indexes[upstream.COMPOSER_VERSIONS] = json.dumps({"stable": [{"version": v} for v in versions]})
        for version in versions:
            phar = f"phar {version}".encode()
            self.archives[f"{upstream.COMPOSER_DOWNLOAD}{version}/composer.phar"] = phar
            signature = subprocess.run(["openssl", "dgst", "-sha384", "-sign", signer or self.signer],
                                       input=phar, capture_output=True, check=True).stdout
            self.indexes[f"{upstream.COMPOSER_DOWNLOAD}{version}/composer.phar.sig"] = (
                sig if sig is not None else json.dumps({"sha384": base64.b64encode(signature).decode()}))

    def composer_pin(self):
        return upstream.read(upstream.DOCKERFILE, upstream.COMPOSER_PIN)

    def test_a_newer_signed_composer_rewrites_its_version_and_checksum(self):
        current = self.composer_pin()
        self.serve_composer("2.99.0", "2.2.32")
        summary = self.run_bumper(upstream.composer)
        self.assertEqual(summary.rows, [("Composer", current, "2.99.0", "bumped")])
        self.assertEqual(self.composer_pin(), "2.99.0")
        self.assertEqual(upstream.read(upstream.DOCKERFILE, upstream.COMPOSER_SHA_PIN), hashlib.sha256(b"phar 2.99.0").hexdigest())

    def test_a_composer_signed_by_another_key_stops_the_run_before_its_pins(self):
        self.serve_composer("2.99.0", signer=self.stranger)
        before = self.text(upstream.DOCKERFILE)
        with self.assertRaises(SystemExit):
            self.run_bumper(upstream.composer)
        self.assertEqual(self.text(upstream.DOCKERFILE), before)

    def test_a_malformed_composer_signature_stops_the_run_before_its_pins(self):
        for sig in ('{"sha384": "not base64!"}', "{}", "not json"):
            with self.subTest(sig=sig):
                self.serve_composer("2.99.0", sig=sig)
                before = self.text(upstream.DOCKERFILE)
                with self.assertRaises(SystemExit):
                    self.run_bumper(upstream.composer)
                self.assertEqual(self.text(upstream.DOCKERFILE), before)

    def test_the_current_composer_writes_nothing(self):
        current = self.composer_pin()
        self.serve_composer(current, "2.2.32")
        before = self.text(upstream.DOCKERFILE)
        summary = self.run_bumper(upstream.composer)
        self.assertEqual(summary.rows, [("Composer", current, current, "current")])
        self.assertEqual(self.text(upstream.DOCKERFILE), before)

    def test_a_malformed_composer_version_stops_the_run(self):
        self.serve_composer("2.99.0;id")
        with self.assertRaises(SystemExit):
            self.run_bumper(upstream.composer)

    def test_composer_3_is_reported_and_not_taken(self):
        current = self.composer_pin()
        self.serve_composer("3.0.0", current)
        summary = self.run_bumper(upstream.composer)
        self.assertEqual(summary.rows, [("Composer", current, current, "current")])
        self.assertEqual(summary.notes, ["MAJOR Composer 3.0.0 is out, outside the 2 line"])

    def serve_ca(self, date="Thu Nov  5 04:12:01 2099", roots=2, checksum=None, header=None):
        """Publishes a CA bundle of `roots` certificates, and returns it."""
        root = "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n"
        first = header if header is not None else f"## Certificate data from Mozilla as of: {date} GMT\n"
        bundle = (first + root * roots).encode()
        self.archives[upstream.CA_RELEASE] = bundle
        self.indexes[upstream.CA_CHECKSUM] = f"{checksum or hashlib.sha256(bundle).hexdigest()}  cacert.pem\n"
        return bundle

    def ca_pins(self):
        return (self.tree / upstream.CA_BUNDLE).read_bytes(), self.text(upstream.DOCKERFILE)

    def ca_day(self):
        return upstream.ca_date(self.text(upstream.CA_BUNDLE)).date().isoformat()

    def test_a_newer_ca_extract_rewrites_the_bundle_and_its_note(self):
        current = self.ca_day()
        bundle = self.serve_ca()
        summary = self.run_bumper(upstream.ca_bundle)
        self.assertEqual((self.tree / upstream.CA_BUNDLE).read_bytes(), bundle)
        self.assertEqual(upstream.read(upstream.DOCKERFILE, upstream.CA_SHA_PIN), hashlib.sha256(bundle).hexdigest())
        self.assertEqual(upstream.read(upstream.DOCKERFILE, upstream.CA_COUNT_PIN), "2")
        self.assertEqual(summary.rows, [("CA bundle", current, "2099-11-05", "bumped")])

    def test_a_ca_extract_that_is_not_newer_writes_nothing(self):
        before = self.ca_pins()
        same = upstream.read(upstream.CA_BUNDLE, upstream.CA_DATE)
        for date in (same, "Mon Jan  1 00:00:00 2001"):
            with self.subTest(date):
                self.serve_ca(date)
                summary = self.run_bumper(upstream.ca_bundle)
                self.assertEqual(summary.rows[0][3], "current")
                self.assertEqual(self.ca_pins(), before)

    def test_a_ca_bundle_that_fails_its_checksum_stops_the_run_before_any_write(self):
        before = self.ca_pins()
        self.serve_ca(checksum="ab" * 32)
        with self.assertRaises(SystemExit):
            self.run_bumper(upstream.ca_bundle)
        self.assertEqual(self.ca_pins(), before)

    def test_a_malformed_ca_bundle_stops_the_run_before_any_write(self):
        before = self.ca_pins()
        cases = {"no certificate": {"roots": 0}, "no date": {"header": ""}, "a bad date": {"date": "soon"}}
        for name, bundle in cases.items():
            with self.subTest(name), self.assertRaises(SystemExit):
                self.serve_ca(**bundle)
                self.run_bumper(upstream.ca_bundle)
            self.assertEqual(self.ca_pins(), before)

    def move_modules(self, go_line=None):
        """Stands in for go: raises golang.org/x/sys, and the go line when `go_line` is set."""
        self.go_calls = []

        def run_go(*arguments):
            self.go_calls.append(arguments)
            path = self.tree / upstream.LAUNCHER_MOD
            text = re.sub(r"(golang\.org/x/sys) v\S+", r"\1 v9.9.9", path.read_text())
            path.write_text(re.sub(r"^go .+$", f"go {go_line}", text, flags=re.MULTILINE) if go_line else text)
            (self.tree / upstream.LAUNCHER_SUM).write_text("moved\n")
        upstream.run_go = run_go

    def test_go_modules_move_inside_their_majors(self):
        current = upstream.go_requirements()["golang.org/x/sys"]
        self.move_modules()
        summary = self.run_bumper(upstream.go_modules)
        self.assertEqual(summary.rows, [("golang.org/x/sys", current, "v9.9.9", "bumped")])
        self.assertEqual(self.go_calls, [("get", "-u", "-t", "./..."), ("mod", "tidy")])
        self.assertEqual(self.text(upstream.LAUNCHER_SUM), "moved\n")

    def test_go_modules_that_need_a_newer_go_wait_for_it(self):
        line = upstream.read(upstream.LAUNCHER_MOD, upstream.GO_LINE)
        before = self.text(upstream.LAUNCHER_MOD), self.text(upstream.LAUNCHER_SUM)
        self.move_modules("9.9.9")
        summary = self.run_bumper(upstream.go_modules)
        self.assertEqual(summary.rows, [("Go modules", line, "9.9.9", "waits for that Go")])
        self.assertEqual((self.text(upstream.LAUNCHER_MOD), self.text(upstream.LAUNCHER_SUM)), before)

    def serve_sqlite(self, *products):
        """Lists each (release, path) of `products` as a sqlite-autoconf on sqlite.org, among its other products."""
        lines = ["PRODUCT,VERSION,RELATIVE-URL,SIZE-IN-BYTES,SHA3-HASH", "PRODUCT,3.99.9,2099/sqlite-src-3990900.zip,14899528,877c"]
        lines += [f"PRODUCT,{release},{path},3332984,b6ce" for release, path in products]
        self.indexes[upstream.SQLITE_DOWNLOADS] = "<!-- Download product data for scripts to read\n" + "\n".join(lines) + "\n-->\n"

    def sqlite_pin(self):
        return upstream.read(upstream.SQLITE_PATCH, upstream.SQLITE_PIN)

    def test_a_newer_sqlite_rewrites_the_url_of_the_patch(self):
        pinned = self.sqlite_pin()
        self.serve_sqlite(("3.99.9", "2099/sqlite-autoconf-3990900.tar.gz"))
        summary = self.run_bumper(upstream.sqlite)
        self.assertEqual(summary.rows, [("SQLite", upstream.sqlite_release(pinned), "3.99.9", "bumped")])
        self.assertEqual(self.sqlite_pin(), "2099/sqlite-autoconf-3990900.tar.gz")
        self.assertEqual(self.text(upstream.SQLITE_PATCH).replace("2099/sqlite-autoconf-3990900.tar.gz", pinned),
                         (ROOT / upstream.SQLITE_PATCH).read_text())

    def test_a_sqlite_at_or_under_the_patch_writes_nothing(self):
        pinned = self.sqlite_pin()
        current = upstream.sqlite_release(pinned)
        for release, path in ((current, pinned), ("3.8.11", "2015/sqlite-autoconf-3081100.tar.gz")):
            with self.subTest(release):
                self.serve_sqlite((release, path))
                summary = self.run_bumper(upstream.sqlite)
                self.assertEqual(summary.rows, [("SQLite", current, release, "current")])
                self.assertEqual(self.sqlite_pin(), pinned)

    def test_a_malformed_sqlite_product_list_stops_the_run(self):
        pinned = self.sqlite_pin()
        twice = ("3.99.9", "2099/sqlite-autoconf-3990900.tar.gz"), ("3.99.8", "2099/sqlite-autoconf-3990800.tar.gz")
        for products in ((), twice, (("3.99.9", "2099/sqlite-autoconf-3990800.tar.gz"),), (("3.99.x", "2099/sqlite-autoconf-3990900.tar.gz"),)):
            with self.subTest(products):
                self.serve_sqlite(*products)
                with self.assertRaises(SystemExit):
                    self.run_bumper(upstream.sqlite)
                self.assertEqual(self.sqlite_pin(), pinned)

    def test_a_sqlite_outside_the_3_line_is_noted_and_not_written(self):
        pinned = self.sqlite_pin()
        self.serve_sqlite(("4.0.0", "2099/sqlite-autoconf-4000000.tar.gz"))
        summary = self.run_bumper(upstream.sqlite)
        self.assertEqual(summary.notes, ["MAJOR SQLite 4.0.0 is out, outside the 3 line"])
        self.assertEqual([row[3] for row in summary.rows], ["current"])
        self.assertEqual(self.sqlite_pin(), pinned)

    def libraries(self, summary):
        """Records what the probe answers, as upstream.yml's two jobs do between them."""
        upstream.libraries(summary, json.loads(json.dumps(upstream.probe_libraries())))

    def serve_spc(self, releases, unsupported=(), recorded=""):
        """Stands in for static-php-cli. `releases` maps an artifact to the newest release it names,
        `unsupported` lists the artifacts whose URL is fixed, and `recorded` is the versions file.
        It returns the answer it gives check-update, which a test may change."""
        (self.tree / upstream.LIBRARY_VERSIONS).write_text(recorded)
        (self.tree / upstream.EXTENSION_LISTS[0]).write_text("curl   # a comment\nzlib\n")
        (self.tree / upstream.EXTENSION_LISTS[1]).write_text("watcher\n")
        answers = {name: {"need-update": True, "unsupported": name in unsupported, "old": None, "new": new}
                   for name, new in {**dict.fromkeys(unsupported), **releases}.items()}
        self.spc_calls = []

        def run_spc(directory, *arguments):
            self.spc_calls.append(arguments)
            if arguments[0] == "check-update":
                return json.dumps(answers)
            licenses = Path(arguments[-1].removeprefix("--dump-dir="))
            licenses.mkdir()
            (licenses / "SUMMARY.json").write_text(json.dumps({"artifacts": dict.fromkeys(answers, {})}))
            return "a log line\n"
        upstream.run_spc = run_spc
        return answers

    def test_a_library_release_rewrites_its_line(self):
        self.serve_spc({"zlib": "v1.4", "curl": "curl-8_23_0"}, recorded="curl curl-8_22_0\nzlib v1.4\n")
        summary = self.run_bumper(self.libraries)
        self.assertEqual(summary.rows, [("curl", "curl-8_22_0", "curl-8_23_0", "bumped"), ("zlib", "v1.4", "v1.4", "current")])
        self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), "curl curl-8_23_0\nzlib v1.4\n")
        self.assertEqual(self.spc_calls[0][:3], ("dump-license", "--for-extensions=curl,zlib", "--for-libs=watcher"))
        self.assertEqual(self.spc_calls[1], ("check-update", "curl,zlib", "--bare", "--json"))

    def test_recorded_library_releases_write_nothing(self):
        recorded = "curl curl-8_23_0\nzlib v1.4\n"
        self.serve_spc({"curl": "curl-8_23_0", "zlib": "v1.4"}, recorded=recorded)
        summary = self.run_bumper(self.libraries)
        self.assertEqual({row[3] for row in summary.rows}, {"current"})
        self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), recorded)

    def test_an_artifact_with_no_release_of_its_own_stays_out(self):
        self.serve_spc({"php-src": "8.5.13", "libaom": "dev-main+d03d2c6", "zlib": "v1.4"}, unsupported=("bzip2", "sqlite"),
                       recorded="zlib v1.4\n")
        summary = self.run_bumper(self.libraries)
        self.assertEqual(summary.rows, [("zlib", "v1.4", "v1.4", "current")])
        self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), "zlib v1.4\n")
        self.assertEqual(self.spc_calls[1][1], "bzip2,libaom,sqlite,zlib")

    def test_an_artifact_static_php_cli_no_longer_links_leaves_the_file_with_a_row(self):
        self.serve_spc({"zlib": "v1.4"}, unsupported=("bzip2",), recorded="bzip2 1.0.8\nxz v5.8.4\nzlib v1.4\n")
        summary = self.run_bumper(self.libraries)
        self.assertEqual(summary.rows[1:], [("bzip2", "1.0.8", "none", "removed"), ("xz", "v5.8.4", "none", "removed")])
        self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), "zlib v1.4\n")

    def test_a_malformed_answer_from_static_php_cli_stops_the_run(self):
        for name, new in (("zlib", None), ("zlib", ""), ("zlib", "v1.4 | 2"), ("zlib", "<b>"), ("zlib", 14), ("z lib", "v1.4"),
                          ("zlib", "../../x"), ("zlib", "v" * 65), ("z" * 65, "v1.4")):
            with self.subTest(name=name, new=new):
                self.serve_spc({name: new}, recorded="zlib v1.3\n")
                with self.assertRaises(SystemExit):
                    self.run_bumper(self.libraries)
                self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), "zlib v1.3\n")

    def test_an_unsupported_flag_that_is_no_boolean_stops_the_run(self):
        answers = self.serve_spc({"zlib": "v1.4"})
        answers["zlib"]["unsupported"] = "false"
        with self.assertRaises(SystemExit):
            self.run_bumper(self.libraries)

    def test_an_artifact_new_to_the_file_reads_none(self):
        self.serve_spc({"xz": "v5.8.4", "zlib": "v1.4"}, recorded="zlib v1.4\n")
        summary = self.run_bumper(self.libraries)
        self.assertEqual(summary.rows[0], ("xz", "none", "v5.8.4", "bumped"))
        self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), "xz v5.8.4\nzlib v1.4\n")

    def serve_watcher(self, release, digest):
        self.indexes[upstream.WATCHER_RELEASES + release] = json.dumps({"assets": [
            {"name": "aarch64-pc-windows-msvc.tar", "digest": "sha256:" + "11" * 32},
            {"name": upstream.WATCHER_ASSET, "digest": digest}]})

    def test_a_watcher_release_reaches_the_windows_build(self):
        self.serve_spc({"watcher": "9.9.9", "zlib": "v1.4"}, recorded="watcher 0.14.5\nzlib v1.4\n")
        self.serve_watcher("9.9.9", "sha256:" + TOOL_SHA)
        summary = self.run_bumper(self.libraries)
        self.assertEqual(summary.rows[0], ("watcher", "0.14.5", "9.9.9", "bumped"))
        windows = self.text(upstream.WINDOWS_BUILD)
        self.assertIn(f"/e-dant/watcher/releases/download/9.9.9/{upstream.WATCHER_ASSET}'", windows)
        self.assertEqual(upstream.read(upstream.WINDOWS_BUILD, upstream.WATCHER_SHA_PIN), TOOL_SHA)

    def test_a_recorded_watcher_leaves_the_windows_build_alone(self):
        before = self.text(upstream.WINDOWS_BUILD)
        self.serve_spc({"watcher": "0.14.5"}, recorded="watcher 0.14.5\n")
        self.run_bumper(self.libraries)
        self.assertEqual(self.text(upstream.WINDOWS_BUILD), before)

    def test_a_watcher_release_without_a_windows_digest_stops_the_run_before_any_write(self):
        before = self.text(upstream.WINDOWS_BUILD)
        for digest in (None, "sha256:short", "md5:" + TOOL_SHA):
            with self.subTest(digest):
                self.serve_spc({"watcher": "9.9.9"}, recorded="watcher 0.14.5\n")
                self.serve_watcher("9.9.9", digest)
                with self.assertRaises(SystemExit):
                    self.run_bumper(self.libraries)
                self.assertEqual(self.text(upstream.WINDOWS_BUILD), before)
                self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), "watcher 0.14.5\n")

    def test_the_library_lines_stay_sorted(self):
        self.serve_spc({"zlib": "v1.4", "curl": "curl-8_23_0", "libpng": "v1.6.59"})
        self.run_bumper(self.libraries)
        self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), "curl curl-8_23_0\nlibpng v1.6.59\nzlib v1.4\n")
        self.assertEqual(self.spc_calls[1][1], "curl,libpng,zlib")

    def serve_libaom(self, tags, prefix=upstream.GITILES_PREFIX):
        """Lists `tags`, each tag name with its gitiles entry, as libaom's tags."""
        url = upstream.read(upstream.BRANCH_PINS, upstream.LIBAOM_URL_PIN)
        self.indexes[f"{url}/+refs/tags?format=JSON"] = prefix + json.dumps(tags)

    def test_the_newest_libaom_release_tag_rewrites_its_tag_and_commit(self):
        before, current = self.text(upstream.BRANCH_PINS), upstream.read(upstream.BRANCH_PINS, upstream.LIBAOM_PIN)
        self.serve_libaom({"v4.0.0": {"value": "3" * 40, "peeled": "4" * 40}, "v5.0.0-rc1": {"value": "5" * 40},
                           "v9": {"value": "6" * 40}, "3gpp-2021-10-15": {"value": "7" * 40}})
        summary = self.run_bumper(upstream.libaom)
        self.assertEqual(summary.rows, [("libaom", current.split()[0], "v4.0.0", "bumped")])
        self.assertEqual(self.text(upstream.BRANCH_PINS), before.replace(current, f"v4.0.0 {'4' * 40}"))
        # A lightweight tag names the commit itself.
        self.serve_libaom({"v4.1.0": {"value": "5" * 40}})
        self.run_bumper(upstream.libaom)
        self.assertEqual(self.text(upstream.BRANCH_PINS), before.replace(current, f"v4.1.0 {'5' * 40}"))

    def test_the_current_libaom_tag_writes_nothing(self):
        before = self.text(upstream.BRANCH_PINS)
        self.serve_libaom({"v3.15.2": {"value": "1" * 40}, "v3.15.3": {"value": "1" * 40, "peeled": "b" * 40}})
        summary = self.run_bumper(upstream.libaom)
        self.assertEqual(summary.rows, [("libaom", "v3.15.3", "v3.15.3", "current")])
        self.assertEqual(self.text(upstream.BRANCH_PINS), before)

    def test_a_malformed_libaom_answer_stops_the_run_before_any_write(self):
        before = self.text(upstream.BRANCH_PINS)
        for entry, prefix in (({"value": "1" * 40, "peeled": None}, upstream.GITILES_PREFIX),
                              ({"value": "ABC"}, upstream.GITILES_PREFIX), ({"peeled": "1" * 39}, upstream.GITILES_PREFIX),
                              ("1" * 40, upstream.GITILES_PREFIX), ({"value": "1" * 40}, "")):
            with self.subTest(entry=entry, prefix=prefix):
                self.serve_libaom({"v4.0.0": entry}, prefix)
                with self.assertRaises(SystemExit):
                    self.run_bumper(upstream.libaom)
                self.assertEqual(self.text(upstream.BRANCH_PINS), before)

    def run_bump(self, *moved):
        """Runs the whole bump with each source of `moved` moving its pin. It returns the suite
        the bump names and what ran after the sources that start a release."""
        ran = []

        def source(name):
            def step(summary, *answers):
                ran.append(name)
                summary.row(name, "1", "2" if name in moved else "1", "bumped" if name in moved else "current")
            return step
        for name in (*CARRIED, "tools", "go_modules"):
            self.addCleanup(setattr, upstream, name, getattr(upstream, name))
            setattr(upstream, name, source(name))
        return upstream.bump(upstream.Summary(), {}), ran[len(CARRIED):]

    def test_each_pin_the_executables_carry_brings_the_tools_and_modules_along(self):
        for name in CARRIED:
            with self.subTest(name):
                self.assertEqual(self.run_bump(name)[1], ["tools", "go_modules"])

    def test_tools_and_modules_stay_when_no_carried_pin_moved(self):
        self.assertEqual(self.run_bump()[1], [])

    def test_the_libraries_moving_alone_take_the_gate_suite(self):
        for moved in (("libraries",), ("sqlite",), ("libaom",), ("sqlite", "libraries")):
            with self.subTest(moved):
                self.assertEqual(self.run_bump(*moved), ("gate", ["tools", "go_modules"]))

    def test_any_other_pin_moving_takes_the_full_suite(self):
        for name in ("php", "composer", "frankenphp", "go", "ca_bundle"):
            with self.subTest(name):
                self.assertEqual(self.run_bump(name, "libraries")[0], "full")

    def test_the_suite_reaches_the_output_file(self):
        output = self.tree / "output"
        output.write_text("earlier=1\n")
        self.addCleanup(setattr, upstream, "bump", upstream.bump)
        answers = self.tree / "answers.json"
        answers.write_text('{"zlib": {"unsupported": false, "new": "v1.4"}}')
        given = []
        upstream.bump = lambda summary, answers: given.append(answers) or "gate"
        arguments = ["upstream.py", "--libraries", str(answers), "--output", str(output)]
        with mock.patch.object(sys, "argv", arguments), contextlib.redirect_stdout(io.StringIO()):
            upstream.main()
        self.assertEqual(output.read_text(), "earlier=1\nsuite=gate\n")
        self.assertEqual(given, [{"zlib": {"unsupported": False, "new": "v1.4"}}])

    def test_the_probe_prints_the_answers_and_writes_nothing(self):
        self.serve_spc({"zlib": "v1.4", "php-src": "8.5.13"}, recorded="zlib v1.3\n")
        self.addCleanup(setattr, upstream, "bump", upstream.bump)
        upstream.bump = lambda summary, answers: self.fail("the probe moved a pin")
        printed = io.StringIO()
        with mock.patch.object(sys, "argv", ["upstream.py", "--probe-libraries"]), contextlib.redirect_stdout(printed):
            upstream.main()
        self.assertEqual(list(json.loads(printed.getvalue())), ["zlib"])
        self.assertEqual(printed.getvalue().count("\n"), 1)
        self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), "zlib v1.3\n")

    def test_a_bump_without_the_answers_is_refused(self):
        with mock.patch.object(sys, "argv", ["upstream.py"]), contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit):
                upstream.main()

    def test_answers_that_are_no_object_stop_the_run(self):
        (self.tree / upstream.LIBRARY_VERSIONS).write_text("zlib v1.3\n")
        for answers in ([], None, "zlib"):
            with self.subTest(answers), self.assertRaises(SystemExit):
                upstream.libraries(upstream.Summary(), answers)
        self.assertEqual(self.text(upstream.LIBRARY_VERSIONS), "zlib v1.3\n")


def build_script(case, name):
    """build/release/NAME.py as a module. Its own imports find build/release/ on sys.path."""
    sys.path.insert(0, str(ROOT / "build" / "release"))
    case.addCleanup(sys.path.remove, str(ROOT / "build" / "release"))
    spec = importlib.util.spec_from_file_location(name.replace("-", "_"), ROOT / "build" / "release" / f"{name}.py")
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

    def test_the_notes_start_at_the_release_before_the_tag(self):
        tags = ["1.0.0-beta9", "1.0.0-beta10", "1.0.0-beta11", "0.9.0"]
        self.assertEqual(self.module.previous(tags, "1.0.0-beta10"), "1.0.0-beta9")
        self.assertEqual(self.module.previous(tags, "1.0.0-beta11"), "1.0.0-beta10")

    def test_the_notes_credit_nod__in_place_of_nod_bot(self):
        notes = "<h3>Contributors (1)</h3>\n<p>BOT</p>\n<h3>Changelog</h3>\n"
        self.assertEqual(self.module.credited(notes.replace("BOT", self.module.BOT)),
                         notes.replace("BOT", self.module.OWNER))

    def test_the_notes_credit_nod__once(self):
        other = '<a href="https://www.drupal.org/u/someone">someone</a>'
        names = ", ".join([self.module.BOT, other, self.module.OWNER])
        self.assertEqual(self.module.credited(f"<h3>Contributors (3)</h3>\n<p>{names}</p>\n"),
                         f"<h3>Contributors (2)</h3>\n<p>{self.module.OWNER}, {other}</p>\n")

    def test_the_notes_keep_an_empty_contributor_list(self):
        notes = "<h3>Contributors (0)</h3>\n<p></p>\n<h3>Changelog</h3>\n"
        self.assertEqual(self.module.credited(notes), notes)

    def test_the_form_entry_names_the_release_types_the_notes_hold(self):
        entry = self.module.form_entry("1.0.0", "<h4>Bug</h4>\n<ul></ul>\n<h4>Task</h4>\n")
        self.assertIn("Release type: Bug fixes.", entry)
        self.assertIn(self.module.FORM, entry)
        self.assertIn("```html\n<h4>Bug</h4>", entry)

    def test_a_commit_body_gives_its_bumped_rows(self):
        summary = upstream.Summary()
        summary.row("PHP", "8.5.12", "8.5.13", "bumped")
        summary.row("Composer", "2.11.2", "2.11.2", "current")
        summary.row("Go modules", "1.27", "1.28", "waits for that Go")
        summary.row("static-php-cli", "5e913373a08c", "0a1b2c3d4e5f", "bumped")
        summary.notes.append("MAJOR PHP 9.0.0 is out, outside the 8.5 line")
        body = summary.render() + "\nDrupacked Demo: 2026.11.7 to 2026.11.9\n"
        self.assertEqual(upstream.bumped(body),
                         [("PHP", "8.5.12", "8.5.13"), ("static-php-cli", "5e913373a08c", "0a1b2c3d4e5f")])

    def test_the_notes_list_each_move_escaped(self):
        self.assertEqual(self.module.upgrades_html(["PHP 8.5.12 to 8.5.13", "<b> 1 to 2&3"]),
                         "<h3>Upgraded packages</h3>\n<ul>\n<li>PHP 8.5.12 to 8.5.13</li>\n"
                         "<li>&lt;b&gt; 1 to 2&amp;3</li>\n</ul>\n")
        self.assertEqual(self.module.upgrades_markdown(["PHP 8.5.12 to 8.5.13", "<b> 1 to 2&3"]),
                         "## Upgraded packages\n\n- PHP 8.5.12 to 8.5.13\n- &lt;b&gt; 1 to 2&amp;3\n")

    def test_a_tag_without_a_move_gets_no_heading(self):
        self.assertEqual(self.module.upgrades_html([]), "")
        self.assertEqual(self.module.upgrades_markdown([]), "")

    FIRST_STEP = """<form id="search-block-form"><input type="text" name="search_block_form" value=""></form>
        <textarea name="stray">outside any form</textarea>
        <form id="project-release-node-form" action="/node/add/project-release/3624016">
        <select name="field_release_vcs_label[und][0][value]"><option value="" selected></option>
        <option value="1.0.0-beta20">1.0.0-beta20</option></select>
        <input type="hidden" name="form_build_id" value="build-1"><input type="hidden" name="form_token" value="token">
        <input type="submit" name="op" value="Next"></form>"""
    SECOND_STEP = """<form id="project-release-node-form" action="/node/add/project-release/3624016">
        <input type="hidden" name="form_build_id" value="build-2"><input type="hidden" name="form_token" value="token">
        <textarea name="body[und][0][value]"></textarea>
        <input type="text" name="field_release_short_description[und][0][value]" value="">
        <input type="checkbox" name="taxonomy_vocabulary_7[und][100]" value="100">
        <input type="checkbox" name="taxonomy_vocabulary_7[und][99]" value="99">
        <input type="checkbox" name="taxonomy_vocabulary_7[und][98]" value="98">
        <input type="submit" name="op" value="Save"><input type="submit" name="op" value="Preview"></form>"""

    def drupal_org(self, *pages):
        """Stands in for drupal.org: answers each request with the next of PAGES and records what was sent."""
        answers, self.requests = list(pages), []

        def as_nod_bot(url, sent=None):
            self.requests.append((url, sent))
            return answers.pop(0)
        self.module.as_nod_bot = as_nod_bot

    def post(self, tag, notes):
        stdin = sys.stdin
        self.addCleanup(setattr, sys, "stdin", stdin)
        sys.stdin = io.StringIO(notes)
        with contextlib.redirect_stdout(io.StringIO()):
            self.module.post(tag)

    def test_a_post_picks_the_tag_then_saves_the_notes_and_the_release_types(self):
        self.drupal_org(self.FIRST_STEP, self.SECOND_STEP, "<h1>drupack 1.0.0-beta20</h1>", "the release page")
        self.post("1.0.0-beta20", self.module.PLACEHOLDER + "<h4>Bug</h4>\n<ul><li>fix</li></ul>\n")
        (_, read), (_, picked), (_, saved), (proof, _) = self.requests
        self.assertIsNone(read)
        self.assertEqual(picked, {"field_release_vcs_label[und][0][value]": "1.0.0-beta20", "form_build_id": "build-1",
                                  "form_token": "token", "op": "Next"})
        self.assertEqual(saved, {"form_build_id": "build-2", "form_token": "token",
                                 "body[und][0][value]": "<h4>Bug</h4>\n<ul><li>fix</li></ul>\n",
                                 "field_release_short_description[und][0][value]": "",
                                 "taxonomy_vocabulary_7[und][99]": 99, "op": "Save"})
        self.assertEqual(proof, "https://www.drupal.org/project/drupack/releases/1.0.0-beta20")

    def test_a_tag_the_form_no_longer_offers_is_read_back_and_not_posted(self):
        self.drupal_org(self.FIRST_STEP, "the release page")
        self.post("1.0.0-beta19", "<h4>Bug</h4>\n")
        self.assertEqual([sent for _, sent in self.requests], [None, None])
        self.assertEqual(self.requests[1][0], "https://www.drupal.org/project/drupack/releases/1.0.0-beta19")

    def test_a_form_drupal_refuses_stops_the_run_with_its_reason(self):
        refusal = '<div class="messages error"><h2>Error message</h2> The tag <em>1.0.0-beta20</em> is taken.</div>'
        self.drupal_org(self.FIRST_STEP, self.SECOND_STEP, refusal + self.SECOND_STEP)
        with self.assertRaises(SystemExit) as stopped:
            self.post("1.0.0-beta20", "<h4>Task</h4>\n")
        self.assertEqual(str(stopped.exception), "drupal.org refused the form: Error message The tag 1.0.0-beta20 is taken.")
        self.assertEqual(len(self.requests), 3)


class Download(unittest.TestCase):
    def test_a_redirect_leaves_the_github_token_behind(self):
        opened = []

        def urlopen(request, timeout):
            opened.append(request)
            return io.BytesIO(b"body")

        with mock.patch.dict("os.environ", {"GITHUB_TOKEN": "runner-token"}), \
                mock.patch.object(urllib.request, "urlopen", urlopen):
            self.assertEqual(upstream.download("https://api.github.com/repos/acme/tool"), b"body")
        self.assertEqual(opened[0].get_header("Authorization"), "Bearer runner-token")
        redirected = urllib.request.HTTPRedirectHandler().redirect_request(
            opened[0], None, 302, "Found", {}, "https://downloads.example/tool")
        self.assertIsNone(redirected.get_header("Authorization"))


class GoPins(unittest.TestCase):
    def test_every_go_pin_names_one_version(self):
        pins = {path: upstream.read(path, upstream.GO_LINE) for path in upstream.GO_MODS}
        pins[upstream.RELEASE_WORKFLOW] = upstream.read(upstream.RELEASE_WORKFLOW, upstream.GO_PIN)
        pins[upstream.DOCKERFILE] = upstream.read(upstream.DOCKERFILE, upstream.GO_IMAGE_PIN)
        self.assertEqual(len(set(pins.values())), 1, pins)


if __name__ == "__main__":
    unittest.main()
