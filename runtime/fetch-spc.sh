#!/usr/bin/env bash
set -euo pipefail

# Checks out static-php-cli at the commit builder-inputs.sh pins, into DIRECTORY.
# Its composer.lock pins the dependencies a caller installs after.
#
# Usage: fetch-spc.sh DIRECTORY

usage='Usage: fetch-spc.sh DIRECTORY'
directory=${1:?$usage}
. "$(dirname -- "${BASH_SOURCE[0]}")/builder-inputs.sh"

rm -rf "$directory"
git init --quiet "$directory"
git -C "$directory" fetch --quiet --depth 1 https://github.com/crazywhalecc/static-php-cli.git "$spc_commit"
git -C "$directory" -c advice.detachedHead=false checkout --quiet FETCH_HEAD
head=$(git -C "$directory" rev-parse HEAD)
if [ "$head" != "$spc_commit" ]; then
    printf 'static-php-cli resolved to %s, expected %s\n' "$head" "$spc_commit" >&2
    exit 1
fi
