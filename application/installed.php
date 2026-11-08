<?php

// Prints yes when the database the project's settings name holds an installed Drupal
// site, no when it holds none. A database it cannot reach ends this script with an error.

use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

[, $project, $docroot] = $argv;
$autoloader = require "$project/vendor/autoload.php";
chdir($docroot);
// Reads settings.php and its database connection, and boots no module.
DrupalKernel::createFromRequest(Request::create('/'), $autoloader, 'prod', false, $docroot);
$database = Database::getConnectionInfo()['default'];
// SQLite creates a missing database file on connect, and no installer has run on a missing file.
if ($database['driver'] === 'sqlite' && !is_file($database['database'])) {
    exit('no');
}
// Every installed site holds key_value, and a database no installer has run holds none.
echo Database::getConnection()->schema()->tableExists('key_value') ? 'yes' : 'no';
