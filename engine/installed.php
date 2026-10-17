<?php

// Prints yes when the database the project's settings name holds an installed Drupal
// site, no when it holds none. A database it cannot reach ends this script with an error.
// php-cli runs it as `installed.php PROJECT DOCROOT`.

use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

[, $project, $docroot] = $argv;
$autoloader = require "$project/vendor/autoload.php";
chdir($docroot);
// Reads settings.php and its database connection, and boots no module.
DrupalKernel::createFromRequest(Request::create('/'), $autoloader, 'prod', false, $docroot);
// Every installed site holds key_value, and a database no installer has run holds none.
echo Database::getConnection()->schema()->tableExists('key_value') ? 'yes' : 'no';
