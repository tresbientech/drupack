<?php

declare(strict_types=1);

// Unit cases for the functions in application/launch.php and the site operations of
// application/operate.php. Run with:
//   ./dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/launch_test.php"
// launch.php requires a vendor/autoload.php whose lock targets a PHP the host may lack, so
// the bundled runtime runs this file.

define('DRUPACK_LAUNCH_LIBRARY', true);
require __DIR__ . '/../launch.php';
define('DRUPACK_SERVE_LIBRARY', true);
require __DIR__ . '/../operate.php';

use Drupack\Support\Need;
use Drupack\Support\SiteContract;
use Drupack\Support\SiteData;
use Drupack\Support\Step;

const OPTION_VARIABLES = [
    'DRUPACK_DATABASE',
    'DRUPACK_DB_HOST',
    'DRUPACK_DB_PORT',
    'DRUPACK_DB_NAME',
    'DRUPACK_DB_USER',
    'DRUPACK_DB_PASSWORD',
    'DRUPACK_ADMIN_USER',
    'DRUPACK_ADMIN_PASSWORD',
    'DRUPACK_SITE_NAME',
];

// The Site contract of site.json beside this file, which the Go build's tests keep equal to
// what a build writes. Named fields replace the reference file's values.
function contract(mixed ...$fields): SiteContract
{
    return new SiteContract(...$fields + get_object_vars(SiteContract::read(__DIR__ . '/site.json')));
}
// The launcher exports the executable's name to every hop; this file runs outside one.
const FIXTURE_NAME = 'fixture-site';
putenv('DRUPACK_RUNTIME_NAME=' . FIXTURE_NAME);

require __DIR__ . '/cases.php';
$scratches = [];

// Clears every start input variable, then sets the named ones, and returns the options they give.
function parse(array $environment = []): array
{
    foreach (OPTION_VARIABLES as $name) {
        putenv(array_key_exists($name, $environment) ? "$name={$environment[$name]}" : $name);
    }
    return startOptions(contract());
}

function scratch(): string
{
    $path = sys_get_temp_dir() . '/drupack-launch-test-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0700, true)) {
        throw new RuntimeException("Cannot create $path");
    }
    $GLOBALS['scratches'][] = $path;
    return $path;
}

// A complete MySQL connection. Overrides win, including an override to null.
function connection(array $overrides = []): array
{
    return $overrides + [
        'database' => 'mysql',
        'db-host' => '127.0.0.1',
        'db-port' => null,
        'db-name' => 'drupal',
        'db-user' => 'drupack',
        'db-password' => 'secret',
    ];
}

test('the start inputs default to sqlite and the site name, and an environment variable sets one', function (): void {
    $options = parse();
    same('sqlite', $options['database']);
    same('Fixture Site', $options['site-name']);
    same(null, $options['db-host']);
    $options = parse(['DRUPACK_SITE_NAME' => 'From Environment', 'DRUPACK_DB_HOST' => 'db.test']);
    same('From Environment', $options['site-name']);
    same('db.test', $options['db-host']);
    parse();
});

test('a site without a recipe refuses a sqlite first start, naming both servers', function (): void {
    foreach (['has no recipe to seed a SQLite site', '--database mysql', '--database pgsql'] as $needle) {
        throws($needle, fn() => requireNeeds([Step::Seed, Step::Settings, Step::Administrator], connection(['database' => 'sqlite']), contract(recipe: '')));
    }
});

test('a site without a recipe starts on a database server', function (): void {
    requireNeeds([Step::Settings, Step::Install, Step::Modules], connection(), contract(recipe: ''));
    requireNeeds([Step::Adopt], connection(), contract(recipe: ''));
});

test('a site with a recipe seeds sqlite', function (): void {
    requireNeeds([Step::Seed, Step::Settings, Step::Administrator], connection(['database' => 'sqlite']), contract(recipe: 'recipes/fixture'));
});

test('sqlite needs no connection details', function (): void {
    $options = connection(['database' => 'sqlite', 'db-host' => null, 'db-name' => null,
        'db-user' => null, 'db-password' => null]);
    requireNeeds([Step::Settings, Step::Install, Step::Modules], $options, contract());
});

