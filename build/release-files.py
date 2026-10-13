#!/usr/bin/env python3
"""Writes a release's files from the executables a build wrote.

Usage: release-files.py --output DIR --version VERSION --base-url URL --commit SHA
           [--runtime RUNTIME]... FILE...

Each FILE is named NAME-TARGET, with .exe on Windows, as drupack-build and the
release workflow write it. DIR receives NAME-VERSION-TARGET for each file,
checksums.txt, release.json, and install-NAME.sh for each NAME, with
install-NAME.ps1 beside it when NAME has a Windows build. The scripts download
from URL, the directory holding the versioned files.

Each RUNTIME is a directory named runtime-TARGET, holding the runtime a macOS or
Windows job compiled. DIR receives it as runtime-VERSION-TARGET.tar.zst, which
checksums.txt and release.json list and no install script names.
"""

import argparse
import hashlib
import json
import re
import shutil
import subprocess
from pathlib import Path

# A Linux target names a musl build with a -musl suffix, and a glibc build with none.
BUILD = re.compile(r"(?P<name>[a-z0-9][a-z0-9-]*)"
                   r"-(?P<target>linux-(?:amd64|arm64)(?:-musl)?|(?:macos|windows)-(?:amd64|arm64))(?P<extension>\.exe)?")
# The job image carries the Linux runtimes, so a release publishes none of them.
RUNTIME = re.compile(r"runtime-(?P<target>(?:macos|windows)-(?:amd64|arm64))")
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


def listing(path, name, target, base_url):
    """The release.json entry of path, a file of the release."""
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return {"name": name, "asset": path.name, "target": target, "url": f"{base_url}/{path.name}",
            "sha256": digest.hexdigest(), "size": path.stat().st_size}


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--runtime", action="append", default=[], type=Path)
    parser.add_argument("files", nargs="+", type=Path)
    args = parser.parse_args()
    for value, pattern in ((args.version, VERSION), (args.base_url, URL)):
        if not pattern.fullmatch(value):
            parser.error(f"{value!r} holds a character the install scripts or file names cannot carry")
    args.output.mkdir(parents=True)

    builds = {}
    assets = []
    for source in args.files:
        build = BUILD.fullmatch(source.name)
        if build is None:
            parser.error(f"{source.name} is not named NAME-TARGET")
        asset = args.output / f"{build['name']}-{args.version}-{build['target']}{build['extension'] or ''}"
        shutil.copyfile(source, asset)
        asset.chmod(0o755)
        assets.append(listing(asset, build["name"], build["target"], args.base_url))
        builds.setdefault(build["name"], {})[build["target"]] = assets[-1]["sha256"]
    for directory in args.runtime:
        runtime = RUNTIME.fullmatch(directory.name)
        if runtime is None:
            parser.error(f"{directory.name} is not named runtime-TARGET")
        asset = args.output / f"runtime-{args.version}-{runtime['target']}.tar.zst"
        subprocess.run(["tar", "-I", "zstd -19 -T0", "-C", directory, "-cf", asset, "."], check=True)
        assets.append(listing(asset, "runtime", runtime["target"], args.base_url))
    checksums = [f"{asset['sha256']}  {asset['asset']}\n" for asset in assets]

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
