#!/usr/bin/env bash
set -euo pipefail

# Installs the Seed site into APPLICATION/seed from the site's recipe, with the PHP
# the build runs. A SQLite first start copies this seed instead of installing.
usage='Usage: build/seed.sh APPLICATION PHP DOCROOT RECIPE'
application=${1:?$usage}
php=${2:?$usage}
docroot=$application/${3:?$usage}
recipe=${4:?$usage}

# Drush parses a relative SQLite URL only; settings.php names the file by absolute path.
cd "$application"

drush() {
    DRUPACK_RUNTIME_DATA_DIR="$application/seed" DRUPACK_RUNTIME_HOST=localhost \
        "$php" php-cli "$application/vendor/drush/drush/drush.php" --root="$docroot" "$@"
}

mkdir -p "$application/seed/private" "$application/seed/tmp" "$application/seed/config" \
    "$docroot/sites/default/files"
printf 'drupack-seed-hash-salt' > "$application/seed/hash_salt"
cp "$application/settings.php" "$docroot/sites/default/settings.php"
sed -i "s|__DRUPACK_DATABASE_CONFIGURATION__|['driver' => 'sqlite', 'database' => '$application/seed/site.sqlite', 'namespace' => 'Drupal\\\\sqlite\\\\Driver\\\\Database\\\\sqlite', 'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/']|" \
    "$docroot/sites/default/settings.php"
# launch.php's seedPassword() carries this password, and the first start replaces it.
drush site:install "$application/$recipe" --yes --db-url=sqlite://seed/site.sqlite \
    --account-name=drupack-admin --account-pass=drupack-seed-password
# Drupal CMS recipes enable automatic_updates and package_manager. The first stalls a
# cron request and the second cannot write into the read-only application, so the seed
# drops whichever of the two the site's recipe enabled.
unrunnable=$(drush pm:list --status=enabled --field=name | grep -xE 'automatic_updates|package_manager' || true)
if [ -n "$unrunnable" ]; then
    # shellcheck disable=SC2086 # one module name per word
    drush pm:uninstall $unrunnable --yes
fi
mv "$docroot/sites/default/files" "$application/seed/files"
cp "$(dirname -- "${BASH_SOURCE[0]}")/site-settings.php" "$docroot/sites/default/settings.php"
