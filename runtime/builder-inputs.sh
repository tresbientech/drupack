#!/usr/bin/env bash

# build-builder.sh compiles the image from these values and builder-tag.sh
# digests them, so a change reaches the image and its registry tag together.

frankenphp_version=1.13.5
frankenphp_commit=a3663e9e2cc4c258018a28ee068e50cc12f3ed2d
php_version=8.5.12
# static-php-cli v3 has no tagged release, so a commit of its v3 branch is the pin.
spc_commit=5e913373a08c7c6cf23e45e0b3c2e2caac23af6d
# A caller may merge a site's additions into another file.
extensions_file=${EXTENSIONS_FILE:-$(dirname -- "${BASH_SOURCE[0]}")/php-extensions.txt}
