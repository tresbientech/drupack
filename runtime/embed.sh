#!/usr/bin/env bash
set -euo pipefail

# Links the runtime executable for the libc the builder image names in LIBC,
# gnu or musl.

cd /go/src/app
# frankenphp's embed.go needs the file to exist, and its init returns early on
# an empty one, which leaves EmbeddedAppPath unset.
: > app.tar

build_tags=nobadger,nomysql,nopgx
symbols=/go/src/app/dist/buildroot/lib/libphp.a.dynsym
# A glibc build links a dynamic PIE against the host libc. A musl build links a
# single file that runs on any host.
# -z now resolves every relocation at load, which leaves the GOT read-only for
# the process lifetime. The static-pie link already reports BIND_NOW.
# SPC_TARGET and SPC_TOOLCHAIN pick the toolchain as build-static.sh picks it:
# the image's gcc for gnu, zig for musl.
case "$LIBC" in
    gnu)
        libc=glibc
        export SPC_TARGET= SPC_TOOLCHAIN='StaticPHP\Toolchain\GccNativeToolchain'
        static_flags=
        linker_flags="-pie -Wl,-z,now -Wl,--dynamic-list=$symbols"
        ;;
    musl)
        libc=musl
        export SPC_TARGET=native-native-musl SPC_TOOLCHAIN=
        static_flags=-static-pie
        # musl sizes every new thread's stack from this header, 128 KB without it.
        # PHP threads get 8 MB, glibc's default and musl's largest.
        # zig's linker takes the export list under the macOS flag name.
        linker_flags="-static-pie -Wl,-z,stack-size=0x800000 -Wl,-exported_symbols_list,$symbols"
        build_tags="static_build,$build_tags"
        ;;
    *) printf 'Unsupported libc: %s\n' "$LIBC" >&2; exit 1 ;;
esac
# spc prints the environment its own FrankenPHP build links in, CC among it.
while IFS= read -r variable; do
    export "$variable"
done < <(cd /go/src/app/dist && ./spc dev:env)
# spc puts zig on PATH inside its own build steps alone.
if [ "$LIBC" = musl ]; then
    export PATH="/opt/spc-pkgroot/zig:$PATH"
fi

php_config=/go/src/app/dist/buildroot/bin/php-config
. /build/builder-inputs.sh
export CGO_ENABLED=1
export CGO_CFLAGS="-fPIC -O2 -I/go/src/app/dist/buildroot/include $($php_config --includes) -DFRANKENPHP_VERSION=$frankenphp_version"
# The image compiled PHP from this same list, so the link flags follow it
# rather than a written-out set that would name a library no extension pulls.
extensions=$(bash /build/extensions-list.sh /build/php-extensions.txt)
extension_libs=$(bash /build/extensions-list.sh /build/php-extension-libs.txt)
# spc resolves buildroot against its working directory, and reports a missing
# library on stdout, where a command substitution hides it. FrankenPHP's
# craft.yml builds with suggested libraries, such as curl's Kerberos, so the
# link names them too.
if ! php_libraries=$(cd /go/src/app/dist && ./spc spc-config "$extensions" --with-libs="$extension_libs" --with-suggests --libs); then
    printf '%s\n' "$php_libraries" >&2
    exit 1
fi
if [ "$LIBC" = gnu ]; then
    php_libraries=${php_libraries//-lstdc++/$(gcc -print-file-name=libstdc++.a)}
fi
export CGO_LDFLAGS="$static_flags -L/go/src/app/dist/buildroot/lib -static-libgcc -Wl,--start-group $php_libraries -Wl,--end-group"
sqlite_version=$(sed -n 's/^#define SQLITE_VERSION *"\(.*\)"$/\1/p' /go/src/app/dist/buildroot/include/sqlite3.h)
if [[ -z $sqlite_version ]]; then
    printf 'sqlite3.h names no SQLITE_VERSION\n' >&2
    exit 1
fi
# static-php-cli installs the Go toolchain FrankenPHP's own build uses.
export GOROOT=/opt/spc-pkgroot/go-xcaddy
export GOTOOLCHAIN=local
# Go moves to the next entry after a "|" on any error, so a dropped fetch gets
# a second attempt. go.sum checks either answer.
export GOPROXY='https://proxy.golang.org|https://proxy.golang.org,direct'
mkdir -p /out
cd caddy
"$GOROOT/bin/go" build -mod=readonly -buildmode=pie -tags="$build_tags" \
    -ldflags="-s -w -linkmode=external -extldflags '$linker_flags' -X 'main.version=${DRUPACK_VERSION:-dev}' -X 'main.libc=$libc' -X 'main.frankenphpVersion=$frankenphp_version' -X 'main.sqliteVersion=$sqlite_version' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP $frankenphp_version PHP $($php_config --version) Caddy'" \
    -o /out/drupack ./frankenphp
/out/drupack --version