test('a resumed start reads its connection from recorded settings', function (): void {
    $options = connection(['db-host' => null, 'db-name' => null, 'db-user' => null, 'db-password' => null]);
    requireNeeds([Step::Modules], $options, contract());
});

test('a first start on a database server needs every connection detail', function (): void {
    requireNeeds([Step::Settings, Step::Install, Step::Modules], connection(), contract());
    foreach (['db-host', 'db-name', 'db-user', 'db-password'] as $name) {
        throws(
            'Missing database connection details for mysql',
            fn() => requireNeeds([Step::Settings, Step::Install, Step::Modules], connection([$name => null]), contract()),
        );
    }
});

// A runtime whose Drush prints one answer per command name, and nothing for any other.
// drushField runs it as RUNTIME php-cli DRUSH COMMAND, from the application directory.
function fakeDrush(array $answers): string
{
    $directory = scratch();
    $cases = '';
    foreach ($answers as $command => $answer) {
        $cases .= "  $command) echo " . escapeshellarg($answer) . " ;;\n";
    }
    file_put_contents("$directory/drupack", "#!/bin/sh\ncase \"\$3\" in\n$cases esac\n");
    chmod("$directory/drupack", 0700);
    putenv("DRUPACK_RUNTIME_APP_DIR=$directory");
    return "$directory/drupack";
}

// Site data whose initialization progress names $steps, as a start that stopped partway leaves it.
function siteOwing(array $steps): SiteData
{
    $directory = scratch();
    file_put_contents("$directory/installation-progress", json_encode($steps));
    return new SiteData($directory);
}

test('the steps a start reads are checked against its options', function (): void {
    throws('Missing database connection details for mysql',
        fn() => owedSteps(siteOwing(['settings', 'install', 'modules']), connection(['db-password' => null]), contract()));
    [$steps] = owedSteps(siteOwing(['modules']), connection(['db-password' => null]), contract());
    same([Step::Modules], $steps);
});

test('steps that owe the administrator get generated credentials, and others get none', function (): void {
    $options = connection(['admin-user' => null, 'admin-password' => null]);
    [, $owing] = owedSteps(siteOwing(['administrator', 'modules']), $options, contract());
    same('admin', $owing['admin-user']);
    same(64, strlen($owing['admin-password']));
    [, $owed] = owedSteps(siteOwing(['modules']), $options, contract());
    same(null, $owed['admin-password']);
});

test('an interrupted installation without its settings refuses before Drush runs', function (): void {
    $site = new SiteData(scratch());
    $site->startInstall();
    throws('holds an interrupted installation but no settings.php', fn() => installSite($site, connection(), fakeDrush([]), contract()));
});

test('a database that already holds a site is kept', function (): void {
    same(true, installSite(new SiteData(scratch()), connection(), fakeDrush(['status' => 'Successful']), contract()));
});

test('a site without a recipe refuses a database that holds no site', function (): void {
    throws('has no recipe to install one',
        fn() => installSite(new SiteData(scratch()), connection(), fakeDrush([]), contract(recipe: '')));
});

test('adoption refuses Site data whose database holds no site', function (): void {
    throws('holds settings but no installed site', fn() => adoptSite(scratch(), fakeDrush([])));
});

test('adoption refuses a site whose administrator accepts the seed password', function (): void {
    throws('still accepts the packaged seed password',
        fn() => adoptSite(scratch(), fakeDrush(['status' => 'Successful', 'php:eval' => 'yes'])));
});

test('adoption takes a site whose administrator has a password of its own', function (): void {
    adoptSite(scratch(), fakeDrush(['status' => 'Successful', 'php:eval' => 'no']));
});

test('the reference site.json reads as the Site contract', function (): void {
    same(['port' => 7225, 'recipe' => '', 'settings' => '', 'siteName' => 'Fixture Site', 'subdomain' => 'fixture', 'node' => true,
        'writable' => [], 'docroot' => 'web', 'mercure' => false, 'static' => null], get_object_vars(SiteContract::read(__DIR__ . '/site.json')));
});

