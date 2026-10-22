#!/usr/bin/env bash
set -euo pipefail

# With ENGINE_PAYLOAD and ENGINE_OUTPUT it also packs the engine executable on
# the same runtime.
usage='Usage: build.sh PAYLOAD_DIRECTORY WORK_DIRECTORY OUTPUT [ENGINE_PAYLOAD ENGINE_OUTPUT]'
payload=$(cd -- "${1:?$usage}" && pwd)
work=${2:?$usage}
mkdir -p "$(dirname -- "${3:?$usage}")"
output="$(cd -- "$(dirname -- "$3")" && pwd)/$(basename -- "$3")"
engine_payload=
if [ -n "${4:-}" ]; then
    engine_payload=$(cd -- "$4" && pwd)
    mkdir -p "$(dirname -- "${5:?$usage}")"
    engine_output="$(cd -- "$(dirname -- "$5")" && pwd)/$(basename -- "$5")"
fi
repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)

# The FrankenPHP and PHP pins every Linux builder image compiles.
. "$repository/runtime/builder-inputs.sh"
drupack_version=${DRUPACK_VERSION:-dev}
# The allowlist every builder compiles. spc resolves the libraries each name needs.
extensions=$(bash "$repository/runtime/extensions-list.sh" "$repository/runtime/php-extensions.txt")
extension_libs=$(bash "$repository/runtime/extensions-list.sh" "$repository/runtime/php-extension-libs.txt")

case "$(uname -m)" in
    arm64) target=macos-arm64 ;;
    x86_64) target=macos-amd64 ;;
    *) printf 'Unsupported macOS architecture: %s\n' "$(uname -m)" >&2; exit 1 ;;
esac

export MACOSX_DEPLOYMENT_TARGET=13.0
mkdir -p "$work"
cd "$work"
# The script changes directory again below, so the caller's path resolves here.
work=$(pwd)
# static-php-cli runs from the pinned checkout, on the runner's PHP and Composer.
bash "$repository/runtime/fetch-spc.sh" "$work/static-php-cli"
composer install --working-dir="$work/static-php-cli" --no-dev --no-interaction --prefer-dist
spc=(php "$work/static-php-cli/bin/spc")

# Installs pkg-config and the other build tools static-php-cli needs.
"${spc[@]}" doctor --auto-fix
# static-php-cli's pre-built archives lag the libraries' own releases, so each
# library compiles from its source release.
"${spc[@]}" download --with-php="$php_version" --for-extensions="$extensions" --for-libs="$extension_libs" --retry 5
# spc's embed smoke test reads nm's stdout and stderr into one stream, which can
# split a symbol name and fail the link. The runtime link below tests the embed.
"${spc[@]}" build --enable-zts --build-embed --disable-opcache-jit --no-smoke-test=embed "$extensions" --with-libs="$extension_libs"
php_includes=$("${spc[@]}" spc-config "$extensions" --with-libs="$extension_libs" --includes)
php_libraries=$("${spc[@]}" spc-config "$extensions" --with-libs="$extension_libs" --libs)
# --version names the SQLite release static-php-cli compiled into PHP.
sqlite_version=$(sed -n 's/^#define SQLITE_VERSION *"\(.*\)"$/\1/p' "$work/buildroot/include/sqlite3.h")
if [[ -z $sqlite_version ]]; then
    printf 'sqlite3.h names no SQLITE_VERSION\n' >&2
    exit 1
fi

rm -rf frankenphp
git -c advice.detachedHead=false clone --quiet --depth 1 --branch "v$frankenphp_version" https://github.com/php/frankenphp.git frankenphp
# A tag can move, so the checkout must match the pinned commit.
frankenphp_head=$(git -C frankenphp rev-parse HEAD)
if [[ $frankenphp_head != "$frankenphp_commit" ]]; then
    printf 'FrankenPHP v%s resolved to %s, expected %s\n' "$frankenphp_version" "$frankenphp_head" "$frankenphp_commit" >&2
    exit 1
fi
# The launcher carries the application, so the server embeds an empty archive,
# which leaves frankenphp's own extraction unused.
: > frankenphp/app.tar
cp "$payload/app_checksum.txt" frankenphp/
cp "$repository/runtime/entrypoint.go" frankenphp/caddy/frankenphp/drupack.go
cp "$repository/runtime/watch/watch.go" frankenphp/caddy/frankenphp/drupack_watch.go
cp "$repository/runtime/tls/tls.go" frankenphp/caddy/frankenphp/drupack_tls.go

runtime="$work/runtime"
entry=drupack
# The work directory is reused between builds, and the packer collects every
# file it finds here.
rm -rf "$runtime"
mkdir -p "$runtime"

cd frankenphp/caddy/frankenphp
CGO_ENABLED=1 CGO_CFLAGS="$php_includes -DFRANKENPHP_VERSION=$frankenphp_version" CGO_LDFLAGS="$php_libraries" \
    go build -buildmode=pie -tags=nobadger,nomysql,nopgx \
    -ldflags="-s -w -linkmode=external -X 'main.version=$drupack_version' -X 'main.frankenphpVersion=$frankenphp_version' -X 'main.sqliteVersion=$sqlite_version' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP $frankenphp_version PHP $php_version Caddy'" \
    -o "$runtime/$entry"
"$runtime/$entry" --version

# php.ini and the trust bundle ride beside the entry executable, where PHPRC
# names them at every hop.
cp "$repository/application/php.ini" "$repository/application/cacert.pem" "$runtime/"

# The payload of a site asking for Node holds each target's verified archive.
# bash 3.2 calls an empty array unset, hence the guarded expansion below.
node=()
if [ -f "$payload/node/$target.tar.gz" ]; then
    node=(-node "$payload/node/$target.tar.gz")
fi
# The subshell keeps the packer's build inside the launcher module, off this FrankenPHP checkout.
(cd "$repository/launcher" \
  && go run ./cmd/pack -runtime "$runtime" -entry "$entry" \
     -version "$drupack_version" \
     -source "$repository/launcher" -output "$output" \
     -app "$payload/app-payload.tar" -app-checksum "$payload/app_checksum.txt" \
     -site "$payload/site.json" -site-version "$drupack_version" ${node[@]+"${node[@]}"})
"$output" --version
if [ -n "$engine_payload" ]; then
    (cd "$repository/launcher" \
      && go run ./cmd/pack -engine -runtime "$runtime" -entry "$entry" \
         -version "$drupack_version" \
         -source "$repository/launcher" -output "$engine_output" \
         -app "$engine_payload/app-payload.tar" -app-checksum "$engine_payload/app_checksum.txt")
    "$engine_output" --version
fi
