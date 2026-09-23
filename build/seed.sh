#!/usr/bin/env bash
set -euo pipefail

# Installs the Seed site into APPLICATION/seed from the site's recipe, with the PHP
# the build runs. A SQLite first start copies this seed instead of installing.
usage='Usage: build/seed.sh APPLICATION PHP RECIPE'
application=${1:?$usage}
php=${2:?$usage}
recipe=${3:?$usage}

# Drush parses a relative SQLite URL only; settings.php names the file by absolute path.
cd "$application"

drush() {
    DRUPACK_RUNTIME_DATA_DIR="$application/seed" DRUPACK_RUNTIME_HOST=localhost \
        "$php" php-cli "$application/vendor/drush/drush/drush.php" --root="$application/web" "$@"
}

mkdir -p "$application/seed/private" "$application/seed/tmp" "$application/seed/config" \
    "$application/web/sites/default/files"
printf 'drupack-seed-hash-salt' > "$application/seed/hash_salt"
cp "$application/settings.php" "$application/web/sites/default/settings.php"
sed -i "s|__DRUPACK_DATABASE_CONFIGURATION__|['driver' => 'sqlite', 'database' => '$application/seed/site.sqlite', 'namespace' => 'Drupal\\\\sqlite\\\\Driver\\\\Database\\\\sqlite', 'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/']|" \
    "$application/web/sites/default/settings.php"
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
mv "$application/web/sites/default/files" "$application/seed/files"
printf '%s\n%s\n' '<?php' "require getenv('DRUPACK_RUNTIME_DATA_DIR') . DIRECTORY_SEPARATOR . 'settings.php';" \
    > "$application/web/sites/default/settings.php"
