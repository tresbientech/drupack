#!/usr/bin/env python3
"""Writes a release's files from the executables a build wrote.

Usage: release-files.py --output DIR --version VERSION --base-url URL --commit SHA FILE...

Each FILE is named NAME-TARGET, with .exe on Windows, as drupack-build and the
release workflow write it. DIR receives NAME-VERSION-TARGET for each file,
checksums.txt, release.json, and install-NAME.sh for each NAME, with
install-NAME.ps1 beside it when NAME has a Windows build. The scripts download
from URL, the directory holding the versioned files.
"""

import argparse
import hashlib
import json
import re
import shutil
from pathlib import Path

# A Linux target names a musl build with a -musl suffix, and a glibc build with none.
BUILD = re.compile(r"(?P<name>[a-z0-9][a-z0-9-]*)"
                   r"-(?P<target>linux-(?:amd64|arm64)(?:-musl)?|(?:macos|windows)-(?:amd64|arm64))(?P<extension>\.exe)?")
# The scripts carry these values inside quoted shell and PowerShell strings, so a
# value holding a character either language reads there is refused. The version
# also names files, so it holds no path separator.
VERSION = re.compile(r"[A-Za-z0-9][A-Za-z0-9._+-]*")
URL = re.compile(r"[A-Za-z0-9._+:/-]+")
TEMPLATES = Path(__file__).resolve().parent


def stamp(template, output, name, version, base_url, builds):
    """Writes install-NAME from template and returns its checksums.txt line."""
    text = (TEMPLATES / template).read_text()
    for placeholder, value in (("@NAME@", name), ("@VERSION@", version), ("@BASE_URL@", base_url),
                               ("@BUILDS@", builds)):
        text = text.replace(placeholder, value)
    script = f"install-{name}{Path(template).suffix}"
    # A Windows checkout writes CRLF, and sh reads a carriage return as part of each line.
    (output / script).write_bytes(text.encode())
    return f"{hashlib.sha256(text.encode()).hexdigest()}  {script}\n"


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("files", nargs="+", type=Path)
    args = parser.parse_args()
    for value, pattern in ((args.version, VERSION), (args.base_url, URL)):
        if not pattern.fullmatch(value):
            parser.error(f"{value!r} holds a character the install scripts or file names cannot carry")
    args.output.mkdir(parents=True)

    builds = {}
    assets = []
    checksums = []
    for source in args.files:
        build = BUILD.fullmatch(source.name)
        if build is None:
            parser.error(f"{source.name} is not named NAME-TARGET")
        asset = f"{build['name']}-{args.version}-{build['target']}{build['extension'] or ''}"
        shutil.copyfile(source, args.output / asset)
        (args.output / asset).chmod(0o755)
        digest = hashlib.sha256()
        with open(args.output / asset, "rb") as handle:
            for chunk in iter(lambda: handle.read(1 << 20), b""):
                digest.update(chunk)
        sha256 = digest.hexdigest()
        builds.setdefault(build["name"], {})[build["target"]] = sha256
        checksums.append(f"{sha256}  {asset}\n")
        assets.append({"name": build["name"], "asset": asset, "target": build["target"],
                       "url": f"{args.base_url}/{asset}", "sha256": sha256,
                       "size": (args.output / asset).stat().st_size})

    for name, targets in builds.items():
        rows = sorted(targets.items())
        checksums.append(stamp("install.sh", args.output, name, args.version, args.base_url,
                               "\n".join(f"{target} {sha256}" for target, sha256 in rows)))
        if any(target.startswith("windows-") for target in targets):
            checksums.append(stamp("install.ps1", args.output, name, args.version, args.base_url,
                                   "\n".join(f"        '{target}' = '{sha256}'" for target, sha256 in rows)))

    (args.output / "checksums.txt").write_bytes("".join(checksums).encode())
    (args.output / "release.json").write_bytes((json.dumps(
        {"version": args.version, "build_commit": args.commit, "assets": assets}, indent=2) + "\n").encode())


if __name__ == "__main__":
    main()
