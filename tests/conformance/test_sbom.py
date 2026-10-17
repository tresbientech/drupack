"""build/sbom.py, run against a stub runtime and a scan shaped like syft's. Run with:

    python3 -m unittest discover -s tests/conformance -p test_sbom.py
"""

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[2] / "build" / "sbom.py"
# The stub answers --version as the runtime does, and php-cli with the lines the
# report script prints. An empty library line names an extension it does not load.
RUNTIME = """#!/bin/sh
if [ "$1" = --version ]; then
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
