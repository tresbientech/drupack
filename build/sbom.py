#!/usr/bin/env python3
"""Writes a release's CycloneDX SBOM.

Usage: sbom.py runtime --target TARGET RUNTIME
       sbom.py merge --name NAME --version VERSION --runtime-components FILE...
           [--site SITE_JSON] --output FILE SYFT...

runtime prints, as JSON, a list holding the report of the runtime executable
that release target TARGET packs: the FrankenPHP, PHP, Caddy and SQLite releases
its --version names, each PHP extension it loads, and the C libraries PHP names,
each with its version. syft reads none of them from a release file, which
carries the runtime compressed inside the launcher.

merge writes the SBOM of release NAME at VERSION: each component of the reports
in the FILE lists, once per name and version with a drupack:target property per
target that ships it, Node when SITE_JSON names it, and each component of the
SYFT CycloneDX scans of the shipped files that has a version.
"""

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
import uuid
from datetime import datetime, timezone
from pathlib import Path

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


# Each C library by the PHP expression that names its release, and its CPE
# vendor and product. An expression yields '' where the runtime loads no
# extension linking that library.
LIBRARIES = {
    "OpenSSL": ("defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : ''", ("openssl", "openssl")),
    "curl": ("function_exists('curl_version') ? curl_version()['version'] : ''", ("haxx", "curl")),
    "nghttp2": ("function_exists('curl_version') ? curl_version()['nghttp2_version'] ?? '' : ''", ("nghttp2", "nghttp2")),
    "libssh2": ("function_exists('curl_version') ? curl_version()['libssh_version'] ?? '' : ''", ("libssh2", "libssh2")),
    "brotli": ("info('brotli', 'Brotli library version')", ("google", "brotli")),
    "libxml2": ("defined('LIBXML_DOTTED_VERSION') ? LIBXML_DOTTED_VERSION : ''", ("xmlsoft", "libxml2")),
    "zlib": ("defined('ZLIB_VERSION') ? ZLIB_VERSION : ''", ("zlib", "zlib")),
    "ICU": ("defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : ''",
            ("icu-project", "international_components_for_unicode")),
    "libsodium": ("defined('SODIUM_LIBRARY_VERSION') ? SODIUM_LIBRARY_VERSION : ''", ("libsodium_project", "libsodium")),
    "PCRE2": ("PCRE_VERSION", ("pcre", "pcre2")),
    "libzip": ("class_exists('ZipArchive') ? ZipArchive::LIBZIP_VERSION : ''", None),
    "libiconv": ("defined('ICONV_IMPL') && str_contains(ICONV_IMPL, 'libiconv') ? ICONV_VERSION : ''", ("gnu", "libiconv")),
    "libpq": ("defined('PGSQL_LIBPQ_VERSION') ? PGSQL_LIBPQ_VERSION : ''", ("postgresql", "postgresql")),
    "Oniguruma": ("defined('MB_ONIGURUMA_VERSION') ? MB_ONIGURUMA_VERSION : ''", ("oniguruma_project", "oniguruma")),
    "FreeType": ("info('gd', 'FreeType Version')", ("freetype", "freetype")),
    "libpng": ("info('gd', 'libPNG Version')", ("libpng", "libpng")),
    "LibYAML": ("info('yaml', 'LibYAML Version')", ("pyyaml", "libyaml")),
    "bzip2": ("info('bz2', 'BZip2 Version')", ("bzip", "bzip2")),
}
# info() reads a row of an extension's phpinfo() section, which the runtime's
# php-cli prints as HTML. A loaded extension without the row stops the report.
REPORT = r"""<?php
function info($extension, $label) {
    if (!extension_loaded($extension)) return '';
    ob_start();
    (new ReflectionExtension($extension))->info();
    $label = preg_quote($label, '/');
    // phpinfo() prints an HTML table under the frankenphp SAPI and plain text under cli.
    if (!preg_match('/>' . $label . ' <\/td><td class="v">([^<]*?) <|^' . $label . ' => ([^\r\n]*)/m', ob_get_clean(), $row)) {
        throw new RuntimeException("phpinfo() of $extension has no row $label");
    }
    return $row[2] ?? $row[1];
}
foreach (get_loaded_extensions() as $name) echo "extension\t", $name, "\t", phpversion($name), "\n";
"""
# The first number of a release string: "OpenSSL 3.6.5 29 Sep 2026", "libssh2/1.11.1".
RELEASE = re.compile(r"(?<!\w)\d[\w.]*")


def runtime_components(runtime):
    # The launcher points PHPRC at the runtime directory, whose php.ini loads the
    # extensions a Windows runtime carries as DLLs.
    environment = {**os.environ, "PHPRC": str(Path(runtime).parent)}
    lines = subprocess.run([runtime, "--version"], check=True, capture_output=True, text=True,
                           env=environment).stdout.splitlines()
    components = [REPORTED[name](version) for name, version in (line.split(" ", 1) for line in lines[1:])]
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as script:
        script.write(REPORT + "".join(f'echo "library\\t{name}\\t", {expression}, "\\n";\n'
                                      for name, (expression, _) in LIBRARIES.items()))
    try:
        report = subprocess.run([runtime, "php-cli", script.name], check=True, stdout=subprocess.PIPE, text=True,
                                env=environment).stdout
    finally:
        Path(script.name).unlink()
    for line in report.splitlines():
        kind, name, version = line.split("\t")
        if kind == "extension":
            # Composer names an extension ext-NAME, lowercase, with dashes for spaces.
            # mysqlnd reports its version as "mysqlnd 8.5.11".
            components.append({"type": "library", "name": "ext-" + name.lower().replace(" ", "-"),
                               "version": version.rsplit(" ", 1)[-1]})
        elif version:
            release = RELEASE.search(version).group()
            component = {"type": "library", "name": name, "version": release}
            if LIBRARIES[name][1]:
                component["cpe"] = cpe(*LIBRARIES[name][1], release)
            components.append(component)
    return components


def merge(args):
    # A target's report lists the component once, and the targets sharing a
    # release of it each add their name.
    components, targets = [], {}
    for path in args.runtime_components:
        for report in json.loads(path.read_text()):
            for component in report["components"]:
                key = (component["name"].lower(), component["version"])
                if key not in targets:
                    targets[key] = []
                    components.append(component)
                targets[key].append(report["target"])
    for component in components:
        component["properties"] = [{"name": "drupack:target", "value": target}
                                   for target in targets[(component["name"].lower(), component["version"])]]
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
    runtime.add_argument("--target", required=True)
    runtime.add_argument("runtime")
    merged = commands.add_parser("merge")
    merged.add_argument("--name", required=True)
    merged.add_argument("--version", required=True)
    merged.add_argument("--runtime-components", type=Path, nargs="+", required=True)
    merged.add_argument("--site", type=Path)
    merged.add_argument("--output", type=Path, required=True)
    merged.add_argument("syft", nargs="+", type=Path)
    args = parser.parse_args()
    if args.command == "runtime":
        json.dump([{"target": args.target, "components": runtime_components(args.runtime)}], sys.stdout, indent=2)
        print()
    else:
        args.output.write_text(json.dumps(merge(args), indent=2) + "\n")


if __name__ == "__main__":
    main()
