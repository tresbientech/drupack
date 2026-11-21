#!/usr/bin/env bash
set -euo pipefail

# Checks out each artifact of branch-pins.txt that PLATFORM builds into DIRECTORY/NAME,
# at its COMMIT, and prints NAME:CHECKOUT, the value static-php-cli's custom-local download option takes.
#
# Usage: fetch-branch-pins.sh PLATFORM DIRECTORY

usage='Usage: fetch-branch-pins.sh PLATFORM DIRECTORY'
platform=${1:?$usage}
directory=${2:?$usage}
builder=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

mkdir -p "$directory"
while read -r name platforms url _ commit; do
    case $name in '#'*) continue ;; esac
    [ "$platforms" = all ] || [ "$platforms" = "$platform" ] || continue
    checkout=$directory/$name
    if [ "$(git -C "$checkout" rev-parse HEAD 2>/dev/null)" = "$commit" ]; then
        # static-php-cli builds a local source in place, so a kept checkout can hold a failed build.
        git -C "$checkout" reset --quiet --hard
        git -C "$checkout" clean --quiet -fdx
    else
        bash "$builder/fetch-commit.sh" "$checkout" "$url" "$commit" >&2
    fi
    printf '%s:%s\n' "$name" "$checkout"
done <"$builder/branch-pins.txt"
