#!/usr/bin/env bash
set -euo pipefail

# Checks out static-php-cli at the commit builder-inputs.sh pins, into DIRECTORY.
# Its composer.lock pins the dependencies a caller installs after.
#
# Usage: fetch-spc.sh DIRECTORY

usage='Usage: fetch-spc.sh DIRECTORY'
directory=${1:?$usage}
builder=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
. "$builder/builder-inputs.sh"

rm -rf "$directory"
git init --quiet "$directory"
# Git for Windows checks out CRLF by default, and the patches below then apply to no file.
git -C "$directory" config core.autocrlf false
git -C "$directory" fetch --quiet --depth 1 https://github.com/crazywhalecc/static-php-cli.git "$spc_commit"
git -C "$directory" -c advice.detachedHead=false checkout --quiet FETCH_HEAD
head=$(git -C "$directory" rev-parse HEAD)
if [ "$head" != "$spc_commit" ]; then
    printf 'static-php-cli resolved to %s, expected %s\n' "$head" "$spc_commit" >&2
    exit 1
fi
# static-php-cli pins SQLite 3.45.2, and its recipe passes configure flags newer
# SQLite releases reject. The patch names the SQLite release the Linux and macOS
# runtimes link, which build/release/upstream.py moves.
git -C "$directory" apply "$builder/spc-sqlite.patch"
# ICU names its source file icu4c-VERSION-sources.tgz since 78, and static-php-cli
# looks for the older name, which stops at 77.1.
git -C "$directory" apply "$builder/spc-icu.patch"
