#!/usr/bin/env bash
set -euo pipefail

# Writes the variant fixture to DIRECTORY: the tracked files of the Drupacked Demo
# checkout DEMO, named variant-demo, with no translations. Drupacked Demo asks for
# Node. The fixture adds a Mercure hub and mercure-probe.php at the docroot, which
# publishes the request's "data" query value as a private update on the topic
# drupack/probe and prints the update's id. Staging copies what git lists, and git
# ignores dist, so DIRECTORY lies outside this checkout.
# Usage: build/variant-fixture.sh DEMO DIRECTORY

demo=${1:?Usage: build/variant-fixture.sh DEMO DIRECTORY}
mkdir -p -- "${2:?Usage: build/variant-fixture.sh DEMO DIRECTORY}"
destination=$(cd -- "$2" && pwd)
cd -- "$demo"
git ls-files -z | xargs -0 cp --parents -t "$destination"
sed -i 's/^name: drupacked-demo$/name: variant-demo/; /^languages:/d' "$destination/drupack.yml"
printf 'mercure: true\n' >>"$destination/drupack.yml"
docroot=$(python3 -c 'import json, sys; print(json.load(sys.stdin)["extra"]["drupal-scaffold"]["locations"]["web-root"].rstrip("/"))' <composer.json)
# Composer creates the docroot, so the demo tracks no file there.
mkdir -p -- "$destination/$docroot"
cat >"$destination/$docroot/mercure-probe.php" <<'PHP'
<?php
echo mercure_publish('drupack/probe', (string) ($_GET['data'] ?? ''), true);
PHP
