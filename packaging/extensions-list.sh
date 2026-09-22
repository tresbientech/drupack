#!/usr/bin/env bash
set -euo pipefail

# Prints an allowlist as the comma-separated list static-php-cli takes. The
# builder image build, the macOS build and the runtime link all read
# packaging/php-extensions.txt through here, from paths of their own.

usage='Usage: extensions-list.sh ALLOWLIST'
sed 's/#.*//; s/[[:space:]]//g' "${1:?$usage}" | grep . | paste -sd,
