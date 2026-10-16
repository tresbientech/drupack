#!/usr/bin/env python3
"""Writes a release's CycloneDX SBOM.

Usage: sbom.py runtime RUNTIME
       sbom.py merge --name NAME --version VERSION --runtime-components FILE
           [--site SITE_JSON] --output FILE SYFT...

runtime prints, as JSON, the components a runtime executable reports: the
FrankenPHP, PHP, Caddy and SQLite releases its --version names, and each PHP
extension it loads with its version. syft reads none of them from a release
file, which carries the runtime compressed inside the launcher.

merge writes the SBOM of release NAME at VERSION: those components, Node when
SITE_JSON names it, and each component of the SYFT CycloneDX scans of the
shipped files that has a version.
"""

import argparse
import json
import subprocess
import sys
import tempfile
import uuid
from datetime import datetime, timezone
from pathlib import Path

EXTENSIONS = '<?php foreach (get_loaded_extensions() as $name) echo $name, "\\t", phpversion($name), "\\n";'
# The FrankenPHP component names the release. syft reads its replaced module with
# no version, and its main module with the +dirty mark the build's own files add.
FRANKENPHP_MODULES = "pkg:golang/github.com/dunglas/frankenphp"


def cpe(vendor, product, version):
    return f"cpe:2.3:a:{vendor}:{product}:{version}:*:*:*:*:*:*:*"


# Each line --version prints after the release line, by its first word.
REPORTED = {
    "FrankenPHP": lambda v: {"type": "application", "name": "FrankenPHP", "version": v,
                             "purl": f"pkg:github/php/frankenphp@v{v}"},
    "PHP": lambda v: {"type": "application", "name": "PHP", "version": v, "cpe": cpe("php", "php", v)},
    "Caddy": lambda v: {"type": "library", "name": "github.com/caddyserver/caddy/v2", "version": f"v{v}",
                        "purl": f"pkg:golang/github.com/caddyserver/caddy/v2@v{v}"},
    "SQLite": lambda v: {"type": "library", "name": "SQLite", "version": v, "cpe": cpe("sqlite", "sqlite", v)},
}


def runtime_components(runtime):
    lines = subprocess.run([runtime, "--version"], check=True, capture_output=True, text=True).stdout.splitlines()
    components = [REPORTED[name](version) for name, version in (line.split(" ", 1) for line in lines[1:])]
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as script:
        script.write(EXTENSIONS)
    try:
        loaded = subprocess.run([runtime, "php-cli", script.name], check=True, capture_output=True, text=True).stdout
    finally:
        Path(script.name).unlink()
    # Composer names an extension ext-NAME, lowercase, with dashes for spaces.
    # mysqlnd reports its version as "mysqlnd 8.5.11".
    for line in loaded.splitlines():
        name, version = line.split("\t")
        components.append({"type": "library", "name": "ext-" + name.lower().replace(" ", "-"),
                           "version": version.rsplit(" ", 1)[-1]})
    return components


def merge(args):
    components = json.loads(args.runtime_components.read_text())
    if args.site:
        node = json.loads(args.site.read_text()).get("node")
        if node:
            components.append({"type": "application", "name": "Node.js", "version": node,
                               "purl": f"pkg:generic/node@{node}", "cpe": cpe("nodejs", "node.js", node)})
    scans = [json.loads(path.read_text()) for path in args.syft]
    # A scan lists a component once per file holding it, and a release holds a
    # build per target, so the first entry of each name and version stays.
    seen = {(c["name"].lower(), c["version"]) for c in components}
    for scan in scans:
        for component in scan.get("components", []):
            version = component.get("version")
            if component["type"] == "file" or version in (None, "", "UNKNOWN") \
                    or component.get("purl", "").startswith(FRANKENPHP_MODULES):
                continue
            key = (component["name"].lower(), version)
            if key in seen:
                continue
            seen.add(key)
            components.append({field: component[field] for field in ("type", "name", "version", "purl", "cpe", "licenses")
                               if field in component})
    return {
        "bomFormat": "CycloneDX",
        "specVersion": scans[0]["specVersion"],
        "serialNumber": f"urn:uuid:{uuid.uuid4()}",
        "version": 1,
        "metadata": {
            "timestamp": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "tools": scans[0]["metadata"]["tools"],
            "component": {"type": "application", "name": args.name, "version": args.version},
        },
        "components": components,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    commands = parser.add_subparsers(dest="command", required=True)
    runtime = commands.add_parser("runtime")
    runtime.add_argument("runtime")
    merged = commands.add_parser("merge")
    merged.add_argument("--name", required=True)
    merged.add_argument("--version", required=True)
    merged.add_argument("--runtime-components", type=Path, required=True)
    merged.add_argument("--site", type=Path)
    merged.add_argument("--output", type=Path, required=True)
    merged.add_argument("syft", nargs="+", type=Path)
    args = parser.parse_args()
    if args.command == "runtime":
        json.dump(runtime_components(args.runtime), sys.stdout, indent=2)
        print()
    else:
        args.output.write_text(json.dumps(merge(args), indent=2) + "\n")


if __name__ == "__main__":
    main()
