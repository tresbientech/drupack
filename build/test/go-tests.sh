#!/usr/bin/env bash
set -euo pipefail

# The Go unit tests of every module in the repository under the race detector,
# then golangci-lint for each system a release targets. build/test/qa.sh and each
# platform job in release.yml run this script.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
# The linter runs on this host for every GOOS, so it is built once for the host.
tools=$(mktemp -d)
trap 'rm -rf -- "$tools"' EXIT
GOBIN=$tools go install github.com/golangci/golangci-lint/v2/cmd/golangci-lint@v2.15.0
for module in $(git ls-files '*/go.mod'); do
    (
        cd "$(dirname -- "$module")"
        go test -race ./...
        for goos in linux darwin windows; do
            GOOS=$goos "$tools/golangci-lint" run ./...
        done
    )
done
