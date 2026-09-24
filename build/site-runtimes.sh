#!/usr/bin/env bash
set -euo pipefail

# Compiles the Linux runtimes for a site whose drupack.yml adds PHP extensions,
# one OUTPUT/PLATFORM-LIBC directory per target, which drupack-build --runtimes
# reads. It runs from an engine checkout on a Docker host, for targets of this
# host's architecture. A builder image is tagged by builder-tag.sh over the
# merged extension list, so a rerun with the same list reuses it.
#
# Usage: build/site-runtimes.sh SITE OUTPUT

usage='Usage: build/site-runtimes.sh SITE OUTPUT'
site=$(realpath -- "${1:?$usage}")
output=${2:?$usage}
engine=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)

described=$(cd "$engine/launcher" && go run ./cmd/drupack-build --site "$site" --describe)
# Prints one site.json field, a list one item per line.
field() {
    python3 -c 'import json, sys
value = json.loads(sys.argv[1])[sys.argv[2]]
print("\n".join(value) if isinstance(value, list) else value)' "$described" "$1"
}

case "$(uname -m)" in
    x86_64) arch=amd64 ;;
    aarch64) arch=arm64 ;;
    *) printf 'Unsupported architecture: %s\n' "$(uname -m)" >&2; exit 1 ;;
esac
while read -r platform; do
    if [ "$platform" != "linux-$arch" ]; then
        printf '%s names %s, and this host compiles linux-%s only. Run it on a %s host.\n' \
            "$site/drupack.yml" "$platform" "$arch" "$platform" >&2
        exit 1
    fi
done < <(field platforms)
# The builder images name glibc gnu.
case "$(field libc)" in
    both) builders=(gnu musl) ;;
    glibc) builders=(gnu) ;;
    musl) builders=(musl) ;;
esac

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir "$work/extensions"
export EXTENSIONS_FILE=$work/extensions/php-extensions.txt
{ cat "$engine/runtime/php-extensions.txt"; field extensions; } > "$EXTENSIONS_FILE"

mkdir -p "$output"
for builder in "${builders[@]}"; do
    image=drupack-builder-$builder:$(bash "$engine/runtime/builder-tag.sh" "$builder")
    if docker image inspect "$image" > /dev/null 2>&1; then
        printf 'Reusing %s\n' "$image"
    else
        bash "$engine/runtime/build-builder.sh" "$builder" "$image" "$work/builder"
    fi
    directory=$output/linux-$arch-${builder/gnu/glibc}
    docker build --target "runtime-$builder-files" \
        --build-arg "${builder^^}_BUILDER=$image" \
        --build-arg EXTENSIONS=site-extensions --build-context "site-extensions=$work/extensions" \
        --output "type=local,dest=$work/$builder" "$engine"
    rm -rf "$directory"
    mv "$work/$builder/out" "$directory"
done
