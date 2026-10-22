#!/usr/bin/env bash

# build-builder.sh compiles the image from these values and builder-tag.sh
# digests them, so a change reaches the image and its registry tag together.

frankenphp_version=1.13.2
frankenphp_commit=0ad8a403a5c2eb13aacab92fffa6abc397157d10
php_version=8.5.12
# static-php-cli v3 has no tagged release, so a commit of its v3 branch is the pin.
spc_commit=583746afa2bdbb20d5e8b60f558db5229abf51a4
# A caller may merge a site's additions into another file.
extensions_file=${EXTENSIONS_FILE:-$(dirname -- "${BASH_SOURCE[0]}")/php-extensions.txt}
