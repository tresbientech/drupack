#!/usr/bin/env bash

# What a builder image is built from, beside the two extension lists.
# build-builder.sh compiles the image from these values and builder-tag.sh
# digests them, so a change reaches the image and its registry tag together.

frankenphp_version=1.13.1
frankenphp_commit=99d4e119ca2e5a90a69d8dbee5d5c9a8016416c5
php_version=8.5.11
# The extension list a builder compiles: the engine's own unless a caller merges a site's additions into another file.
extensions_file=${EXTENSIONS_FILE:-$(dirname -- "${BASH_SOURCE[0]}")/php-extensions.txt}
