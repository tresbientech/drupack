#!/usr/bin/env bash
set -euo pipefail

# Runs FrankenPHP's build-static.sh in its toolchain container, on the
# static-php-cli checkout build-builder.sh copied beside it.

cd /go/src/app
# static-php-cli is third-party code, so none of its Composer scripts or plugins run.
composer install --working-dir=static-php-cli --no-dev --no-interaction --prefer-dist --no-scripts --no-plugins
# build-static.sh downloads the spc nightly unless dist/spc exists. It runs dist/spc craft
# from dist, and embed.sh runs dist/spc later. Around craft, the wrapper downloads the set
# upstream.py records and checks it, then checks again what craft downloaded itself.
mkdir -p dist
cat >dist/spc <<'WRAPPER'
#!/bin/sh
set -eu
spc=/go/src/app/static-php-cli/bin/spc
[ "$1" = craft ] || exec php "$spc" "$@"
locals=
# Each pin checkout is one word, and CHECK_DOWNLOADS is empty or one flag.
for pin in /go/src/app/branch-pins/*; do locals="$locals --custom-local=${pin##*/}:$pin"; done
php "$spc" download --with-php="$PHP_VERSION" --source-only --no-alt --retry=5 $locals \
    --for-packages="php-embed,ext-$(printf %s "$PHP_EXTENSIONS" | sed 's/,/,ext-/g'),$PHP_EXTENSION_LIBS"
php /go/src/app/check-downloads.php $CHECK_DOWNLOADS downloads
php "$spc" "$@"
php /go/src/app/check-downloads.php $CHECK_DOWNLOADS downloads
WRAPPER
chmod +x dist/spc
exec ./build-static.sh
