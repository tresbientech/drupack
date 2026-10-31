#!/usr/bin/env bash

# build-builder.sh compiles the image from these values and builder-tag.sh
# digests them, so a change reaches the image and its registry tag together.

frankenphp_version=1.13.4
frankenphp_commit=7f50be9d86261bf7cff9b051ab4fb572e95020ed
php_version=8.5.12
# static-php-cli v3 has no tagged release, so a commit of its v3 branch is the pin.
spc_commit=39e0b8a4e790e9f25025d0e0eb4289c4e0d1364a
# A caller may merge a site's additions into another file.
extensions_file=${EXTENSIONS_FILE:-$(dirname -- "${BASH_SOURCE[0]}")/php-extensions.txt}
