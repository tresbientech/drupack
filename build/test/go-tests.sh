#!/usr/bin/env bash
set -euo pipefail

# The Go unit tests of every module in the repository under the race detector,
# then golangci-lint for each system a release targets. With the argument test,
# the unit tests alone: lint output is the same on every host, so one job lints.
# Usage: build/test/go-tests.sh [test]
case ${1:-} in
    '' | test) ;;
    *) echo 'Usage: build/test/go-tests.sh [test]' >&2; exit 2 ;;
esac
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
# The linter builds with the toolchain the launcher module names, which a host Go may predate.
if [ -z "${GOTOOLCHAIN:-}" ]; then
    GOTOOLCHAIN=$(awk '$1 == "toolchain" { print $2; found = 1 } $1 == "go" { version = "go" $2 } END { if (!found) print version }' launcher/go.mod)
    export GOTOOLCHAIN
fi
lint=
if [ -z "${1:-}" ]; then
    # The linter runs on this host for every GOOS, so it is built once for the host.
    lint=$(mktemp -d)
    trap 'rm -rf -- "$lint"' EXIT
    GOBIN=$lint go install github.com/golangci/golangci-lint/v2/cmd/golangci-lint@v2.15.0
fi
for module in $(git ls-files '*/go.mod'); do
    (
        cd "$(dirname -- "$module")"
        go test -race ./...
        if [ -n "$lint" ]; then
            for goos in linux darwin windows; do
                GOOS=$goos "$lint/golangci-lint" run ./...
            done
        fi
    )
done
