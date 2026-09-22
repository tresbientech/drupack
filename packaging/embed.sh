#!/usr/bin/env bash
set -euo pipefail

# Links the runtime executable for the libc the builder image names in
# SPC_LIBC. packaging/app-payload.sh packs the application separately.

cd /go/src/app
# The launcher carries the application and unpacks it once per release, so the server
# embeds nothing. frankenphp's embed.go still needs the file to exist, and its init
# returns early on an empty one, which leaves EmbeddedAppPath unset.
: > app.tar

php_config=/go/src/app/dist/static-php-cli/buildroot/bin/php-config
frankenphp_version=1.12.7
export CGO_ENABLED=1
export CGO_CFLAGS="-fPIC -O2 -I/go/src/app/dist/static-php-cli/buildroot/include $($php_config --includes) -DFRANKENPHP_VERSION=$frankenphp_version"
# static-php-cli owns the mapping from extensions to libraries, and the image
# compiled PHP from this same list, so the link flags follow the allowlist
# rather than a written-out set that would name a library no extension pulls.
extensions=$(bash /build/extensions-list.sh /build/php-extensions.txt)
php_libraries=$(/go/src/app/dist/static-php-cli/spc spc-config "$extensions" --libs)
php_libraries=${php_libraries//-lstdc++/$(gcc -print-file-name=libstdc++.a)}
# watcher serves FrankenPHP itself, so no extension pulls it.
export CGO_LDFLAGS="-L/go/src/app/dist/static-php-cli/buildroot/lib -static-libgcc -Wl,--start-group $php_libraries -lwatcher-c -Wl,--end-group"
build_tags=nobadger,nomysql,nopgx
linker_flags="-Wl,--dynamic-list=/go/src/app/dist/static-php-cli/buildroot/lib/libphp.a.dynsym"
# The builder image sets SPC_LIBC. A glibc build links a dynamic PIE against the
# host libc. A musl build links a single file that runs on any host. A Linux
# executable carries both, and the launcher picks one per host.
# -z now resolves every relocation at load, which leaves the GOT read-only for
# the process lifetime. The static-pie link already reports BIND_NOW.
case "$SPC_LIBC" in
    glibc) linker_flags="-pie -Wl,-z,now $linker_flags" ;;
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
    -ldflags="-s -w -linkmode=external -extldflags '$linker_flags' -X 'main.version=${DRUPACK_VERSION:-dev}' -X 'main.libc=$SPC_LIBC' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP $frankenphp_version PHP $($php_config --version) Caddy'" \
    -o /out/drupack ./frankenphp
/out/drupack version

