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
    cp "/dev-application/$name" "/data/runtime/app/$name"
done

cd /data/runtime/app
# A release start gets these from its launcher, which names the application it
# unpacked, the site's name, php.ini's directory and the packed trust bundle. A
# caller's own bundle still wins.
export DRUPACK_RUNTIME_APP_DIR=/data/runtime/app
DRUPACK_RUNTIME_NAME=$(python3 -c 'import json; print(json.load(open("site.json"))["name"])')
export DRUPACK_RUNTIME_NAME
export PHPRC=/data/runtime/app
export DRUPACK_CA_FILE=${DRUPACK_CA_FILE-/data/runtime/app/cacert.pem}

# launch.php sets up the site, then replaces itself with the server.
exec "$DRUPACK_PHP" --data-dir /data --listen "0.0.0.0:$listen" "$@"
