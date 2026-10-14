#!/usr/bin/env bash
set -euo pipefail

# Writes the Node fixture to DIRECTORY: the tracked files of the Drupacked Demo
# checkout DEMO, named node-demo, with no translations. Drupacked Demo asks for
# Node. Staging copies what git lists, and git ignores dist, so DIRECTORY lies
# outside this checkout.
# Usage: build/node-fixture.sh DEMO DIRECTORY

demo=${1:?Usage: build/node-fixture.sh DEMO DIRECTORY}
mkdir -p -- "${2:?Usage: build/node-fixture.sh DEMO DIRECTORY}"
destination=$(cd -- "$2" && pwd)
cd -- "$demo"
git ls-files -z | xargs -0 cp --parents -t "$destination"
sed -i 's/^name: drupacked-demo$/name: node-demo/; /^languages:/d' "$destination/drupack.yml"
