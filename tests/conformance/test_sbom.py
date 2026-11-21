"""build/release/sbom.py, run against a stub runtime and a scan shaped like syft's. Run with:

    python3 -m unittest discover -s tests/conformance -p test_sbom.py
"""

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[2] / "build" / "release" / "sbom.py"
LIBRARY_REPORT = SCRIPT.parents[2] / "runtime" / "builder" / "runtime-libraries.sh"
BRANCH_PINS = SCRIPT.parents[2] / "runtime" / "builder" / "branch-pins.txt"
# The stub answers drupack-version as the runtime does, and php-cli with the lines the
# report script prints. An empty library line names an extension it does not load.
RUNTIME = """#!/bin/sh
if [ "$1" = drupack-version ]; then
    printf ' (drupack dev)\\nFrankenPHP 1.12.7\\nPHP 8.5.11\\nCaddy 2.11.4\\nSQLite %s\\n'
else
    printf 'extension\\tCore\\t8.5.11\\nextension\\tmysqlnd\\tmysqlnd 8.5.11\\n'
    printf 'extension\\tZend OPcache\\t8.5.11\\nextension\\tapcu\\t5.1.28\\n'
    printf 'library\\tOpenSSL\\tOpenSSL 3.6.5 29 Sep 2026\\nlibrary\\tlibssh2\\tlibssh2/1.11.1\\n'
    printf 'library\\tbzip2\\t1.0.8, 13-Jul-2019\\nlibrary\\tlibzip\\t1.12.1\\nlibrary\\tnghttp2\\t\\n'
fi
"""


def component(name, version, kind="library", purl=None):
    entry = {"bom-ref": f"{name}-{version}", "type": kind, "name": name, "version": version}
    if purl:
        entry["purl"] = purl
    return entry


