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
. "$repository/runtime/builder/builder-inputs.sh"
drupack_version=${DRUPACK_ENGINE_VERSION:-dev}
# The allowlist every builder compiles. spc resolves the libraries each name needs.
extensions=$(bash "$repository/runtime/extensions/extensions-list.sh" "$repository/runtime/extensions/php-extensions.txt")
extension_libs=$(bash "$repository/runtime/extensions/extensions-list.sh" "$repository/runtime/extensions/php-extension-libs.txt")

case "$(uname -m)" in
    arm64) target=macos-arm64 ;;
    *) printf 'Unsupported macOS architecture: %s\n' "$(uname -m)" >&2; exit 1 ;;
esac

export MACOSX_DEPLOYMENT_TARGET=13.0
mkdir -p "$work"
cd "$work"
# The script changes directory again below, so the caller's path resolves here.
work=$(pwd)
# static-php-cli runs from the pinned checkout, on the runner's PHP and Composer.
bash "$repository/runtime/builder/fetch-spc.sh" "$work/static-php-cli"
# static-php-cli is third-party code, so none of its Composer scripts or plugins run.
composer install --working-dir="$work/static-php-cli" --no-dev --no-interaction --prefer-dist --no-scripts --no-plugins
spc=(php "$work/static-php-cli/bin/spc")

# Installs pkg-config and the other build tools static-php-cli needs.
"${spc[@]}" doctor --auto-fix
# A buildroot that holds libphp.a came from the cache of .github/actions/macos-build/action.yml,
# whose key hashes every input of this compile. spc build compiles PHP again over it.
if [ ! -f "$work/buildroot/lib/libphp.a" ]; then
    # spc build downloads again, and without its own dl- options it clones each branch head.
    pins=$(bash "$repository/runtime/builder/fetch-branch-pins.sh" macos "$work/branch-pins")
    download=() build=()
    while IFS= read -r pin; do download+=("--custom-local=$pin") build+=("--dl-custom-local=$pin"); done <<<"$pins"
    # static-php-cli's pre-built archives lag the libraries' own releases, so each
    # library compiles from its source release. The set is the one upstream.py records.
    "${spc[@]}" download --with-php="$php_version" --for-packages="php-embed,ext-${extensions//,/,ext-},$extension_libs" \
        --no-alt --retry 5 "${download[@]}"
    php "$repository/runtime/builder/check-downloads.php" downloads
    # spc's embed smoke test reads nm's stdout and stderr into one stream, which can
    # split a symbol name and fail the link. The runtime link below tests the embed.
    "${spc[@]}" build --enable-zts --build-embed --disable-opcache-jit --no-smoke-test=embed "$extensions" --with-libs="$extension_libs" "${build[@]}"
    # The build downloads what its own options miss.
    php "$repository/runtime/builder/check-downloads.php" downloads
fi
php_includes=$("${spc[@]}" spc-config "$extensions" --with-libs="$extension_libs" --includes)
php_libraries=$("${spc[@]}" spc-config "$extensions" --with-libs="$extension_libs" --libs)
# build/release/sbom.py reads this report.
sh "$repository/runtime/builder/runtime-libraries.sh" "$work/buildroot" "$php_libraries" >"$work/runtime-libraries.txt"
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
cp "$repository/runtime/frankenphp/entrypoint.go" frankenphp/caddy/frankenphp/drupack.go
cp "$repository/runtime/frankenphp/watch/watch.go" frankenphp/caddy/frankenphp/drupack_watch.go
cp "$repository/runtime/frankenphp/crtenv_other.go" frankenphp/caddy/frankenphp/drupack_crtenv_other.go

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
"$runtime/$entry" drupack-version

# php.ini and the trust bundle ride beside the entry executable, where PHPRC
# names them at every hop.
cp "$repository/runtime/php.ini" "$repository/runtime/cacert.pem" "$runtime/"

# The subshell keeps the packer's build inside the launcher module, off this FrankenPHP checkout.
(cd "$repository/launcher" \
  && go run ./cmd/pack -target "$target" -runtime "$runtime" -payload "$payload" \
     -version "$drupack_version" -output "$output")
"$output" --version
if [ -n "$engine_payload" ]; then
    (cd "$repository/launcher" \
      && go run ./cmd/pack -engine -target "$target" -runtime "$runtime" -payload "$engine_payload" \
         -version "$drupack_version" -output "$engine_output")
    "$engine_output" --version
fi
