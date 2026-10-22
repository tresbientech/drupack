#!/usr/bin/env bash
set -euo pipefail

# Runs FrankenPHP's build-static.sh in its toolchain container, on the
# static-php-cli checkout build-builder.sh copied beside it.

cd /go/src/app
composer install --working-dir=static-php-cli --no-dev --no-interaction --prefer-dist
# build-static.sh downloads the spc nightly unless dist/spc exists.
mkdir -p dist
printf '#!/bin/sh\nexec php /go/src/app/static-php-cli/bin/spc "$@"\n' >dist/spc
chmod +x dist/spc
if [ -s /run/github-token ]; then
    GITHUB_TOKEN=$(cat /run/github-token)
    export GITHUB_TOKEN
fi
exec ./build-static.sh
