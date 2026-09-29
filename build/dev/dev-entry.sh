#!/bin/sh
set -e

# launch.php runs this script as the launcher's lay-app word: `lay-app DATA
# WRITABLE...` lays the site's own application in DATA/app from the overlaid copy
# in DATA/runtime/app, and `lay-app --check DATA` answers yes. Every start lays
# again, so a change to application/ applies. As in an upgrade, a restart
# replaces the files the release ships inside a writable directory.
if [ "$1" = lay-app ]; then
    shift
    if [ "$1" = --check ]; then
        exit 0
    fi
    data=$1
    shift
    # The writable list comes from the command line of a word anyone can run.
    for directory in "$@"; do
        case /$directory/ in
            //* | */../* | /./)
                printf 'writable directory is not a relative path inside the application: %s\n' "$directory" >&2
                exit 1
                ;;
        esac
    done
    rm -rf "$data/app.partial"
    cp -r "$data/runtime/app" "$data/app.partial"
    # An interrupted lay leaves the old application in .previous-app with no app.
    if [ -d "$data/app" ]; then
        rm -rf "$data/.previous-app"
        mv "$data/app" "$data/.previous-app"
    fi
    for directory in "$@"; do
        mkdir -p "$data/app.partial/$directory"
        if [ -d "$data/.previous-app/$directory" ]; then
            for entry in "$data/.previous-app/$directory"/* "$data/.previous-app/$directory"/.[!.]*; do
                if [ -e "$entry" ] || [ -L "$entry" ]; then
                    if [ ! -e "$data/app.partial/$directory/${entry##*/}" ] && [ ! -L "$data/app.partial/$directory/${entry##*/}" ]; then
                        cp -a "$entry" "$data/app.partial/$directory/"
                    fi
                fi
            done
        fi
    done
    mv "$data/app.partial" "$data/app"
    rm -rf "$data/.previous-app"
    exit 0
fi

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
# directory permits the unlink, the file mode does not permit a write. Drupal's
# status report takes the write bit off sites/default, so the directory gets it back.
replace() {
    mkdir -p "$(dirname "$2")"
    chmod u+w "$(dirname "$2")"
    rm -f "$2"
    cp "$1" "$2"
}
# The layer drupack-build's layEngine lays over the site: the engine's application
# tree without its unit files, then the site files build/ holds for sites/default.
# find lists no file under the vendor link a developer's checkout may hold.
cd /dev-application
find . -type f ! -path './tests/*' | while read -r name; do
    replace "$name" "/data/runtime/app/$name"
done
cd /data/runtime/app
docroot=$(python3 -c 'import json; print(json.load(open("site.json"))["docroot"])')
replace /dev-build/site-settings.php "$docroot/sites/default/settings.php"
replace /dev-build/site-templates.php "$docroot/sites/default/site-templates.php"

# A release start gets these from its launcher, which names the application it
# unpacked, the site's name, php.ini's directory and the packed trust bundle. A
# caller's own bundle still wins.
export DRUPACK_RUNTIME_APP_DIR=/data/runtime/app
# The lay-app word above; a release start's launcher names itself here.
export DRUPACK_RUNTIME_LAUNCHER=/dev-entry.sh
DRUPACK_RUNTIME_NAME=$(python3 -c 'import json; print(json.load(open("site.json"))["name"])')
export DRUPACK_RUNTIME_NAME
export PHPRC=/data/runtime/app
export DRUPACK_CA_FILE=${DRUPACK_CA_FILE-/data/runtime/app/cacert.pem}

# launch.php sets up the site, then replaces itself with the server.
exec "$DRUPACK_PHP" --data-dir /data --listen "0.0.0.0:$listen" "$@"
