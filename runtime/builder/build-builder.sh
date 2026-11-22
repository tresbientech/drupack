#!/usr/bin/env bash
set -euo pipefail

# PHP_EXTENSIONS takes effect while the builder image is made, so a published
# image cannot carry a narrowed PHP.
#
# Usage: build-builder.sh musl|gnu TAG [WORK_DIRECTORY]
# EXTENSIONS_FILE names the extension list, runtime/extensions/php-extensions.txt by default.

usage='Usage: build-builder.sh musl|gnu TAG [WORK_DIRECTORY]'
libc=${1:?$usage}
tag=${2:?$usage}
work=${3:-$(mktemp -d)}
directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

. "$directory/builder-inputs.sh"
extension_libs=$(bash "$directory/../extensions/extensions-list.sh" "$directory/../extensions/php-extension-libs.txt")

case "$libc" in
    musl|gnu) ;;
    *) printf 'Unsupported libc: %s\n' "$libc" >&2; exit 1 ;;
esac
case "$(uname -m)" in
    x86_64) platform=linux/amd64 ;;
    aarch64) platform=linux/arm64 ;;
    *) printf 'Unsupported architecture: %s\n' "$(uname -m)" >&2; exit 1 ;;
esac

extensions=$(bash "$directory/../extensions/extensions-list.sh" "$extensions_file")

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

# FrankenPHP's craft.yml can fetch extensions from git branches, for a PHP the
# build does not compile. Without the list, craft builds the sources
# library-versions.txt records.
awk '/^  custom-git:$/ {skip = 1; next} skip && /^    - / {next} {skip = 0; print}' \
    "$source/craft.yml" >"$work/craft.yml"
mv "$work/craft.yml" "$source/craft.yml"

# The toolchain image copies the checkout, so the build runs the pinned spc.
bash "$directory/fetch-spc.sh" "$source/static-php-cli"
cp "$directory/spc-craft.sh" "$source/spc-craft.sh"
cp "$directory/check-downloads.php" "$directory/library-versions.txt" "$source/"
# build-static.sh writes craft.yml's custom-local list, which also reaches spc build.
# The pin checkouts enter the compile container alone, so neither image holds them.
pins=$(bash "$directory/fetch-branch-pins.sh" linux "$work/branch-pins")
custom=$'\t\t''line="  custom-local: [\"frankenphp:${CURRENT_DIR}\"]"'
grep -qxF "$custom" "$source/build-static.sh" || { printf 'build-static.sh has no line %s\n' "$custom" >&2; exit 1; }
entries=$(for name in $(cut -d: -f1 <<<"$pins"); do printf ', \\"%s:${CURRENT_DIR}/branch-pins/%s\\"' "$name" "$name"; done)
script=$(<"$source/build-static.sh")
printf '%s\n' "${script/"$custom"/"${custom%']"'}$entries]\""}" >"$source/build-static.sh"
# A FrankenPHP bump that changes this line stops here instead of dropping the pin.
dockerfile=$source/static-builder.Dockerfile
base_line='FROM ghcr.io/static-php/packages-builder-rhel-8:latest'
if ! grep -qxF "$base_line" "$dockerfile"; then
    printf '%s has no line "%s"\n' "$dockerfile" "$base_line" >&2
    exit 1
fi
sed -i "s#^$base_line\$#FROM $toolchain_base#" "$dockerfile"

target=static-builder-$libc
container=drupack-builder-$libc-$$
# The toolchain image holds no PHP: build-static.sh compiles it when the
# container runs. bake would read the workflow's GO_VERSION, so it is unset for
# the pinned FrankenPHP's own.
cd "$source"
env -u GO_VERSION VERSION=$frankenphp_version docker buildx bake --load \
    --set "$target.platform=$platform" \
    --set "$target.tags=$tag-toolchain" \
    "$target"
# The builder image is the toolchain plus what embed.sh reads from the compile:
# PHP's libraries, headers and php-config, spc, and the Go toolchain without
# spc's module cache. Sources, downloads and build caches stay out.
paths=(
    go/src/app/dist/buildroot/bin
    go/src/app/dist/buildroot/include
    go/src/app/dist/buildroot/lib
    go/src/app/dist/spc
    go/src/app/static-php-cli
    opt/spc-pkgroot/go-xcaddy/bin
    opt/spc-pkgroot/go-xcaddy/lib
    opt/spc-pkgroot/go-xcaddy/pkg
    opt/spc-pkgroot/go-xcaddy/src
    opt/spc-pkgroot/go-xcaddy/go.env
    opt/spc-pkgroot/go-xcaddy/VERSION
)
target=()
image_env=
# zig compiles a native target for the build host's CPU, so musl names its
# architecture's baseline, for the compile and for embed.sh. zig links musl.
if [ "$libc" = musl ]; then
    spc_target=$(uname -m)-linux-musl
    target=(-e "SPC_TARGET=$spc_target")
    image_env="ENV SPC_TARGET=$spc_target"
    paths+=(opt/spc-pkgroot/zig)
fi
# build-static.sh reads GITHUB_TOKEN to raise its GitHub API rate limit. The
# stopped container keeps it in its config, so the container goes on any exit,
# after its static-php-cli logs are copied to WORK_DIRECTORY/spc-log. A run that
# stops before static-php-cli starts has no log folder.
trap 'docker cp "$container:/go/src/app/log" "$work/spc-log" 2>/dev/null || :; docker rm -f "$container" >/dev/null' EXIT
# zig, the musl linker, opens a file per object while it links.
docker create --name "$container" --ulimit nofile=8192:8192 \
    -e GITHUB_TOKEN \
    "${target[@]}" \
    -e "PHP_VERSION=$php_version" \
    -e "PHP_EXTENSIONS=$extensions" \
    -e "PHP_EXTENSION_LIBS=$extension_libs" \
    -e "CHECK_DOWNLOADS=${EXTENSIONS_FILE:+--report}" \
    "$tag-toolchain" \
    ./spc-craft.sh
docker cp "$work/branch-pins" "$container:/go/src/app/branch-pins"
docker start --attach "$container"
# docker cp nests a directory inside an existing one, so the stage starts empty.
stage=$work/image
rm -rf "$stage"
for path in "${paths[@]}"; do
    mkdir -p "$stage/$(dirname -- "$path")"
    docker cp "$container:/$path" "$stage/$path"
done
# COPY merges into what the toolchain holds. The base ships an older Go in
# go-xcaddy, and the compile's Go replaces it whole.
docker build --tag "$tag" --file - "$stage" <<EOF
FROM $tag-toolchain
RUN rm -rf /opt/spc-pkgroot/go-xcaddy
COPY . /
$image_env
EOF
docker rm "$container"
trap - EXIT
docker image rm "$tag-toolchain"
rm -rf "$stage"
