#!/usr/bin/env python3
"""Fails when a site's composer.lock declares a PHP extension that neither
runtime/extensions/php-extensions.txt nor the site's drupack.yml extensions name.

It reads the ext-* requirements of the lock's packages and of its platform block,
and names the packages behind every extension the merged list misses.
"""

import argparse
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent.parent
ALLOWLIST = ROOT / "runtime" / "extensions" / "php-extensions.txt"
# PHP compiles these whatever a build selects, so no list names them.
BUILT_IN = {"core", "date", "hash", "json", "lexbor", "pcre", "random", "reflection", "spl", "standard", "uri"}
# Composer packages require OPcache under its Zend names.
ALIASES = {"zend-opcache": "opcache", "zendopcache": "opcache"}


def allowlist() -> set[str]:
    names = set()
    for line in ALLOWLIST.read_text().splitlines():
        name = line.split("#", 1)[0].strip()
        if name:
            names.add(name)
    return names


def requirements(lock: dict) -> dict[str, list[str]]:
    """Each extension the lock requires, with the packages that require it. The
    platform block counts as composer.json."""
    owners = [("composer.json", lock.get("platform", {}))]
    owners += [(package["name"], package.get("require", {})) for package in lock["packages"]]
    declared: dict[str, list[str]] = {}
    for owner, require in owners:
        for key in require:
            if not key.lower().startswith("ext-"):
                continue
            name = key.lower()[len("ext-"):]
            name = ALIASES.get(name, name)
            if name not in BUILT_IN:
                declared.setdefault(name, []).append(owner)
    return declared


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("site", type=Path, help="the site's composer project directory")
    parser.add_argument("--extensions", default="", help="the site's additions, comma-separated")
    arguments = parser.parse_args()

    declared = requirements(json.loads((arguments.site / "composer.lock").read_text()))
    additions = {name for name in arguments.extensions.split(",") if name}
    missing = sorted(set(declared) - allowlist() - additions)
    if missing:
        print(f"{ALLOWLIST.relative_to(ROOT)} and drupack.yml's extensions omit extensions the lock declares:",
              file=sys.stderr)
        for extension in missing:
            print(f"  {extension}: {', '.join(declared[extension])}", file=sys.stderr)
        return 1
    print(f"{len(declared)} declared extensions, all in {ALLOWLIST.relative_to(ROOT)} or drupack.yml's extensions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
