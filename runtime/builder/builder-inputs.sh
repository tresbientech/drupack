#!/usr/bin/env bash

# build-builder.sh compiles the image from these values and builder-tag.sh
# digests them, so a change reaches the image and its registry tag together.

frankenphp_version=1.13.5
frankenphp_commit=a3663e9e2cc4c258018a28ee068e50cc12f3ed2d
php_version=8.5.12
# static-php-cli v3 has no tagged release, so a commit of its v3 branch is the pin.
spc_commit=5e913373a08c7c6cf23e45e0b3c2e2caac23af6d
# FrankenPHP's static-builder.Dockerfile starts from packages-builder-rhel-8:latest.
# Its images published after 2026-11-08 link libstdc++ dynamically, and ICU's
# build tools then fail against RHEL 8's libstdc++.
toolchain_base=ghcr.io/static-php/packages-builder-rhel-8@sha256:efabc1c41e99075282bf8c7bf5c58dc355d8575dfc5915c588475bda944f96b3
# A caller may merge a site's additions into another file.
extensions_file=${EXTENSIONS_FILE:-$(dirname -- "${BASH_SOURCE[0]}")/../extensions/php-extensions.txt}
