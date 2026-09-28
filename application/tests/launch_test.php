<?php

declare(strict_types=1);

// Unit cases for the pure functions in application/launch.php. Run with:
//   ./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/launch_test.php"
// launch.php requires vendor/autoload.php, whose lock targets a PHP the host
// may not have, so the bundled runtime runs this file. The constant loads
// launch.php for its functions alone; the guard above its main block answers
// to it.

define('DRUPACK_LAUNCH_LIBRARY', true);
require __DIR__ . '/../launch.php';

use Drupack\Support\SiteData;

// Every variable options() reads for a default.
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

// The site.json a build writes, reduced to what options() reads.
const FIXTURE_SITE = ['site_name' => 'Fixture Site'];
// The launcher exports the executable's name to every hop; this file runs outside one.
const FIXTURE_NAME = 'fixture-site';
putenv('DRUPACK_RUNTIME_NAME=' . FIXTURE_NAME);

require __DIR__ . '/cases.php';
$scratches = [];

// Clears every option variable, then sets the named ones, so a case reads the same
// defaults whatever the shell holds.
function parse(array $arguments, bool $drush = false, array $environment = []): array
{
    foreach (OPTION_VARIABLES as $name) {
        putenv(array_key_exists($name, $environment) ? "$name={$environment[$name]}" : $name);
    }
    return options($arguments, $drush, FIXTURE_SITE);
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
        throws($needle, fn() => requireSeed(['seed', 'settings', 'administrator'], ['recipe' => '']));
    }
});

test('a site without a recipe starts on a database server', function (): void {
    requireSeed(['settings', 'install', 'modules'], ['recipe' => '']);
    requireSeed(['adopt'], ['recipe' => '']);
});

test('a site with a recipe seeds sqlite', function (): void {
    requireSeed(['seed', 'settings', 'administrator'], ['recipe' => 'recipes/fixture']);
});

test('sqlite needs no connection details', function (): void {
    $options = connection(['database' => 'sqlite', 'db-host' => null, 'db-name' => null,
        'db-user' => null, 'db-password' => null]);
    requireDatabaseOptions($options, ['settings', 'install', 'modules']);
});

test('a resumed start reads its connection from recorded settings', function (): void {
    $options = connection(['db-host' => null, 'db-name' => null, 'db-user' => null, 'db-password' => null]);
    requireDatabaseOptions($options, ['modules']);
});

test('a first start on a database server needs every connection detail', function (): void {
    requireDatabaseOptions(connection(), ['settings', 'install', 'modules']);
    foreach (['db-host', 'db-name', 'db-user', 'db-password'] as $name) {
        throws(
            'Missing database connection details for mysql',
            fn() => requireDatabaseOptions(connection([$name => null]), ['settings', 'install', 'modules']),
        );
    }
});

test('site data with no listener record changes nothing', function (): void {
    $options = ['listen' => null, 'host' => null];
    same($options, recordedListener($options, null));
});

test('a listener record fills an absent address and host', function (): void {
    $record = ['listen' => '127.0.0.1:8080', 'host' => 'example.test', 'files-dir' => null];
    $filled = recordedListener(['listen' => null, 'host' => null], $record);
    same('127.0.0.1:8080', $filled['listen']);
    same('example.test', $filled['host']);
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
    $record = ['listen' => '127.0.0.1:8080', 'host' => 'example.test', 'files-dir' => null];
    $given = recordedListener(['listen' => '127.0.0.1:9000', 'host' => null], $record);
    same('127.0.0.1:9000', $given['listen']);
    same('example.test', $given['host']);
});

test('a served listener brackets an IPv6 host in its URL', function (): void {
    $site = new SiteData(scratch());
    $site->recordListener('[::]:8080', '::1', null);
    same(['::', 'http://[::1]:8080/'], servedListener($site, ['listen' => '127.0.0.1:7000', 'host' => 'localhost']));
});

test('a served listener without a record is the one this start asked for', function (): void {
    same(['127.0.0.1', 'http://localhost:7000/'], servedListener(new SiteData(scratch()), ['listen' => '127.0.0.1:7000', 'host' => 'localhost']));
});

test('only a loopback address opens a browser', function (): void {
    foreach (['127.0.0.1' => true, '127.0.0.2' => true, '::1' => true, '0.0.0.0' => false, '::' => false, '192.168.1.5' => false] as $bind => $expected) {
        same($expected, loopback($bind), $bind);
    }
});

test('an IPv6 wildcard is probed on IPv6 loopback', function (): void {
    $server = stream_socket_server('tcp://[::1]:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    try {
        same(true, portTaken('::', $port));
    } finally {
        fclose($server);
    }
});

test('the install and administrator steps require credentials', function (): void {
    same(true, credentialsRequired(['settings', 'install', 'modules']));
    same(true, credentialsRequired(['seed', 'settings', 'administrator']));
    same(false, credentialsRequired(['adopt']));
    same(false, credentialsRequired(['modules']));
    same(false, credentialsRequired([]));
});

test('a start that needs no credentials generates none', function (): void {
    $options = ['admin-user' => null, 'admin-password' => null];
    same($options, administratorCredentials($options, ['adopt']));
});