test('check records whether Site data owes a step, and refuses options a step lacks', function (): void {
    parse();
    same(['installed' => false], checkSite(scratch(), contract(recipe: 'recipes/site')));
    $installed = scratch();
    touch("$installed/site-installed");
    same(['installed' => true], checkSite($installed, contract()));
    parse(['DRUPACK_DATABASE' => 'mysql']);
    throws('Missing database connection details for mysql', fn() => checkSite(scratch(), contract()));
    parse();
});

test('prepare runs the owed steps and records whether they needed credentials', function (): void {
    parse();
    $site = siteOwing(['administrator']);
    same(['adopted' => false, 'credentials' => true], prepare(fakeDrush([]), $site->directory, contract()));
    same(true, $site->installed());
    same(false, file_exists("$site->directory/installation-progress"));
    same('admin', getenv('DRUPACK_ADMIN_USER'), 'the administrator step reads a generated name');
    parse();
});

test('prepare on an installed site runs nothing and needed no credentials', function (): void {
    parse();
    $installed = scratch();
    touch("$installed/site-installed");
    same(['adopted' => false, 'credentials' => false], prepare(fakeDrush([]), $installed, contract()));
});

test('owed steps need a recipe, a connection and credentials by step', function (): void {
    same([Need::Connection, Need::Credentials], Step::owed([Step::Settings, Step::Install, Step::Modules]));
    same([Need::Recipe, Need::Connection, Need::Credentials], Step::owed([Step::Seed, Step::Settings, Step::Administrator]));
    same([], Step::owed([Step::Adopt]));
    same([], Step::owed([Step::Modules]));
    same([], Step::owed([]));
});

test('a start that needs no credentials generates none', function (): void {
    $options = ['admin-user' => null, 'admin-password' => null];
    same($options, administratorCredentials($options, [Step::Adopt]));
});

test('a start that installs generates an administrator password', function (): void {
    $filled = administratorCredentials(['admin-user' => null, 'admin-password' => null], [Step::Administrator]);
    same('admin', $filled['admin-user']);
    same(64, strlen((string) $filled['admin-password']));
});

test('a given administrator name and password survive', function (): void {
    $filled = administratorCredentials(['admin-user' => 'ann', 'admin-password' => 'given'], [Step::Install]);
    same('ann', $filled['admin-user']);
    same('given', $filled['admin-password']);
});

test('canonical rewrites backslashes as forward slashes on a host whose separator is one', function (): void {
    same('C:/Users/theno/Downloads', canonicalSeparator('C:\\Users\\theno\\Downloads', '\\'));
    same('/var/www/html', canonical('/var/www/html'), 'a path with no backslash comes back unchanged');
});

test('canonical leaves a backslash alone where it is not the separator', function (): void {
    same('a\\b', canonicalSeparator('a\\b', '/'), 'a backslash is a legal character in a Linux file name');
});

test('a database port defaults per backend', function (): void {
    same('3306', databasePort(['database' => 'mysql', 'db-port' => null]));
    same('5432', databasePort(['database' => 'pgsql', 'db-port' => null]));
    same('15432', databasePort(['database' => 'pgsql', 'db-port' => '15432']));
});

// settings.php hashes DRUPACK_RUNTIME_APP_DIR as the launcher exports it, in canonical form,
// with no normalising step of its own.
test('the deployment identifier hashes the exported application directory directly', function (): void {
    $data = scratch();
    file_put_contents("$data/hash_salt", 'test-hash-salt');
    $content = settings(__DIR__ . '/../settings.php', [
        'driver' => 'sqlite',
        'database' => "$data/site.sqlite",
        'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
        'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/',
    ], '');
    $path = "$data/settings-under-test.php";
    file_put_contents($path, $content);

    putenv("DRUPACK_RUNTIME_DATA_DIR=$data");
    putenv('DRUPACK_RUNTIME_HOST=localhost');

    $appDir = 'C:/Users/theno/AppData/Local/Drupack/runtime/app/r2e2893a48a83';
    putenv("DRUPACK_RUNTIME_APP_DIR=$appDir");
    // Settings::initialize() gives settings.php these two, beside the arrays below.
    $app_root = dirname(__DIR__);
    $class_loader = new \Composer\Autoload\ClassLoader();
    $databases = [];
    $settings = [];
    $config = [];
    require $path;

    putenv('DRUPACK_RUNTIME_APP_DIR');
    putenv('DRUPACK_RUNTIME_HOST');
    putenv('DRUPACK_RUNTIME_DATA_DIR');

    same(substr(hash('sha256', $appDir), 0, 16), $settings['deployment_identifier'],
        'the identifier hashes the exported value with no normalising step of its own');
});

