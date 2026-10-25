#!/usr/bin/env bash
set -euo pipefail

# The Python unit tests, which need no built file. build/qa.sh and release.yml's
# Linux job both run this list.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
for file in test_environment.py test_guards.py test_app_payload.py test_release_files.py test_targets.py test_native_pack.py test_sbom.py; do
    python3 -m unittest discover -s tests/conformance -p "$file"
done
python3 -m unittest discover -s tests/upstream
