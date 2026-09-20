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
    discover_argv = ["conformance", "discover", "-s", str(case_dir), "-p", "*_cases.py", "-v", *rest]
    if os.environ.get("DRUPACK_CACHE_DIR"):
        # Set already: this is the offline case's inner run, sharing its outer run's cache
        # mount rather than unpacking a second copy inside the container.
        program = unittest.main(module=None, argv=discover_argv, exit=False)
    else:
        cache_dir = tempfile.mkdtemp(prefix="drupack-cache-")
        os.environ["DRUPACK_CACHE_DIR"] = cache_dir
        try:
            program = unittest.main(module=None, argv=discover_argv, exit=False)
        finally:
            # A plain TemporaryDirectory cleanup would raise on a handle Windows has not yet
            # released, turning a green run red; harness.remove_cache_dir retries instead.
            harness.remove_cache_dir(cache_dir, harness.WAITS["cache_cleanup"].seconds)
    return 0 if program.result.wasSuccessful() else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
