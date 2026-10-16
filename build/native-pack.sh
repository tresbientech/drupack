#!/usr/bin/env bash
# Packs a site into a macOS or Windows executable from the runtime file of a
# Drupack release.
#
# Usage: native-pack.sh --base-url URL --target TARGET --payload DIRECTORY
#            --output FILE --site-version VERSION
#
# URL holds the release's files and ends in the release's version. TARGET is
# macos-amd64, macos-arm64 or windows-amd64. DIRECTORY holds the files
# `drupack-build --native` exported. The release's checksums.txt vouches for the
# runtime file. A macOS runner runs this with bash 3.2, and the Windows runner
# runs it through Git Bash.
set -euo pipefail

fail() {
    printf '%s\n' "$*" >&2
    exit 1
}

while [ $# -gt 0 ]; do
    case $1 in
        --base-url) base_url=${2%/} ;;
        --target) target=$2 ;;
        --payload) payload=$2 ;;
        --output) output=$2 ;;
        --site-version) site_version=$2 ;;
        *) fail "unknown option $1" ;;
    esac
    shift 2
done

# Every runtime but the Windows one starts from an executable named drupack.
case $target in
    windows-*) entry=frankenphp.exe node_extension=zip ;;
    *) entry=drupack node_extension=tar.gz ;;
esac
version=${base_url##*/}
file=runtime-$version-$target.tar.zst

if command -v sha256sum >/dev/null 2>&1; then
    sha256() { sha256sum "$1" | cut -d ' ' -f 1; }
else
    sha256() { shasum -a 256 "$1" | cut -d ' ' -f 1; }
fi

engine=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
payload=$(cd -- "$payload" && pwd)
mkdir -p -- "$(dirname -- "$output")"
output=$(cd -- "$(dirname -- "$output")" && pwd)/$(basename -- "$output")

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

download() {
    curl -fsSL --retry 3 -o "$work/$1" "$base_url/$1" || fail "cannot download $1 from $base_url"
}
download checksums.txt
download "$file"

expected=$(awk -v file="$file" '$2 == file { print $1 }' "$work/checksums.txt")
[ -n "$expected" ] || fail "checksums.txt lists no $file"
actual=$(sha256 "$work/$file")
[ "$actual" = "$expected" ] || fail "$file has SHA-256 $actual, and checksums.txt lists $expected"

# Each runner's tar reads a plain archive, whatever compression it was built with.
mkdir "$work/runtime"
zstd -dc "$work/$file" | tar -C "$work/runtime" -xf -

# bash 3.2 calls an empty array unset, hence the guarded expansion below.
node=()
if [ -f "$payload/node/$target.$node_extension" ]; then
    node=(-node "$payload/node/$target.$node_extension")
fi
cd -- "$engine/launcher"
# The launcher matches the target, whatever the host runs: a Windows on Arm or an
# Apple silicon host packs amd64 too.
CGO_ENABLED=0 go run ./cmd/pack -goarch "${target##*-}" -runtime "$work/runtime" -entry "$entry" \
    -version "$version" -source "$engine/launcher" -output "$output" \
    -app "$payload/app-payload.tar" -app-checksum "$payload/app_checksum.txt" \
    -site "$payload/site.json" -site-version "$site_version" ${node[@]+"${node[@]}"}
