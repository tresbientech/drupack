#!/usr/bin/env python3
"""Prints the version a release takes. With no argument, the highest version tag
with its last number raised by one, 1.0.0-alpha2 to 1.0.0-alpha3, 1.0.0 to 1.0.1.
With one, that version, once it has a tag's form and sorts above every tag.

    python3 build/next-version.py
    python3 build/next-version.py 1.0.0-beta1
"""

import re
import subprocess
import sys

from upstream import release_key

# The forms release_key orders, without the v prefix and the capitals it also reads.
TAG = re.compile(r"\d+\.\d+\.\d+(-(alpha|beta|rc)\d+)?")


def next_version(tags):
    highest = max(tags, key=release_key)
    return re.sub(r"\d+$", lambda number: str(int(number[0]) + 1), highest)


def named_version(version, tags):
    # The version arrives from a workflow dispatch input.
    if TAG.fullmatch(version) is None:
        sys.exit(f"{version!r} is not a version tag such as 1.0.0 or 1.0.0-beta1")
    highest = max(tags, key=release_key)
    if release_key(version) <= release_key(highest):
        sys.exit(f"{version} does not sort above {highest}, the highest tag")
    return version


def main():
    tags = subprocess.run(["git", "tag", "--list"], check=True, capture_output=True, text=True).stdout.split()
    version = named_version(sys.argv[1], tags) if len(sys.argv) > 1 else next_version(tags)
    sys.stdout.write(version + "\n")


if __name__ == "__main__":
    main()
