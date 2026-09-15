#!/usr/bin/env bash
set -euo pipefail

cd /go/src/app
archive_options=(--mtime=@0 --owner=0 --group=0 --numeric-owner)
# Drupal's cached absolute paths use the full application input identity.
tar "${archive_options[@]}" -cf - -C /app . \
    | sha256sum | cut -d ' ' -f 1 | tr -d '\n' > app_checksum.txt
tar "${archive_options[@]}" \
    --exclude='tests' --exclude='Tests' \
    --exclude='*.js.map' --exclude='*.css.map' --exclude='*.pcss.css' \
    --exclude='package-lock.json' --exclude='yarn.lock' \
    --exclude='pnpm-lock.yaml' --exclude='npm-shrinkwrap.json' \
    --exclude='.github' --exclude='.gitlab' \
    --exclude='./web/modules/contrib/canvas/ui/src' \
    --exclude='./web/modules/contrib/canvas/ui/lib' \
    --exclude='./web/modules/contrib/canvas/ui/assets/videos' \
    --exclude='./web/modules/contrib/canvas/packages/cli/src' \
    --exclude='./web/modules/contrib/canvas/packages/workbench/src' \
    --exclude='./web/modules/contrib/canvas/packages/eslint-config/src' \
    --exclude='./web/modules/contrib/modeler_api/ui/src' \
    --exclude='./web/modules/contrib/project_browser/sveltejs/src' \
    --exclude='./web/modules/contrib/project_browser/sveltejs/scripts' \
    --exclude='./vendor/html2text/html2text/test' \
    -cf app.tar -C /app .
tar "${archive_options[@]}" --no-recursion -rf app.tar -C /app \
    ./web/modules/contrib/canvas/ui/src \
    ./web/modules/contrib/canvas/ui/src/local_packages \
    ./web/modules/contrib/canvas/ui/src/local_packages/hyperscriptify \
    ./web/modules/contrib/canvas/ui/src/local_packages/hyperscriptify/LICENSE
php_config=/go/src/app/dist/static-php-cli/buildroot/bin/php-config
frankenphp_version=1.12.7
export CGO_ENABLED=1
export CGO_CFLAGS="-fPIC -O2 -I/go/src/app/dist/static-php-cli/buildroot/include $($php_config --includes) -DFRANKENPHP_VERSION=$frankenphp_version"
php_libraries=$($php_config --libs)
php_libraries=${php_libraries//-lstdc++/$(gcc -print-file-name=libstdc++.a)}
export CGO_LDFLAGS="-L/go/src/app/dist/static-php-cli/buildroot/lib -static-libgcc -Wl,--start-group -lphp $php_libraries -lwatcher-c -lpq -lpgcommon -lpgport -lhashkit -lcharset -largon2 -Wl,--end-group"
export GOROOT=/go/src/app/dist/static-php-cli/pkgroot/x86_64-linux/go-xcaddy
export GOPATH=/go/src/app/dist/static-php-cli/pkgroot/x86_64-linux/go
export GOTOOLCHAIN=local
mkdir -p /out
cd caddy
"$GOROOT/bin/go" build -mod=readonly -buildmode=pie -tags=nobadger,nomysql,nopgx \
    -ldflags="-s -w -linkmode=external -extldflags '-pie -Wl,--dynamic-list=/go/src/app/dist/static-php-cli/buildroot/lib/libphp.a.dynsym' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP $frankenphp_version PHP $($php_config --version) Caddy'" \
    -o /out/portable-drupal ./frankenphp
/out/portable-drupal version
