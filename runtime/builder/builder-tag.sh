#!/usr/bin/env bash
set -euo pipefail

# A run whose inputs are unchanged finds the image already published and
# skips the PHP compile.
#
# Usage: builder-tag.sh musl|gnu [MACHINE]
# MACHINE is the uname -m of the host the image runs on, this host's when unset.
# EXTENSIONS_FILE names the extension list, runtime/extensions/php-extensions.txt by default.

usage='Usage: builder-tag.sh musl|gnu [MACHINE]'
libc=${1:?$usage}
machine=${2:-$(uname -m)}
directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
. "$directory/builder-inputs.sh"

# extensions-list.sh drops the comments, so a comment edit keeps the tag.
extensions=$(bash "$directory/../extensions/extensions-list.sh" "$extensions_file")
extension_libs=$(bash "$directory/../extensions/extensions-list.sh" "$directory/../extensions/php-extension-libs.txt")
# An image is built for the architecture it runs on, and both share this tag
# namespace, so the machine type belongs in the digest.
# The scripts that compile the image change what it holds. The image holds the library
# files library-versions.txt records, so a move of that file builds a new image.
recipe=$(cat "$directory/build-builder.sh" "$directory/spc-craft.sh" "$directory/fetch-spc.sh" "$directory/fetch-commit.sh" "$directory/branch-pins.txt" "$directory/fetch-branch-pins.sh" "$directory/spc-sqlite.patch" "$directory/spc-icu.patch" \
    "$directory/library-versions.txt" "$directory/check-downloads.php" | sha256sum)
printf '%s\n' "$frankenphp_commit" "$spc_commit" "$toolchain_base" "$php_version" "$extensions" "$extension_libs" \
    "$libc" "$machine" "$recipe" | sha256sum | cut -c 1-16
