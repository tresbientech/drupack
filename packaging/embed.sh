#!/usr/bin/env bash
set -euo pipefail

cd /go/src/app
case "${1:-}" in
"")
archive_options=(--mtime=@0 --owner=0 --group=0 --numeric-owner --mode=u+rw,go+rX)
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
    ;;
--reuse-archive) ;;
*) printf 'Unknown build option: %s\n' "$1" >&2; exit 1 ;;
esac
php_config=/go/src/app/dist/static-php-cli/buildroot/bin/php-config
frankenphp_version=1.12.7
export CGO_ENABLED=1
export CGO_CFLAGS="-fPIC -O2 -I/go/src/app/dist/static-php-cli/buildroot/include $($php_config --includes) -DFRANKENPHP_VERSION=$frankenphp_version"
php_libraries=$($php_config --libs)
php_libraries=${php_libraries//-lstdc++/$(gcc -print-file-name=libstdc++.a)}
native_libraries=$(PKG_CONFIG_PATH=/go/src/app/dist/static-php-cli/buildroot/lib/pkgconfig pkg-config --static --libs libcurl libzip freetype2 libavif libwebp)
export CGO_LDFLAGS="-L/go/src/app/dist/static-php-cli/buildroot/lib -static-libgcc -Wl,--start-group -lphp $php_libraries $native_libraries -lwatcher-c -lpq -lpgcommon -lpgport -lhashkit -lcharset -largon2 -Wl,--end-group"
build_tags=nobadger,nomysql,nopgx
linker_flags=-Wl,--dynamic-list=/go/src/app/dist/static-php-cli/buildroot/lib/libphp.a.dynsym
case "$SPC_LIBC" in
    glibc) linker_flags="-pie $linker_flags" ;;
    musl)
        CGO_LDFLAGS="-static-pie $CGO_LDFLAGS"
        linker_flags="-static-pie -Wl,-z,stack-size=0x80000 $linker_flags"
        build_tags="static_build,$build_tags"
        ;;
    *) printf 'Unsupported libc: %s\n' "$SPC_LIBC" >&2; exit 1 ;;
esac
native_arch="$(uname -m)"
case "$native_arch" in
    x86_64|aarch64) static_php_arch="$native_arch-linux" ;;
    *) printf 'Unsupported native architecture: %s\n' "$native_arch" >&2; exit 1 ;;
esac
export GOROOT="/go/src/app/dist/static-php-cli/pkgroot/$static_php_arch/go-xcaddy"
export GOPATH="/go/src/app/dist/static-php-cli/pkgroot/$static_php_arch/go"
export GOTOOLCHAIN=local
mkdir -p /out
cd caddy
"$GOROOT/bin/go" build -mod=readonly -buildmode=pie -tags="$build_tags" \
    -ldflags="-s -w -linkmode=external -extldflags '$linker_flags' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP $frankenphp_version PHP $($php_config --version) Caddy'" \
    -o /out/drupack ./frankenphp
/out/drupack version
