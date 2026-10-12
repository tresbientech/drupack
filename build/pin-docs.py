#!/usr/bin/env python3
"""Rewrites the Drupack version the site-building guide names to the one given.

    python3 build/pin-docs.py 1.0.0-beta1
"""

import re
import sys

from upstream import ROOT

GUIDE = "docs/build-your-site.md"
# The workflow ref. Every other pinned line of the guide repeats its version.
PIN = r"build\.yml@(\S+)$"


def pin(text, version):
    current = re.search(PIN, text, re.MULTILINE)
    if current is None:
        sys.exit(f"{GUIDE}: no line matches {PIN}")
    return text.replace(current[1], version)


def main():
    guide = ROOT / GUIDE
    guide.write_text(pin(guide.read_text(), sys.argv[1]))


if __name__ == "__main__":
    main()
