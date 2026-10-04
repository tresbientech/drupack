#!/usr/bin/env python3
"""Prints the version an automatic release takes: the highest version tag with
its last number raised by one, 1.0.0-alpha2 to 1.0.0-alpha3, 1.0.0 to 1.0.1.

    python3 build/next-version.py
"""

import re
import subprocess
import sys

from upstream import release_key


def next_version(tags):
    highest = max((tag for tag in tags if release_key(tag) is not None), key=release_key)
    return re.sub(r"\d+$", lambda number: str(int(number[0]) + 1), highest)


def main():
    tags = subprocess.run(["git", "tag", "--list"], check=True, capture_output=True, text=True).stdout.split()
    sys.stdout.write(next_version(tags) + "\n")


if __name__ == "__main__":
    main()
