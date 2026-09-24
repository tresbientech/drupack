#!/usr/bin/env bash
set -euo pipefail

# Prints the registry tag for a builder image: a digest over everything the
# image carries. A run whose inputs are unchanged finds the image already
# published and skips the PHP compile.
#
# Usage: builder-tag.sh musl|gnu
# EXTENSIONS_FILE names the extension list, runtime/php-extensions.txt by default.

usage='Usage: builder-tag.sh musl|gnu'
libc=${1:?$usage}
directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
. "$directory/builder-inputs.sh"

# extensions-list.sh drops the comments, so a comment edit keeps the tag.
extensions=$(bash "$directory/extensions-list.sh" "${EXTENSIONS_FILE:-$directory/php-extensions.txt}")
extension_libs=$(bash "$directory/extensions-list.sh" "$directory/php-extension-libs.txt")
# An image is built for the architecture it runs on, and both share this tag
# namespace, so the machine type belongs in the digest.
printf '%s\n' "$frankenphp_commit" "$php_version" "$extensions" "$extension_libs" \
    "$libc" "$(uname -m)" | sha256sum | cut -c 1-16
