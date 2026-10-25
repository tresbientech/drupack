#!/usr/bin/env bash

# build-builder.sh compiles the image from these values and builder-tag.sh
# digests them, so a change reaches the image and its registry tag together.

frankenphp_version=1.13.3
frankenphp_commit=ae3d5b949d0963111bd895ffdd730e63c120665c
php_version=8.5.12
# static-php-cli v3 has no tagged release, so a commit of its v3 branch is the pin.
spc_commit=d7f1a88dad6d78fefa76b249b05dab1ddcff364b
# A caller may merge a site's additions into another file.
extensions_file=${EXTENSIONS_FILE:-$(dirname -- "${BASH_SOURCE[0]}")/php-extensions.txt}
