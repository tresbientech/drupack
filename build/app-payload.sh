#!/usr/bin/env bash
set -euo pipefail

# Packs the application the launcher carries into OUTPUT: app-payload.tar and
# app_checksum.txt. Its content is PHP source, vendor, translations and a seeded
# database, none of which depend on the libc a runtime links against, so one
# payload serves every runtime.
usage='Usage: build/app-payload.sh APPLICATION OUTPUT DOCROOT'
application=${1:?$usage}
output=${2:?$usage}
contrib=./${3:?$usage}/modules/contrib

mkdir -p "$output"
cd "$output"
archive_options=(--mtime=@0 --owner=0 --group=0 --numeric-owner --mode=u+rw,go+rX)
# Drupal's cached absolute paths use the full application input identity.
tar "${archive_options[@]}" -cf - -C "$application" . \
    | sha256sum | cut -d ' ' -f 1 | tr -d '\n' > app_checksum.txt
# php.ini and cacert.pem ride beside the entry executable in the packed
# runtime directory instead, which PHPRC names in every hop; nothing reads
# either file from the application directory.
tar "${archive_options[@]}" \
    --exclude='tests' --exclude='Tests' \
    --exclude='./php.ini' --exclude='./cacert.pem' \
    --exclude='*.js.map' --exclude='*.css.map' --exclude='*.pcss.css' \
    --exclude='package-lock.json' --exclude='yarn.lock' \
    --exclude='pnpm-lock.yaml' --exclude='npm-shrinkwrap.json' \
    --exclude='.github' --exclude='.gitlab' \
    --exclude="$contrib/canvas/ui/src" \
    --exclude="$contrib/canvas/ui/lib" \
    --exclude="$contrib/canvas/ui/assets/videos" \
    --exclude="$contrib/canvas/packages/cli/src" \
    --exclude="$contrib/canvas/packages/workbench/src" \
    --exclude="$contrib/canvas/packages/eslint-config/src" \
    --exclude="$contrib/modeler_api/ui/src" \
    --exclude="$contrib/project_browser/sveltejs/src" \
    --exclude="$contrib/project_browser/sveltejs/scripts" \
    --exclude='./vendor/html2text/html2text/test' \
    -cf app-payload.tar -C "$application" .
# Canvas's ui/src holds one file its licence requires to ship, which the exclude above drops.
hyperscriptify=$contrib/canvas/ui/src/local_packages/hyperscriptify
if [ -e "$application/$hyperscriptify/LICENSE" ]; then
    tar "${archive_options[@]}" --no-recursion -rf app-payload.tar -C "$application" \
        "$contrib/canvas/ui/src" "$contrib/canvas/ui/src/local_packages" \
        "$hyperscriptify" "$hyperscriptify/LICENSE"
fi
