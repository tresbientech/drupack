<?php

// Prints the tome_static_directory the project's settings.php sets, or nothing when it sets
// none. A value other than a non-empty string ends this script with an error.

use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

[, $project, $docroot] = $argv;
$autoloader = require "$project/vendor/autoload.php";
chdir($docroot);
// Reads settings.php alone: no kernel boots and no database opens.
Settings::initialize($docroot, DrupalKernel::findSitePath(Request::create('/')), $autoloader);
$directory = Settings::get('tome_static_directory');
if ($directory !== null && (!is_string($directory) || $directory === '')) {
    fwrite(STDERR, "tome_static_directory in the project's settings.php must name a directory.\n");
    exit(1);
}
echo $directory ?? '';
