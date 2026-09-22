#!/usr/bin/env python3
"""Fails when a site's composer.lock declares a PHP extension that
runtime/php-extensions.txt omits.

static-php-cli reads the lock and reports the extensions its packages require.
This compares that set against the allowlist and names the packages behind
every extension the allowlist misses.
"""

import argparse
import hashlib
import json
import platform
import subprocess
import sys
import tarfile
import tempfile
import urllib.request
from pathlib import Path

SPC_VERSION = "2.8.5"
# Published digests from the static-php-cli release of the pinned version.
SPC_ARCHIVES = {
    "x86_64": ("spc-linux-x86_64.tar.gz", "523ba4279c54c7a377156c0dd3a36adf92ee64b01e9a7f5e9e2ec084b8e458e5"),
    "aarch64": ("spc-linux-aarch64.tar.gz", "675a3840dcdc4ed041fe20eaa54310ce019a9984c1c03951df9ec66df5795213"),
}
# spc reports date, and PHP compiles it whatever the build selects, so it
# cannot appear in PHP_EXTENSIONS.
ALWAYS_COMPILED = frozenset({"date"})

ROOT = Path(__file__).resolve().parent.parent
ALLOWLIST = ROOT / "runtime" / "php-extensions.txt"


def allowlist() -> set[str]:
    names = set()
    for line in ALLOWLIST.read_text().splitlines():
        name = line.split("#", 1)[0].strip()
        if name:
            names.add(name)
    return names


def fetch_spc(work: Path) -> Path:
    machine = platform.machine()
    if machine not in SPC_ARCHIVES:
        sys.exit(f"No pinned spc build for {machine}. Pass --spc with a binary.")
    archive_name, digest = SPC_ARCHIVES[machine]
    url = f"https://github.com/crazywhalecc/static-php-cli/releases/download/{SPC_VERSION}/{archive_name}"
    archive = work / archive_name
    with urllib.request.urlopen(url) as response:
        archive.write_bytes(response.read())
    found = hashlib.sha256(archive.read_bytes()).hexdigest()
    if found != digest:
        sys.exit(f"{archive_name} has checksum {found}, expected {digest}")
    with tarfile.open(archive) as tar:
        tar.extract("spc", work, filter="data")
    binary = work / "spc"
    binary.chmod(0o755)
    return binary


def declared(spc: Path, work: Path, project: Path) -> list[str]:
    # spc writes a log directory into its working directory.
    result = subprocess.run(
        [str(spc), "dump-extensions", str(project), "--no-dev", "--format=json"],
        capture_output=True, text=True, cwd=work,
    )
    if result.returncode != 0:
        sys.exit(f"spc dump-extensions failed:\n{result.stderr}")
    # The command prints its own banner above the JSON array.
    body = result.stdout[result.stdout.index("["):]
    return json.loads(body)


def declaring_packages(extension: str, project: Path) -> list[str]:
    lock = json.loads((project / "composer.lock").read_text())
    key = f"ext-{extension}"
    names = []
    if key in lock.get("platform", {}):
        names.append(str(project / "composer.json"))
    names.extend(p["name"] for p in lock["packages"] if key in p.get("require", {}))
    return names


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("site", type=Path, help="the site's composer project directory")
    parser.add_argument("--spc", type=Path, help="an spc binary to use instead of downloading one")
    arguments = parser.parse_args()

    with tempfile.TemporaryDirectory() as directory:
        work = Path(directory)
        extensions = declared(arguments.spc or fetch_spc(work), work, arguments.site.resolve())

    missing = sorted(set(extensions) - allowlist() - ALWAYS_COMPILED)
    if missing:
        print(f"{ALLOWLIST.relative_to(ROOT)} omits extensions the lock declares:", file=sys.stderr)
        for extension in missing:
            print(f"  {extension}: {', '.join(declaring_packages(extension, arguments.site))}", file=sys.stderr)
        return 1
    print(f"{len(extensions)} declared extensions, all in {ALLOWLIST.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
