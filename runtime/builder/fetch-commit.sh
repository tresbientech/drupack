#!/usr/bin/env bash
set -euo pipefail

# Replaces DIRECTORY with a checkout of COMMIT, fetched alone from URL.
#
# Usage: fetch-commit.sh DIRECTORY URL COMMIT

usage='Usage: fetch-commit.sh DIRECTORY URL COMMIT'
directory=${1:?$usage}
url=${2:?$usage}
commit=${3:?$usage}

rm -rf "$directory"
git init --quiet "$directory"
# Git for Windows checks out CRLF by default, and a patch then applies to no file.
git -C "$directory" config core.autocrlf false
git -C "$directory" fetch --quiet --depth 1 "$url" "$commit"
git -C "$directory" checkout --quiet --detach FETCH_HEAD
# A tag's object id fetches, and its checkout lands on the commit it names.
head=$(git -C "$directory" rev-parse HEAD)
if [ "$head" != "$commit" ]; then
    printf '%s resolved to %s, expected %s\n' "$url" "$head" "$commit" >&2
    exit 1
fi
