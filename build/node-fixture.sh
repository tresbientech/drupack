#!/usr/bin/env bash
set -euo pipefail

# Writes the Node fixture to DIRECTORY: Mercury Demo's tracked files, named
# node-demo, with no translations. Mercury Demo asks for Node. Staging copies
# what git lists, and git ignores dist, so DIRECTORY lies outside this checkout.
# Usage: build/node-fixture.sh DIRECTORY

mkdir -p -- "${1:?Usage: build/node-fixture.sh DIRECTORY}"
destination=$(cd -- "$1" && pwd)
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../examples/mercury-demo"
git ls-files -z | xargs -0 cp --parents -t "$destination"
sed -i 's/^name: mercury-demo$/name: node-demo/; /^languages:/d' "$destination/drupack.yml"
