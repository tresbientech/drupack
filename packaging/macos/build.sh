#!/usr/bin/env bash
set -euo pipefail

usage='Usage: build.sh APPLICATION_DIRECTORY WORK_DIRECTORY OUTPUT'
application=$(cd -- "${1:?$usage}" && pwd)
work=${2:?$usage}
mkdir -p "$(dirname -- "${3:?$usage}")"
output="$(cd -- "$(dirname -- "$3")" && pwd)/$(basename -- "$3")"
repository=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)

frankenphp_version=1.12.7
drupack_version=${DRUPACK_VERSION:-dev}
frankenphp_commit=a765b086f5cc56f6b7753117367d56e1b0da948d
php_version=8.5.10
spc_version=2.8.5
# FrankenPHP's build-static.sh defaults at the pinned commit, which the Linux builder image also uses.
extensions=amqp,apcu,ast,bcmath,brotli,bz2,calendar,ctype,curl,dba,dom,exif,fileinfo,filter,ftp,gd,gmp,gettext,iconv,igbinary,imagick,intl,ldap,lz4,mbregex,mbstring,memcached,mysqli,mysqlnd,opcache,openssl,password-argon2,parallel,pcntl,pdo,pdo_mysql,pdo_pgsql,pdo_sqlite,pgsql,phar,posix,protobuf,readline,redis,session,shmop,simplexml,soap,sockets,sodium,sqlite3,ssh2,sysvmsg,sysvsem,sysvshm,tidy,tokenizer,xlswriter,xml,xmlreader,xmlwriter,xsl,xz,zip,zlib,yaml,zstd
extension_libs=libavif,nghttp2,nghttp3,ngtcp2,watcher

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
cp "$application/app_checksum.txt" frankenphp/
cp "$repository/packaging/entrypoint.go" frankenphp/caddy/frankenphp/drupack.go

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
cp "$repository/runtime/php.ini" "$repository/runtime/cacert.pem" "$runtime/"

# The subshell keeps the packer's build inside the launcher module, off this FrankenPHP checkout.
(cd "$repository/packaging/launcher" \
  && go run ./cmd/pack -runtime "$runtime" -entry "$entry" \
     -version "$drupack_version" \
     -source "$repository/packaging/launcher" -output "$output" \
     -app "$application/app-payload.tar" -app-checksum "$application/app_checksum.txt")
"$output" version