test('a start that installs generates an administrator password', function (): void {
    $filled = administratorCredentials(['admin-user' => null, 'admin-password' => null], ['administrator']);
    same('admin', $filled['admin-user']);
    same(64, strlen((string) $filled['admin-password']));
});

test('a given administrator name and password survive', function (): void {
    $filled = administratorCredentials(['admin-user' => 'ann', 'admin-password' => 'given'], ['install']);
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

test('the site token hashes the path, lowercased on windows', function (): void {
    $data = '/Users/Ann/Site Data';
    same(hash('sha256', windows() ? strtolower($data) : $data), siteToken($data));
    same(windows(), siteToken('/a/B') === siteToken('/a/b'),
        'windows spells one path in several casings, so the hash reads one');
});

test('a rendered template holds every value in place of its token', function (): void {
    $directory = scratch();
    file_put_contents("$directory/template", "http://:__DRUPACK_PORT__ {\n\tbind __DRUPACK_BIND__\n\troot * __DRUPACK_PORT__\n}\n");
    renderTemplate("$directory/template", ['PORT' => '8080', 'BIND' => '::1'], "$directory/Caddyfile");
    same("http://:8080 {\n\tbind ::1\n\troot * 8080\n}\n", file_get_contents("$directory/Caddyfile"));
    same(false, file_exists("$directory/Caddyfile.new"), 'the staging file was renamed away');
});

test('a template token without a value refuses', function (): void {
    $directory = scratch();
    file_put_contents("$directory/template", "bind __DRUPACK_BIND__ __DRUPACK_PORT__");
    throws('names __DRUPACK_PORT__, which has no value', fn() => renderTemplate("$directory/template", ['BIND' => '::1'], "$directory/Caddyfile"));
    same(false, file_exists("$directory/Caddyfile"));
});

test('a rendered value holding a double quote refuses', function (): void {
    $directory = scratch();
    file_put_contents("$directory/template", 'output file "__DRUPACK_LOG_PATH__"');
    throws('LOG_PATH must not contain a double quote', fn() => renderTemplate("$directory/template", ['LOG_PATH' => '/a"b'], "$directory/Caddyfile"));
});

test('the shipped Caddyfile names only the tokens a start supplies', function (): void {
    preg_match_all('/__DRUPACK_([A-Z_]+)__/', file_get_contents(__DIR__ . '/../Caddyfile'), $tokens);
    $names = array_values(array_unique($tokens[1]));
    sort($names);
    same(['BIND', 'DOCROOT', 'FILES_DIR', 'GUARDS', 'ID', 'LOG_PATH', 'PORT'], $names);
});

test('a database port defaults per backend', function (): void {
    same('3306', databasePort(['database' => 'mysql', 'db-port' => null]));
    same('5432', databasePort(['database' => 'pgsql', 'db-port' => null]));
    same('15432', databasePort(['database' => 'pgsql', 'db-port' => '15432']));
});

// The launcher that ships with this settings file always exports
// DRUPACK_RUNTIME_APP_DIR in its canonical form, so settings.php produces one
// identifier for whichever value it is handed, by hashing that value with no
// normalising step of its own.
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

// The command line is described in four places. options() is the contract, and the
// other three are asserted against it here. docs/adr/0014 records the decision.
const REPOSITORY = __DIR__ . '/../..';

// runtime/entrypoint.go answers these two itself, so neither reaches an option key.
const USAGE_NON_OPTIONS = ['help', 'version'];

function optionNames(string $text): array
{
    preg_match_all('/--([a-z][a-z-]*)/', $text, $found);
    $names = array_values(array_unique(array_diff($found[1], USAGE_NON_OPTIONS)));
    sort($names);
    return $names;
}

function parserOptions(): array
{
    $names = array_keys(parse([])[0]);
    sort($names);
    return $names;
}

function entrypointOptions(): array
{
    $source = (string) file_get_contents(REPOSITORY . '/runtime/entrypoint.go');
    // The Options: block alone. The examples under it repeat options and add --dry-run.
    if (!preg_match('/\nOptions:\n(.*?)\nCommands:\n/s', $source, $block)) {
        throw new RuntimeException('entrypoint.go has no Options: block ending at Commands:');
    }
    return optionNames($block[1]);
}

function referenceOptions(): array
{
    $page = (string) file_get_contents(REPOSITORY . '/docs/cli.md');
    // The option table alone. Its first column holds one option per row.
    preg_match_all('/^\| `(--[a-z-]+)` \|/m', $page, $rows);
    return optionNames(implode(' ', $rows[1]));
}

test('the help constant names every option the parser accepts', function () {
    same(parserOptions(), optionNames(HELP));
});

test('the entrypoint usage names every option the parser accepts', function () {
    same(parserOptions(), entrypointOptions());
});

test('docs/cli.md names every option the parser accepts', function () {
    same(parserOptions(), referenceOptions());
});

$status = runCases();

foreach ($scratches as $path) {
    foreach (glob("$path/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($path);
}

exit($status);
