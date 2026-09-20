"""Entry point: python3 tests/conformance EXECUTABLE RESULTS [unittest arguments].

Discovers every *_cases.py module under this directory and hands the rest of argv to
unittest, so a caller's own -k, -v or -q still applies on top of this suite's default.
"""

import os
import sys
import tempfile
import unittest
from pathlib import Path

import harness

USAGE = "Usage: python3 tests/conformance EXECUTABLE RESULTS [unittest arguments]"


def main(argv):
    if len(argv) < 2:
        print(USAGE, file=sys.stderr)
        return 2
    binary, results, *rest = argv
    harness.BINARY = Path(binary).resolve()
    harness.RESULTS = Path(results).resolve()
    harness.RESULTS.mkdir(parents=True, exist_ok=True)
    case_dir = Path(__file__).resolve().parent
    with tempfile.TemporaryDirectory(prefix="drupack-cache-") as cache_dir:
        os.environ["DRUPACK_CACHE_DIR"] = cache_dir
        program = unittest.main(
            module=None,
            argv=["conformance", "discover", "-s", str(case_dir), "-p", "*_cases.py", "-v", *rest],
            exit=False,
        )
    return 0 if program.result.wasSuccessful() else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
