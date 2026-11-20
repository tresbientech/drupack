#!/usr/bin/env bash
set -euo pipefail

# Runs FrankenPHP's build-static.sh in its toolchain container, on the
# static-php-cli checkout build-builder.sh copied beside it.

cd /go/src/app
# static-php-cli is third-party code, so none of its Composer scripts or plugins run.
composer install --working-dir=static-php-cli --no-dev --no-interaction --prefer-dist --no-scripts --no-plugins
# build-static.sh downloads the spc nightly unless dist/spc exists.
mkdir -p dist
printf '#!/bin/sh\nexec php /go/src/app/static-php-cli/bin/spc "$@"\n' >dist/spc
chmod +x dist/spc
exec ./build-static.sh
