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
# extension script prints.
RUNTIME = """#!/bin/sh
if [ "$1" = --version ]; then
    printf ' (drupack dev)\\nFrankenPHP 1.12.7\\nPHP 8.5.11\\nCaddy 2.11.4\\nSQLite 3.45.2\\n'
else
    printf 'Core\\t8.5.11\\nmysqlnd\\tmysqlnd 8.5.11\\nZend OPcache\\t8.5.11\\napcu\\t5.1.28\\n'
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
        self.runtime = self.scratch / "drupack"
        self.runtime.write_text(RUNTIME)
        self.runtime.chmod(0o755)

    def sbom(self, *args):
        return subprocess.run([sys.executable, SCRIPT, *map(str, args)], check=True, capture_output=True, text=True)

    def test_runtime_lists_what_the_runtime_reports(self):
        components = json.loads(self.sbom("runtime", self.runtime).stdout)
        self.assertEqual([(c["name"], c["version"]) for c in components], [
            ("FrankenPHP", "1.12.7"), ("PHP", "8.5.11"), ("github.com/caddyserver/caddy/v2", "v2.11.4"),
            ("SQLite", "3.45.2"), ("ext-core", "8.5.11"), ("ext-mysqlnd", "8.5.11"),
            ("ext-zend-opcache", "8.5.11"), ("ext-apcu", "5.1.28")])
        self.assertEqual(components[0]["purl"], "pkg:github/php/frankenphp@v1.12.7")

    def test_merge_keeps_each_versioned_component_once(self):
        runtime = self.scratch / "runtime.json"
        runtime.write_text(self.sbom("runtime", self.runtime).stdout)
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
        self.assertEqual([(c["name"], c["version"]) for c in sbom["components"]][4:], [
            ("ext-core", "8.5.11"), ("ext-mysqlnd", "8.5.11"), ("ext-zend-opcache", "8.5.11"),
            ("ext-apcu", "5.1.28"), ("Node.js", "24.22.0"), ("github.com/spf13/cobra", "v1.9.1"),
            ("drupal/core", "11.4.10")])


if __name__ == "__main__":
    unittest.main()
