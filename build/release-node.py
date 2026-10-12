#!/usr/bin/env python3
"""Stops with a message while Packagist lists no drupal/drupack release for the
highest version tag. Packagist takes each version from a drupal.org release
node, which is created by hand.

    python3 build/release-node.py
"""

import json
import subprocess
import sys

from upstream import PACKAGIST_METADATA, fetch, release_key

PACKAGE = "drupal/drupack"


def unlisted(tags, listed):
    highest = max(tags, key=release_key)
    return None if highest in listed else highest


def main():
    tags = subprocess.run(["git", "tag", "--list"], check=True, capture_output=True, text=True).stdout.split()
    entries = json.loads(fetch(f"{PACKAGIST_METADATA}{PACKAGE}.json"))["packages"][PACKAGE]
    tag = unlisted(tags, [entry["version"] for entry in entries])
    if tag is not None:
        sys.exit(f"Packagist lists no {PACKAGE} {tag}. Create the drupal.org release node for {tag}.")


if __name__ == "__main__":
    main()
