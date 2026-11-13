#!/usr/bin/env bash
set -euo pipefail

# Prints the names in FILE as the comma-separated list static-php-cli takes.

usage='Usage: extensions-list.sh FILE'
# The trailing - names stdin, which the BSD paste on macOS requires.
sed 's/#.*//; s/[[:space:]]//g' "${1:?$usage}" | grep . | paste -s -d , -
