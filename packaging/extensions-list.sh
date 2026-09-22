#!/usr/bin/env bash
set -euo pipefail

# Prints one name per line as the comma-separated list static-php-cli takes.
# Every builder reads packaging/php-extensions.txt through here, and the two
# that compile PHP read packaging/php-extension-libs.txt the same way.

usage='Usage: extensions-list.sh FILE'
# The trailing - names stdin, which the BSD paste on macOS requires.
sed 's/#.*//; s/[[:space:]]//g' "${1:?$usage}" | grep . | paste -s -d , -
