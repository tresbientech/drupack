#!/usr/bin/env bash
set -euo pipefail

# Writes the variant fixture to DIRECTORY: the tracked files of the Drupacked Demo checkout
# DEMO, named variant-demo, with no translations. It adds a Mercure hub, a drupack.caddy and
# mercure-probe.php at the docroot, which publishes the request's "data" query value as a
# private update on the topic drupack/probe and prints the update's id. Staging copies what
# git lists, and git ignores dist, so DIRECTORY lies outside this checkout.
# Usage: build/variant-fixture.sh DEMO DIRECTORY

demo=${1:?Usage: build/variant-fixture.sh DEMO DIRECTORY}
mkdir -p -- "${2:?Usage: build/variant-fixture.sh DEMO DIRECTORY}"
destination=$(cd -- "$2" && pwd)
cd -- "$demo"
git ls-files -z | xargs -0 cp --parents -t "$destination"
sed -i 's/^name: drupacked-demo$/name: variant-demo/; /^languages:/d' "$destination/drupack.yml"
python3 -c 'import json, sys
path = sys.argv[1]
with open(path) as handle:
    composer = json.load(handle)
composer.setdefault("extra", {}).setdefault("drupack", {})["mercure"] = True
with open(path, "w") as handle:
    json.dump(composer, handle, indent=4)
    handle.write("\n")' "$destination/composer.json"
docroot=$(python3 -c 'import json, sys; print(json.load(sys.stdin)["extra"]["drupal-scaffold"]["locations"]["web-root"].rstrip("/"))' <composer.json)
# Composer creates the docroot, so the demo tracks no file there.
mkdir -p -- "$destination/$docroot"
cat >"$destination/$docroot/mercure-probe.php" <<'PHP'
<?php
echo mercure_publish('drupack/probe', (string) ($_GET['data'] ?? ''), true);
PHP
# The project's own Caddy rules, which project_rules_cases.py asserts on the packed site.
printf 'header X-Project-Rule applied\n' >"$destination/drupack.caddy"
