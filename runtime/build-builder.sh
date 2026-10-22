#!/usr/bin/env bash
set -euo pipefail

# PHP_EXTENSIONS takes effect while the builder image is made, so a published
# image cannot carry a narrowed PHP.
#
# Usage: build-builder.sh musl|gnu TAG [WORK_DIRECTORY]
# EXTENSIONS_FILE names the extension list, runtime/php-extensions.txt by default.

usage='Usage: build-builder.sh musl|gnu TAG [WORK_DIRECTORY]'
libc=${1:?$usage}
tag=${2:?$usage}
work=${3:-$(mktemp -d)}
repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

. "$repository/runtime/builder-inputs.sh"
extension_libs=$(bash "$repository/runtime/extensions-list.sh" "$repository/runtime/php-extension-libs.txt")

case "$libc" in
    musl|gnu) ;;
    *) printf 'Unsupported libc: %s\n' "$libc" >&2; exit 1 ;;
esac
case "$(uname -m)" in
    x86_64) platform=linux/amd64 ;;
    aarch64) platform=linux/arm64 ;;
    *) printf 'Unsupported architecture: %s\n' "$(uname -m)" >&2; exit 1 ;;
esac

extensions=$(bash "$repository/runtime/extensions-list.sh" "$extensions_file")

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

# The toolchain image copies the checkout, so the build runs the pinned spc.
bash "$repository/runtime/fetch-spc.sh" "$source/static-php-cli"
cp "$repository/runtime/spc-craft.sh" "$source/spc-craft.sh"

target=static-builder-$libc
container=drupack-builder-$libc-$$
# The image holds only the toolchain. build-static.sh compiles PHP when the
# container runs, and the stopped container, committed, is the builder image.
# bake would read the workflow's GO_VERSION, so it is unset for the pinned
# FrankenPHP's own.
cd "$source"
env -u GO_VERSION VERSION=$frankenphp_version docker buildx bake --load \
    --set "$target.platform=$platform" \
    --set "$target.tags=$tag-toolchain" \
    "$target"
# build-static.sh reads GITHUB_TOKEN to raise its GitHub API rate limit. The
# token enters through a mounted file, since docker commit keeps every -e value
# in the image it writes, and the release publishes that image.
token=$(mktemp)
trap 'rm -f "$token"' EXIT
printf '%s' "${GITHUB_TOKEN:-}" >"$token"
# zig compiles a native target for the build host's CPU, so musl names its
# architecture's baseline. docker commit keeps it for embed.sh.
target=()
if [ "$libc" = musl ]; then
    target=(-e "SPC_TARGET=$(uname -m)-linux-musl")
fi
# zig, the musl linker, opens a file per object while it links.
docker run --name "$container" --ulimit nofile=8192:8192 \
    -v "$token:/run/github-token:ro" \
    "${target[@]}" \
    -e "PHP_VERSION=$php_version" \
    -e "PHP_EXTENSIONS=$extensions" \
    -e "PHP_EXTENSION_LIBS=$extension_libs" \
    "$tag-toolchain" \
    ./spc-craft.sh
docker commit "$container" "$tag"
docker rm "$container"
docker image rm "$tag-toolchain"
