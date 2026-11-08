#!/bin/sh
set -e

# The packed demo executable at /dev-site starts the site, as a release does. Its
# launcher unpacks the application into a cache kept in the data directory, and this
# script lays the working tree's application over that copy first, so a change to
# application/ applies on the next start. The launcher itself is the one the build
# packed: a change to launcher/ needs a new build.
listen=$1
shift
export DRUPACK_CACHE_DIR=/data/dev-cache
# The version flag unpacks the application and starts no site.
/dev-site --version >/dev/null
unpacked=$(dirname "$(ls -t "$DRUPACK_CACHE_DIR"/app/*/.used | head -n 1)")

# The copied files keep the unpacked mode, so replace each by name: the directory
# permits the unlink, the file mode does not permit a write. Drupal's status report
# takes the write bit off sites/default, so the directory gets it back.
replace() {
    mkdir -p "$(dirname "$2")"
    chmod u+w "$(dirname "$2")"
    rm -f "$2"
    cp "$1" "$2"
}
# Lays what drupack-build's layEngine lays over the site: the engine's application
# tree without its unit files, then the site files build/ holds for sites/default.
# find lists no file under the vendor link a developer's checkout may hold.
lay() {
    (
        cd /dev-application
        find . -type f ! -path './tests/*' | while read -r name; do
            replace "$name" "$1/$name"
        done
    )
    replace /dev-build/site-settings.php "$1/$docroot/sites/default/settings.php"
    replace /dev-build/site-templates.php "$1/$docroot/sites/default/site-templates.php"
}
docroot=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["docroot"])' "$unpacked/site.json")
lay "$unpacked"
# A site whose contract names writable directories runs the copy the launcher laid in
# Site data. The first start lays the built release there, and the next applies this.
if [ -d /data/app ]; then
    lay /data/app
fi

exec /dev-site start --foreground --data-dir /data --listen "0.0.0.0:$listen" "$@"
