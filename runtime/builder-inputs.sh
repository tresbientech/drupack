#!/usr/bin/env bash

# What a builder image is built from, beside the two extension lists.
# build-builder.sh compiles the image from these values and builder-tag.sh
# digests them, so a change reaches the image and its registry tag together.

frankenphp_version=1.12.7
frankenphp_commit=a765b086f5cc56f6b7753117367d56e1b0da948d
php_version=8.5.10
# The extension list a builder compiles: the engine's own unless a caller merges a site's additions into another file.
extensions_file=${EXTENSIONS_FILE:-$(dirname -- "${BASH_SOURCE[0]}")/php-extensions.txt}
