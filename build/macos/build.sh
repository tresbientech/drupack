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

frankenphp_version=1.12.7
drupack_version=${DRUPACK_VERSION:-dev}
frankenphp_commit=a765b086f5cc56f6b7753117367d56e1b0da948d
php_version=8.5.10
spc_version=2.8.5
# The allowlist every builder compiles. spc resolves the libraries each name needs.
extensions=$(bash "$repository/runtime/extensions-list.sh" "$repository/runtime/php-extensions.txt")
extension_libs=$(bash "$repository/runtime/extensions-list.sh" "$repository/runtime/php-extension-libs.txt")

case "$(uname -m)" in
    arm64) spc_archive=spc-macos-aarch64.tar.gz; spc_sha256=acf2f25d56d0cbf8e65aa82e5054fef555f7be7c5c38046c6e0819f266d83225 ;;
    x86_64) spc_archive=spc-macos-x86_64.tar.gz; spc_sha256=e8b798048f62ca4960764196543b60ae703f7174aa418824cf542aeec1d2cd6a ;;
    *) printf 'Unsupported macOS architecture: %s\n' "$(uname -m)" >&2; exit 1 ;;
esac

export MACOSX_DEPLOYMENT_TARGET=13.0
mkdir -p "$work"
cd "$work"
# The script changes directory again below, so the caller's path resolves here.
work=$(pwd)
curl -fsSL -o "$spc_archive" "https://github.com/crazywhalecc/static-php-cli/releases/download/$spc_version/$spc_archive"
printf '%s  %s\n' "$spc_sha256" "$spc_archive" | shasum -a 256 -c -
tar -xzf "$spc_archive"

# Installs pkg-config and the other build tools static-php-cli needs.
./spc doctor --auto-fix
./spc download --with-php="$php_version" --for-extensions="$extensions" --for-libs="$extension_libs" --prefer-pre-built --retry 5
./spc build --enable-zts --build-embed --disable-opcache-jit "$extensions" --with-libs="$extension_libs"
php_includes=$(./spc spc-config "$extensions" --with-libs="$extension_libs" --includes)
php_libraries=$(./spc spc-config "$extensions" --with-libs="$extension_libs" --libs)

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

runtime="$work/runtime"
entry=drupack
# The work directory is reused between builds, and the packer collects every
# file it finds here.
rm -rf "$runtime"
mkdir -p "$runtime"

cd frankenphp/caddy/frankenphp
CGO_ENABLED=1 CGO_CFLAGS="$php_includes -DFRANKENPHP_VERSION=$frankenphp_version" CGO_LDFLAGS="$php_libraries" \
    go build -buildmode=pie -tags=nobadger,nomysql,nopgx \
    -ldflags="-s -w -linkmode=external -X 'main.version=$drupack_version' -X 'github.com/caddyserver/caddy/v2.CustomVersion=FrankenPHP $frankenphp_version PHP $php_version Caddy'" \
    -o "$runtime/$entry"
"$runtime/$entry" version

# php.ini and the trust bundle ride beside the entry executable, where PHPRC
# names them at every hop.
cp "$repository/application/php.ini" "$repository/application/cacert.pem" "$runtime/"

# The subshell keeps the packer's build inside the launcher module, off this FrankenPHP checkout.
(cd "$repository/launcher" \
  && go run ./cmd/pack -runtime "$runtime" -entry "$entry" \
     -version "$drupack_version" \
     -source "$repository/launcher" -output "$output" \
     -app "$payload/app-payload.tar" -app-checksum "$payload/app_checksum.txt" \
     -site "$payload/site.json" -site-version "$drupack_version")
"$output" version
if [ -n "$engine_payload" ]; then
    (cd "$repository/launcher" \
      && go run ./cmd/pack -engine -runtime "$runtime" -entry "$entry" \
         -version "$drupack_version" \
         -source "$repository/launcher" -output "$engine_output" \
         -app "$engine_payload/app-payload.tar" -app-checksum "$engine_payload/app_checksum.txt")
    "$engine_output" --version
fi
