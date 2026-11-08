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
    'DRUPACK_DATA_DIR',
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

// Clears every option variable, then sets the named ones.
function parse(array $arguments, bool $drush = false, array $environment = []): array
{
    foreach (OPTION_VARIABLES as $name) {
        putenv(array_key_exists($name, $environment) ? "$name={$environment[$name]}" : $name);
    }
    return options($arguments, $drush, contract());
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

test('an option takes its value joined or separate', function (): void {
    [$joined] = parse(['--data-dir=/x']);
    [$separate] = parse(['--data-dir', '/x']);
    same('/x', $joined['data-dir']);
    same($joined, $separate);
});

test('an absent option falls back to its default', function (): void {
    [$options, $command] = parse([]);
    same('./data', $options['data-dir']);
    same('sqlite', $options['database']);
    same('Fixture Site', $options['site-name'], 'the site name defaults to the one site.json names');
    same(null, $options['listen'], 'the listener defaults after the recorded one is read');
    same(null, $options['host']);
    same([], $command);
});

test('an environment variable sets a default and an option beats it', function (): void {
    [$fromEnvironment] = parse([], false, ['DRUPACK_SITE_NAME' => 'From Environment']);
    same('From Environment', $fromEnvironment['site-name']);
    [$fromOption] = parse(['--site-name', 'From Option'], false, ['DRUPACK_SITE_NAME' => 'From Environment']);
    same('From Option', $fromOption['site-name']);
});

test('an empty environment variable reads as absent', function (): void {
    [$options] = parse([], false, ['DRUPACK_DATA_DIR' => '']);
    same('./data', $options['data-dir']);
});

test('a value may not start with a dash', function (): void {
    throws('Missing value for --data-dir', fn() => parse(['--data-dir', '--listen', '127.0.0.1:80']));
});

test('a trailing option with no value refuses', function (): void {
    throws('Missing value for --host', fn() => parse(['--host']));
});

test('an unknown argument refuses and names itself', function (): void {
    throws('Unknown argument: --unknown', fn() => parse(['--unknown', 'x']));
    throws('Unknown argument: status', fn() => parse(['status']));
});

test('--no-browser consumes no value', function (): void {
    [$options] = parse(['--no-browser', '--host', 'example.com']);
    same('1', $options['no-browser']);
    same('example.com', $options['host']);
});

test('under drush the first unknown argument starts the command', function (): void {
    [$options, $command] = parse(['--data-dir', '/x', 'status', '--fields=db'], true);
    same('/x', $options['data-dir']);
    same(['status', '--fields=db'], $command);
});

test('under drush an option after the command stays in the command', function (): void {
    [$options, $command] = parse(['status', '--data-dir', '/x'], true);
    same('./data', $options['data-dir']);
    same(['status', '--data-dir', '/x'], $command);
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

test('site data with no listener record changes nothing', function (): void {
    $options = ['listen' => null, 'host' => null];
    same($options, recordedListener($options, null));
});

test('a listener record fills an absent address, static address and host', function (): void {
    $record = ['listen' => '127.0.0.1:8080', 'host' => 'example.test', 'files-dir' => null, 'scheme' => 'https', 'static-listen' => '127.0.0.1:8081'];
    $filled = recordedListener(['listen' => null, 'static-listen' => null, 'host' => null], $record);
    same('127.0.0.1:8080', $filled['listen']);
    same('127.0.0.1:8081', $filled['static-listen']);
    same('example.test', $filled['host']);
    same('https', $filled['scheme']);
});

test('a listener record fills an absent files directory', function (): void {
    $record = ['listen' => '127.0.0.1:8080', 'host' => 'localhost', 'files-dir' => '/srv/files'];
    same('/srv/files', recordedFilesDirectory(['files-dir' => null], $record)['files-dir']);
});

test('an explicit files directory beats the listener record', function (): void {
    $record = ['listen' => '127.0.0.1:8080', 'host' => 'localhost', 'files-dir' => '/srv/files'];
    same('/srv/other', recordedFilesDirectory(['files-dir' => '/srv/other'], $record)['files-dir']);
});

test('a record without a files directory leaves the default to Site data', function (): void {
    same(null, recordedFilesDirectory(['files-dir' => null], ['listen' => '127.0.0.1:8080', 'host' => 'localhost', 'files-dir' => null])['files-dir']);
    same(null, recordedFilesDirectory(['files-dir' => null], null)['files-dir']);
});

test('an explicit option beats the listener record', function (): void {
    $record = ['listen' => '127.0.0.1:8080', 'host' => 'example.test', 'files-dir' => null, 'scheme' => 'http', 'static-listen' => '127.0.0.1:8081'];
    $given = recordedListener(['listen' => '127.0.0.1:9000', 'static-listen' => '127.0.0.1:9001', 'host' => null], $record);
    same('127.0.0.1:9000', $given['listen']);
    same('127.0.0.1:9001', $given['static-listen']);
    same('example.test', $given['host']);
});

test('a site URL takes the scheme and brackets an IPv6 host', function (): void {
    same('https://localhost:8080/', siteUrl('https', 'localhost', 8080));
    same('http://[::1]:8080/', siteUrl('http', '::1', 8080));
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

test('export points Drush at the bundle the last HTTPS start wrote, and keeps the public roots without one', function (): void {
    $directory = scratch();
    file_put_contents("$directory/ca-bundle.pem", "PUBLIC\n\nLOCAL\n");
    $public = getenv('DRUPACK_CA_FILE');
    try {
        putenv("DRUPACK_CA_FILE=$directory/public.pem");
        useTlsBundle($directory);
        same("$directory/ca-bundle.pem", getenv('DRUPACK_CA_FILE'));
        putenv("DRUPACK_CA_FILE=$directory/public.pem");
        useTlsBundle(scratch());
        same("$directory/public.pem", getenv('DRUPACK_CA_FILE'));
    } finally {
        putenv($public === false ? 'DRUPACK_CA_FILE' : "DRUPACK_CA_FILE=$public");
    }
});

test('a plan names the URL a reader types and the one a probe reaches', function (): void {
    same([
        'bind' => '127.0.0.1',
        'port' => 7225,
        'host' => 'demo.drupack.site',
        'scheme' => 'https',
        'url' => 'https://demo.drupack.site:7225/',
        'probe' => 'https://127.0.0.1:7225/',
    ], listenerPlan('127.0.0.1', 7225, 'demo.drupack.site', 'https'));
});

test('a plan on an IPv6 wildcard brackets its hosts and probes IPv6 loopback', function (): void {
    $plan = listenerPlan('::', 8080, '::1', 'http');
    same('http://[::1]:8080/', $plan['url']);
    same('http://[::1]:8080/', $plan['probe']);
});

test('the site environment names the plan\'s host and URL, the site\'s paths and the options given', function (): void {
    [$options] = parse(['--db-host', 'db.test'], environment: ['DRUPACK_DATABASE' => 'mysql']);
    same([
        'DRUPACK_RUNTIME_DATA_DIR' => '/data',
        'DRUPACK_RUNTIME_FILES_DIR' => '/data/files',
        'DRUPACK_RUNTIME_HOST' => '[::1]',
        'DRUSH_OPTIONS_URI' => 'http://[::1]:8080/',
        'TMPDIR' => '/data/runtime',
        'TEMP' => '/data/runtime',
        'TMP' => '/data/runtime',
        'XDG_DATA_HOME' => '/data/runtime',
        'XDG_CONFIG_HOME' => '/data/runtime',
        'DRUPACK_DATABASE' => 'mysql',
        'DRUPACK_DB_HOST' => 'db.test',
        'DRUPACK_SITE_NAME' => 'Fixture Site',
    ], siteEnvironment(listenerPlan('::', 8080, '::1', 'http'), '/data', '/data/files', '/data/runtime', $options, null));
});

test('a static site exports its export directory to the server and Drush, and another site exports none', function (): void {
    $plan = listenerPlan('127.0.0.1', 8080, 'localhost', 'http');
    same('/data/static', siteEnvironment($plan, '/data', '/data/files', '/data/runtime', connection(), '/data/static')['DRUPACK_RUNTIME_STATIC_ROOT']);
    same(false, array_key_exists('DRUPACK_RUNTIME_STATIC_ROOT', siteEnvironment($plan, '/data', '/data/files', '/data/runtime', connection(), null)));
});

test('a static plan names the static URL on the site host and scheme', function (): void {
    same(['bind' => '::', 'port' => 8081, 'url' => 'https://[::1]:8081/'], staticPlan('::', 8081, '::1', 'https'));
});

test('check records whether Site data owes a step, and refuses options a step lacks', function (): void {
    parse([]);
    same(['installed' => false], checkSite(scratch(), contract(recipe: 'recipes/site')));
    $installed = scratch();
    touch("$installed/site-installed");
    same(['installed' => true], checkSite($installed, contract()));
    parse([], environment: ['DRUPACK_DATABASE' => 'mysql']);
    throws('Missing database connection details for mysql', fn() => checkSite(scratch(), contract()));
    parse([]);
});

test('prepare runs the owed steps and records whether they needed credentials', function (): void {
    parse([]);
    $site = siteOwing(['administrator']);
    same(['adopted' => false, 'credentials' => true], prepare(fakeDrush([]), $site->directory, contract()));
    same(true, $site->installed());
    same(false, file_exists("$site->directory/installation-progress"));
    same('admin', getenv('DRUPACK_ADMIN_USER'), 'the administrator step reads a generated name');
    parse([]);
});

test('prepare on an installed site runs nothing and needed no credentials', function (): void {
    parse([]);
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

test('a relative data-dir resolves against the directory the reader started from', function (): void {
    putenv('DRUPACK_RUNTIME_CWD=/reader/start');
    same('/reader/start/data', fromStartDirectory('data'));
    same('/x', fromStartDirectory('/x'), 'an absolute path is returned unchanged');
    putenv('DRUPACK_RUNTIME_CWD');
});

test('a relative data-dir with no recorded start directory is returned unchanged', function (): void {
    putenv('DRUPACK_RUNTIME_CWD');
    same('./data', fromStartDirectory('./data'));
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

test('an export runs Tome at the static URL unless the words name one', function (): void {
    $url = 'http://localhost:8081/';
    same(['tome:static', '--yes', "--uri=$url", '--path-count=10'], exportCommand($url, ['--path-count=10']));
    foreach ([['--uri', 'https://x.test/'], ['--uri=https://x.test/'], ['-l', 'https://x.test/'], ['-lhttps://x.test/']] as $words) {
        same(array_merge(['tome:static', '--yes'], $words), exportCommand($url, $words), implode(' ', $words));
        same(array_merge(['tome:static', '--yes'], $words), exportCommand(null, $words), 'null ' . implode(' ', $words));
    }
    same(['tome:static', '--yes', "--uri=$url", '--', '--uri=x'], exportCommand($url, ['--', '--uri=x']), 'a --uri after -- is no option');
});

$status = runCases();

foreach ($scratches as $path) {
    foreach (glob("$path/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($path);
}

exit($status);
