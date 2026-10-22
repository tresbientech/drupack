#!/usr/bin/env python3
"""Fails when a site's composer.lock declares a PHP extension that neither
runtime/php-extensions.txt nor the site's drupack.yml extensions name.

static-php-cli reads the lock and reports the extensions its packages require.
This compares that set against the merged list and names the packages behind
every extension it misses.
"""

import argparse
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ALLOWLIST = ROOT / "runtime" / "php-extensions.txt"


def allowlist() -> set[str]:
    names = set()
    for line in ALLOWLIST.read_text().splitlines():
        name = line.split("#", 1)[0].strip()
        if name:
            names.add(name)
    return names


def declared(php: Path, spc: Path, work: Path, project: Path) -> list[str]:
    # spc writes a log directory into its working directory. The runtime reads
    # php.ini beside itself only through PHPRC.
    result = subprocess.run(
        [str(php), "php-cli", str(spc / "bin" / "spc"), "dump-extensions", str(project), "--no-dev", "--format=json"],
        capture_output=True, text=True, cwd=work, env={**os.environ, "PHPRC": str(php.parent)},
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
    parser.add_argument("--php", type=Path, default=os.environ.get("DRUPACK_PHP"),
                        help="the Drupack runtime that runs spc, DRUPACK_PHP by default")
    parser.add_argument("--spc", type=Path, default=os.environ.get("DRUPACK_SPC"),
                        help="a static-php-cli checkout with its dependencies installed, DRUPACK_SPC by default")
    parser.add_argument("--extensions", default="", help="the site's additions, comma-separated")
    arguments = parser.parse_args()
    if arguments.php is None or arguments.spc is None:
        parser.error("--php and --spc are required outside the job image")

    with tempfile.TemporaryDirectory() as directory:
        extensions = declared(arguments.php, arguments.spc, Path(directory), arguments.site.resolve())

    additions = {name for name in arguments.extensions.split(",") if name}
    missing = sorted(set(extensions) - allowlist() - additions)
    if missing:
        print(f"{ALLOWLIST.relative_to(ROOT)} and drupack.yml's extensions omit extensions the lock declares:",
              file=sys.stderr)
        for extension in missing:
            print(f"  {extension}: {', '.join(declaring_packages(extension, arguments.site))}", file=sys.stderr)
        return 1
    print(f"{len(extensions)} declared extensions, all in {ALLOWLIST.relative_to(ROOT)} or drupack.yml's extensions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
