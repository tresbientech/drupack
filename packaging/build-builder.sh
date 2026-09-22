#!/usr/bin/env bash
set -euo pipefail

# Builds one FrankenPHP static-builder image carrying the extensions
# packaging/php-extensions.txt names. PHP_EXTENSIONS takes effect while the
# image is built, so a published image cannot carry a narrowed PHP.
#
# Usage: build-builder.sh musl|gnu TAG [WORK_DIRECTORY]

usage='Usage: build-builder.sh musl|gnu TAG [WORK_DIRECTORY]'
libc=${1:?$usage}
tag=${2:?$usage}
work=${3:-$(mktemp -d)}
repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

frankenphp_version=1.12.7
frankenphp_commit=a765b086f5cc56f6b7753117367d56e1b0da948d
php_version=8.5.10
# watcher serves FrankenPHP and the nghttp trio serves Caddy, so no extension
# pulls them. libavif gives gd its AVIF support.
extension_libs=libavif,nghttp2,nghttp3,ngtcp2,watcher

case "$libc" in
    musl|gnu) ;;
    *) printf 'Unsupported libc: %s\n' "$libc" >&2; exit 1 ;;
esac
case "$(uname -m)" in
    x86_64) platform=linux/amd64 ;;
    aarch64) platform=linux/arm64 ;;
    *) printf 'Unsupported architecture: %s\n' "$(uname -m)" >&2; exit 1 ;;
esac

extensions=$(bash "$repository/packaging/extensions-list.sh" "$repository/packaging/php-extensions.txt")

source=$work/frankenphp
rm -rf "$source"
git -c advice.detachedHead=false clone --quiet --depth 1 --branch "v$frankenphp_version" \
    https://github.com/php/frankenphp.git "$source"
# A tag can move, so the checkout must match the pinned commit.
head=$(git -C "$source" rev-parse HEAD)
if [ "$head" != "$frankenphp_commit" ]; then
    printf 'FrankenPHP v%s resolved to %s, expected %s\n' "$frankenphp_version" "$head" "$frankenphp_commit" >&2
    exit 1
fi

target=static-builder-$libc
# bake reads GITHUB_TOKEN for the secret its build-static.sh run expects, and
# builds both architectures unless one is named.
cd "$source"
VERSION=$frankenphp_version docker buildx bake --load \
    --set "$target.platform=$platform" \
    --set "$target.tags=$tag" \
    --set "$target.args.PHP_VERSION=$php_version" \
    --set "$target.args.PHP_EXTENSIONS=$extensions" \
    --set "$target.args.PHP_EXTENSION_LIBS=$extension_libs" \
    "$target"
