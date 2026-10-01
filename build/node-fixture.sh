#!/usr/bin/env bash
set -euo pipefail

# Writes the Node fixture to DIRECTORY: Drupacked Demo's tracked files, named
# node-demo, with no translations. Drupacked Demo asks for Node. Staging copies
# what git lists, and git ignores dist, so DIRECTORY lies outside this checkout.
# Usage: build/node-fixture.sh DIRECTORY

mkdir -p -- "${1:?Usage: build/node-fixture.sh DIRECTORY}"
destination=$(cd -- "$1" && pwd)
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../examples/drupacked-demo"
git ls-files -z | xargs -0 cp --parents -t "$destination"
sed -i 's/^name: drupacked-demo$/name: node-demo/; /^languages:/d' "$destination/drupack.yml"
