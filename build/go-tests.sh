#!/usr/bin/env bash
set -euo pipefail

# The Go unit tests of every module in the repository. build/qa.sh and each
# platform job in release.yml run this script.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
for module in $(git ls-files '*/go.mod'); do
    (cd "$(dirname -- "$module")" && go test ./...)
done