test("the deployment identifier changes with the release a site's own application holds", function (): void {
    $data = scratch();
    $app = scratch();
    file_put_contents("$data/hash_salt", 'test-hash-salt');
    $path = "$data/settings-under-test.php";
    file_put_contents($path, settings(__DIR__ . '/../settings.php', ['driver' => 'sqlite', 'database' => "$data/site.sqlite"], ''));
    putenv("DRUPACK_RUNTIME_DATA_DIR=$data");
    putenv('DRUPACK_RUNTIME_HOST=localhost');
    putenv("DRUPACK_RUNTIME_APP_DIR=$app");
    $identifiers = [];
    foreach (['release-one', 'release-two'] as $release) {
        file_put_contents("$app/.release", $release);
        $app_root = dirname(__DIR__);
        $class_loader = new \Composer\Autoload\ClassLoader();
        $databases = [];
        $settings = [];
        $config = [];
        require $path;
        $identifiers[] = $settings['deployment_identifier'];
    }
    unlink("$app/.release");
    putenv('DRUPACK_RUNTIME_APP_DIR');
    putenv('DRUPACK_RUNTIME_HOST');
    putenv('DRUPACK_RUNTIME_DATA_DIR');
    same(true, $identifiers[0] !== $identifiers[1], 'two releases in one directory share an identifier');
});

test("the site's settings file loads after the engine's settings", function (): void {
    $data = scratch();
    $app = scratch();
    file_put_contents("$data/hash_salt", 'test-hash-salt');
    file_put_contents("$app/acme.settings.php", "<?php\n\$settings['update_free_access'] = TRUE;\n");
    $database = ['driver' => 'sqlite', 'database' => "$data/site.sqlite"];
    putenv("DRUPACK_RUNTIME_DATA_DIR=$data");
    putenv('DRUPACK_RUNTIME_HOST=localhost');
    putenv("DRUPACK_RUNTIME_APP_DIR=$app");
    foreach (['acme.settings.php' => true, '' => false] as $siteSettings => $expected) {
        $path = "$data/settings-under-test.php";
        file_put_contents($path, settings(__DIR__ . '/../settings.php', $database, (string) $siteSettings));
        $app_root = dirname(__DIR__);
        $class_loader = new \Composer\Autoload\ClassLoader();
        $databases = [];
        $settings = [];
        $config = [];
        require $path;
        same($expected, $settings['update_free_access'], "site settings '$siteSettings'");
    }
    putenv('DRUPACK_RUNTIME_APP_DIR');
    putenv('DRUPACK_RUNTIME_HOST');
    putenv('DRUPACK_RUNTIME_DATA_DIR');
});

test('the public files template loader resolves the files address in the files directory', function (): void {
    require_once __DIR__ . '/../support/PublicFilesTemplateLoader.php';
    $files = scratch();
    $outside = scratch();
    file_put_contents("$files/component.html.twig", 'compiled');
    file_put_contents("$outside/outside.html.twig", 'outside');
    putenv("DRUPACK_RUNTIME_FILES_DIR=$files");
    $loader = new \Drupack\Support\PublicFilesTemplateLoader();
    putenv('DRUPACK_RUNTIME_FILES_DIR');
    same(true, $loader->exists('sites/default/files/component.html.twig'));
    same('compiled', $loader->getSourceContext('sites/default/files/component.html.twig')->getCode());
    same(false, $loader->exists('component.html.twig'), 'a name outside the address');
    same(false, $loader->exists('sites/default/files/../' . basename($outside) . '/outside.html.twig'),
        'a name climbing out of the files directory');
});

$status = runCases();

foreach ($scratches as $path) {
    foreach (glob("$path/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($path);
}

exit($status);
