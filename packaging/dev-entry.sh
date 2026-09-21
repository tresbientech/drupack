#!/bin/sh
set -e

listen=$1
shift
mkdir -p /data/runtime
# The data directory is a separate mount, so the copy cannot use hard links. It
# lands beside the target and moves into place, so an interrupted copy is never
# mistaken for a finished one.
if [ ! -d /data/runtime/app ]; then
    rm -rf /data/runtime/app.partial
    cp -r /app /data/runtime/app.partial
    # Drupal's installer leaves sites/default read-only. The release archive
    # rewrites every mode with u+rw, so this copy does the same.
    chmod -R u+rwX /data/runtime/app.partial
    mv /data/runtime/app.partial /data/runtime/app
fi
# The copied files keep the image user's mode, so replace each by name: the
# directory permits the unlink, the file mode does not permit a write.
for name in Caddyfile launch.php php.ini settings.php cacert.pem; do
    rm -f "/data/runtime/app/$name"
    cp "/dev-runtime/$name" "/data/runtime/app/$name"
done

binary=/go/src/app/dist/static-php-cli/buildroot/bin/frankenphp
export DRUPACK_RUNTIME_BINARY=$binary
cd /data/runtime/app

# A release start gets these from the launcher's environment function. The
# development image runs frankenphp directly, so it sets the same two here, and
# a caller's own bundle still wins.
export PHPRC=/data/runtime/app
export DRUPACK_CA_FILE=${DRUPACK_CA_FILE:-/data/runtime/app/cacert.pem}

# launch.php sets up the site, then hands over to a server. Its Drush path runs
# the same setup and stops, and this image's php-server ignores a Caddyfile, so
# the server starts separately below.
DRUPACK_RUNTIME_DRUSH=1 "$binary" php-cli launch.php --data-dir /data --listen "0.0.0.0:$listen" "$@" status --field=bootstrap

mkdir -p /data/logs
export DRUPACK_RUNTIME_DATA_DIR=/data
export DRUPACK_RUNTIME_BIND=0.0.0.0
export DRUPACK_RUNTIME_PORT=$listen
export DRUPACK_RUNTIME_HOST=localhost
export DRUPACK_RUNTIME_LOG_PATH=/data/logs/caddy.log
exec "$binary" run --config Caddyfile --adapter caddyfile
