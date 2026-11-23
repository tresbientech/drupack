#!/usr/bin/env bash
set -euo pipefail

# Writes the variant fixture to DIRECTORY: the tracked files of the site folder SITE,
# named variant-fixture, with no translations. It adds a Mercure hub, a
# .drupack/route.caddy and a .drupack/mercure.caddy.
# Staging copies what git lists, and git ignores dist, so DIRECTORY lies outside this checkout.
# Usage: build/test/variant-fixture.sh SITE DIRECTORY

site=${1:?Usage: build/test/variant-fixture.sh SITE DIRECTORY}
mkdir -p -- "${2:?Usage: build/test/variant-fixture.sh SITE DIRECTORY}"
destination=$(cd -- "$2" && pwd)
cd -- "$site"
git ls-files -z -- . | xargs -0 cp --parents -t "$destination"
sed -i 's/^name: fixture$/name: variant-fixture/; /^languages:/d' "$destination/drupack.yml"
python3 -c 'import json, sys
path = sys.argv[1]
with open(path) as handle:
    composer = json.load(handle)
composer.setdefault("extra", {}).setdefault("drupack", {})["mercure"] = True
with open(path, "w") as handle:
    json.dump(composer, handle, indent=4)
    handle.write("\n")' "$destination/composer.json"
# The project's own Caddy rules, which project_rules_cases.py asserts on the packed site.
mkdir "$destination/.drupack"
printf 'header X-Project-Rule applied\n' >"$destination/.drupack/route.caddy"
# The project's hub directives: the debugger UI answers only when the start imported them.
printf 'debugger\n' >"$destination/.drupack/mercure.caddy"
