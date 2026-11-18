<?php

declare(strict_types=1);

// Unit cases for build/pack/install-translations.php. Run with:
//   ./dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/install_translations_test.php"
// The constant loads the script for its functions alone.

define('DRUPACK_INSTALL_TRANSLATIONS_LIBRARY', true);
require __DIR__ . '/../../build/pack/install-translations.php';

require __DIR__ . '/cases.php';

test('core and each drupal.org project type get a lookup', function (): void {
    same(['admin_toolbar' => '3.6.0', 'drupal' => '11.4.11', 'gin' => '4.1.0', 'standard_plus' => '2.0.0'], translationProjects([
        ['name' => 'drupal/gin', 'type' => 'drupal-theme', 'version' => '4.1.0'],
        ['name' => 'drupal/core', 'type' => 'drupal-core', 'version' => '11.4.11'],
        ['name' => 'drupal/standard_plus', 'type' => 'drupal-profile', 'version' => '2.0.0'],
        ['name' => 'drupal/admin_toolbar', 'type' => 'drupal-module', 'version' => '3.6.0'],
    ]));
});

test("a project's Drupal version annotation names its release", function (): void {
    same(['token' => '8.x-1.15'], translationProjects([
        ['name' => 'drupal/token', 'type' => 'drupal-module', 'version' => '1.15.0', 'extra' => ['drupal' => ['version' => '8.x-1.15']]],
    ]));
});

test('a package outside drupal/ or of another type gets none', function (): void {
    same([], translationProjects([
        ['name' => 'acme/custom_module', 'type' => 'drupal-module', 'version' => '1.0.0'],
        ['name' => 'drupal/core-recommended', 'type' => 'metapackage', 'version' => '11.4.11'],
        ['name' => 'symfony/console', 'type' => 'library', 'version' => '7.4.0'],
    ]));
});

exit(runCases());