class Sbom(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory()
        self.addCleanup(scratch.cleanup)
        self.scratch = Path(scratch.name)
        self.runtime = self.stub("drupack", "3.45.2")

    def stub(self, name, sqlite):
        runtime = self.scratch / name
        runtime.write_text(RUNTIME % sqlite)
        runtime.chmod(0o755)
        return runtime

    def sbom(self, *args):
        return subprocess.run([sys.executable, SCRIPT, *map(str, args)], check=True, capture_output=True, text=True)

    def report(self, target, runtime):
        path = self.scratch / f"{target}.json"
        path.write_text(self.sbom("runtime", "--target", target, runtime).stdout)
        return path

    def test_runtime_lists_what_the_runtime_reports(self):
        [report] = json.loads(self.sbom("runtime", "--target", "linux-amd64", self.runtime).stdout)
        self.assertEqual(report["target"], "linux-amd64")
        components = report["components"]
        self.assertEqual([(c["name"], c["version"]) for c in components], [
            ("FrankenPHP", "1.12.7"), ("PHP", "8.5.11"), ("github.com/caddyserver/caddy/v2", "v2.11.4"),
            ("SQLite", "3.45.2"), ("ext-core", "8.5.11"), ("ext-mysqlnd", "8.5.11"),
            ("ext-zend-opcache", "8.5.11"), ("ext-apcu", "5.1.28"), ("OpenSSL", "3.6.5"),
            ("libssh2", "1.11.1"), ("bzip2", "1.0.8"), ("libzip", "1.12.1")])
        self.assertEqual(components[0]["purl"], "pkg:github/php/frankenphp@v1.12.7")
        self.assertEqual(components[8]["cpe"], "cpe:2.3:a:openssl:openssl:3.6.5:*:*:*:*:*:*:*")
        self.assertNotIn("cpe", components[11])

    def test_the_library_report_lists_the_records_on_the_link_line(self):
        pkgconfig = self.scratch / "buildroot" / "lib" / "pkgconfig"
        pkgconfig.mkdir(parents=True)
        for name, version, libs in (("libavif", "1.4.2", "-L${libdir} -lavif"),
                                    ("libcurl", "8.23.0", "-L${libdir} -lcurl -lssl"),
                                    ("libjpeg", "3.2.1", "-L${libdir} -ljpeg"),
                                    ("libssl", "3.6.5", "-L${libdir} -lssl"),
                                    ("libargon2", "ZERO", "-L${libdir} -largon2"),
                                    ("ncurses", "${version}", "-L${libdir} -lncurses"),
                                    ("form", "6.5", "-L${libdir} -lform -lcurl"),
                                    ("zlib", "1.4", "-L${libdir} -lz")):
            (pkgconfig / f"{name}.pc").write_text(
                f"version=6.6.1\nName: {name}\nVersion: {version}\nLibs: {libs}\nLibs.private: -lm\n")
        flags = "-Wl,-z,now -L/buildroot/lib -lavif -lcurl -largon2 -lncurses -lzstd /buildroot/lib/libjpeg.a"
        report = subprocess.run(["sh", LIBRARY_REPORT, self.scratch / "buildroot", flags],
                                check=True, capture_output=True, text=True).stdout
        self.assertEqual(report, "libavif 1.4.2\nlibcurl 8.23.0\nlibjpeg 3.2.1\nncurses 6.6.1\n")

    def test_the_library_report_refuses_a_link_line_no_record_names(self):
        pkgconfig = self.scratch / "buildroot" / "lib" / "pkgconfig"
        pkgconfig.mkdir(parents=True)
        (pkgconfig / "libavif.pc").write_text("Version: 1.4.2\nLibs: -lavif\n")
        result = subprocess.run(["sh", LIBRARY_REPORT, self.scratch / "buildroot", "-lother"],
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 1)
        self.assertIn("No pkg-config record", result.stderr)

    def test_runtime_adds_the_linked_libraries_php_does_not_name(self):
        libraries = self.scratch / "libraries.txt"
        libraries.write_text("aom 3.15.3\nlibcrypto 9.9.9\nlibexslt 0.8.25\nlibnghttp3 1.19.0\nlibsharpyuv 1.6.0\n"
                             "libssh2 9.9.9\nlibwebp 1.6.0\nlibwebpmux 1.6.0\nlibxslt 1.1.45\nnewlib 2.0\n")
        [report] = json.loads(self.sbom("runtime", "--target", "linux-amd64", "--libraries", libraries,
                                        self.runtime).stdout)
        linked = report["components"][12:]
        self.assertEqual([(c["name"], c["version"]) for c in linked], [
            ("aom", "3.15.3"), ("nghttp3", "1.19.0"), ("libwebp", "1.6.0"), ("libxslt", "1.1.45"), ("newlib", "2.0")])
        self.assertEqual([c.get("cpe") for c in linked], [
            "cpe:2.3:a:aomedia:aomedia:3.15.3:*:*:*:*:*:*:*", None,
            "cpe:2.3:a:webmproject:libwebp:1.6.0:*:*:*:*:*:*:*",
            "cpe:2.3:a:xmlsoft:libxslt:1.1.45:*:*:*:*:*:*:*", None])

    def spc_cache(self, entries):
        cache = self.scratch / ".cache.json"
        cache.write_text(json.dumps(entries))
        return subprocess.run([sys.executable, SCRIPT, "runtime", "--target", "windows-amd64", "--spc-cache", cache,
                               self.runtime], capture_output=True, text=True)

    def test_runtime_adds_the_libraries_static_php_cli_built_from_source(self):
        pins = {pin[0]: pin for pin in (line.split() for line in BRANCH_PINS.read_text().splitlines()) if pin[0][0] != "#"}
        source = {name: {"source": {"version": tag}} for name, tag in [
            ("brotli", "v1.2.1"), ("freetype", "VER-2-15-0"), ("postgresql", "REL_18_7"), ("libssh2", "libssh2-1.11.1"),
            ("libsodium", "1.0.22-RELEASE"), ("bzip2", None), ("php-src", "8.5.13"), ("ext-apcu", "v5.1.28")]}
        local = {name: {"source": {"cache_type": "local"}} for name in ("pthreads4w", "libaom")}
        result = self.spc_cache(source | local | {"jom": {"source": None, "binary": {}}})
        linked = json.loads(result.stdout)[0]["components"][12:]
        self.assertEqual([(c["name"], c["version"]) for c in linked], [
            ("brotli", "1.2.1"), ("freetype", "2.15.0"), ("aom", pins["libaom"][3][1:]), ("libsodium", "1.0.22"),
            ("libpq", "18.7"), ("pthreads4w", pins["pthreads4w"][4])])

    def test_a_source_version_that_names_no_release_stops_the_report(self):
        result = self.spc_cache({"zstd": {"source": {"version": "dev-main+abc1234"}}})
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("zstd at 'dev-main+abc1234'", result.stderr)

    def test_merge_keeps_scanned_packages_whose_purls_differ(self):
        runtime = self.report("linux-amd64", self.runtime)
        npm, composer = "pkg:npm/shared@1.0.0", "pkg:composer/acme/shared@1.0.0"
        scan = {"specVersion": "1.6", "metadata": {"tools": []}, "components": [
            component("shared", "1.0.0", purl=npm),
            component("shared", "1.0.0", purl=composer),
            component("shared", "1.0.0", purl=npm),
            component("shared", "1.0.0"),
            component("libssh2", "1.11.1", purl="pkg:generic/libssh2@1.11.1"),
        ]}
        scanned = self.scratch / "scan.json"
        scanned.write_text(json.dumps(scan))
        output = self.scratch / "sbom.cdx.json"
        self.sbom("merge", "--name", "acme", "--version", "1.0.0", "--runtime-components", runtime,
                  "--output", output, scanned)
        components = json.loads(output.read_text())["components"]
        self.assertEqual([c["purl"] for c in components if c["name"] == "shared"], [npm, composer])
        self.assertEqual([c.get("purl") for c in components if c["name"] == "libssh2"], [None])

    def test_merge_names_the_targets_that_ship_each_runtime_component(self):
        linux = self.report("linux-amd64", self.runtime)
        windows = self.report("windows-amd64", self.stub("frankenphp.exe", "3.53.4"))
        output = self.scratch / "sbom.cdx.json"
        scan = self.scratch / "scan.json"
        scan.write_text(json.dumps({"specVersion": "1.6", "metadata": {"tools": []}, "components": []}))
        self.sbom("merge", "--name", "drupack", "--version", "1.0.0", "--runtime-components", linux, windows,
                  "--output", output, scan)
        targets = {(c["name"], c["version"]): [p["value"] for p in c["properties"]]
                   for c in json.loads(output.read_text())["components"]}
        self.assertEqual(targets[("SQLite", "3.45.2")], ["linux-amd64"])
        self.assertEqual(targets[("SQLite", "3.53.4")], ["windows-amd64"])
        self.assertEqual(targets[("OpenSSL", "3.6.5")], ["linux-amd64", "windows-amd64"])

    def test_merge_keeps_each_versioned_component_once(self):
        runtime = self.report("linux-amd64", self.runtime)
        golang = "pkg:golang/github.com/spf13/cobra@v1.9.1"
        scan = {
            "specVersion": "1.6",
            "metadata": {"tools": {"components": [{"name": "syft"}]}},
            "components": [
                component("built/acme-linux-amd64", "", kind="file"),
                component("git.example/launcher", "UNKNOWN"),
                component("github.com/dunglas/frankenphp/caddy", "v1.12.7+dirty",
                          purl="pkg:golang/github.com/dunglas/frankenphp/caddy@v1.12.7+dirty"),
                component("php", "8.5.11", kind="application"),
                component("github.com/spf13/cobra", "v1.9.1", purl=golang),
                component("github.com/spf13/cobra", "v1.9.1", purl=golang),
                component("drupal/core", "11.4.10", purl="pkg:composer/drupal/core@11.4.10"),
            ],
        }
        scanned = self.scratch / "scan.json"
        scanned.write_text(json.dumps(scan))
        site = self.scratch / "site.json"
        site.write_text(json.dumps({"name": "acme", "node": "24.22.0"}))
        output = self.scratch / "sbom.cdx.json"
        self.sbom("merge", "--name", "acme", "--version", "2026.10.1", "--runtime-components", runtime,
                  "--site", site, "--output", output, scanned)
        sbom = json.loads(output.read_text())
        self.assertEqual(sbom["metadata"]["component"], {"type": "application", "name": "acme", "version": "2026.10.1"})
        self.assertEqual(sbom["specVersion"], "1.6")
        self.assertEqual([(c["name"], c["version"]) for c in sbom["components"]][11:], [
            ("libzip", "1.12.1"), ("Node.js", "24.22.0"), ("github.com/spf13/cobra", "v1.9.1"),
            ("drupal/core", "11.4.10")])


if __name__ == "__main__":
    unittest.main()
