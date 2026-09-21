<?php

declare(strict_types=1);

// Unit cases for the pure functions in runtime/launch.php. Run with:
//   php tests/unit/launch_test.php
// No product binary, no container, no dependency. The constant loads launch.php
// for its functions alone; the guard above its main block answers to it.

define('DRUPACK_LAUNCH_LIBRARY', true);
require __DIR__ . '/../../runtime/launch.php';

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

$cases = [];
$scratches = [];

function test(string $name, callable $case): void
{
    $GLOBALS['cases'][$name] = $case;
}

function same(mixed $expected, mixed $actual, string $note = ''): void
{
    if ($expected === $actual) {
        return;
    }
    throw new RuntimeException(($note === '' ? '' : "$note: ")
        . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function throws(string $needle, callable $case): void
{
    try {
        $case();
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), $needle)) {
            throw new RuntimeException("expected a message holding \"$needle\", got \"{$error->getMessage()}\"");
        }
        return;
    }
    throw new RuntimeException("expected a throw holding \"$needle\", nothing was thrown");
}

// Clears every option variable, then sets the named ones, so a case reads the same
// defaults whatever the shell holds.
function parse(array $arguments, bool $drush = false, array $environment = []): array
{
    foreach (OPTION_VARIABLES as $name) {
        putenv(array_key_exists($name, $environment) ? "$name={$environment[$name]}" : $name);
    }
    return options($arguments, $drush);
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
    same('Drupal Mercury Demo', $options['site-name']);
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

test('a finished site has no remaining steps', function (): void {
    $data = scratch();
    file_put_contents(markerPath($data), '');
    same([], remainingSteps($data, 'sqlite'));
});

test('recorded progress names the remaining steps', function (): void {
    $data = scratch();
    file_put_contents(progressPath($data), json_encode(['modules']));
    same(['modules'], remainingSteps($data, 'pgsql'));
});

test('the finished marker beats recorded progress', function (): void {
    $data = scratch();
    file_put_contents(markerPath($data), '');
    file_put_contents(progressPath($data), json_encode(['modules']));
    same([], remainingSteps($data, 'pgsql'));
});

test('unreadable progress refuses', function (): void {
    $data = scratch();
    file_put_contents(progressPath($data), 'not json');
    throws('Cannot read the recorded initialization progress', fn() => remainingSteps($data, 'sqlite'));
});

test('settings without a marker adopt the site', function (): void {
    $data = scratch();
    file_put_contents("$data/settings.php", '');
    same(['adopt'], remainingSteps($data, 'sqlite'));
});

test('a database without settings refuses', function (): void {
    $data = scratch();
    file_put_contents("$data/site.sqlite", '');
    throws('holds a database without settings', fn() => remainingSteps($data, 'sqlite'));
});

test('settings beat a database when neither marker exists', function (): void {
    $data = scratch();
    file_put_contents("$data/settings.php", '');
    file_put_contents("$data/site.sqlite", '');
    same(['adopt'], remainingSteps($data, 'sqlite'));
});

test('an empty site data directory seeds sqlite', function (): void {
    same(['seed', 'settings', 'administrator'], remainingSteps(scratch(), 'sqlite'));
});

test('an empty site data directory installs on a database server', function (): void {
    $data = scratch();
    same(['settings', 'install', 'modules'], remainingSteps($data, 'pgsql'));
    same(true, file_exists(firstEverPath($data)), 'a fresh database is recorded before the install step');
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
    same($options, recordedListener($options, scratch()));
});

test('a listener record fills an absent address and host', function (): void {
    $data = scratch();
    writeListener($data, ['listen' => '127.0.0.1:8080', 'host' => 'example.test']);
    $filled = recordedListener(['listen' => null, 'host' => null], $data);
    same('127.0.0.1:8080', $filled['listen']);
    same('example.test', $filled['host']);
});

test('an explicit option beats the listener record', function (): void {
    $data = scratch();
    writeListener($data, ['listen' => '127.0.0.1:8080', 'host' => 'example.test']);
    $given = recordedListener(['listen' => '127.0.0.1:9000', 'host' => null], $data);
    same('127.0.0.1:9000', $given['listen']);
    same('example.test', $given['host']);
});

test('an unreadable listener record refuses', function (): void {
    $data = scratch();
    file_put_contents(listenerPath($data), 'not json');
    throws('Cannot read the recorded listener', fn() => recordedListener(['listen' => null, 'host' => null], $data));
});

test('a listener record missing its host refuses', function (): void {
    $data = scratch();
    file_put_contents(listenerPath($data), json_encode(['listen' => '127.0.0.1:8080']));
    throws('Recorded listener has no host', fn() => recordedListener(['listen' => null, 'host' => null], $data));
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

test('a database port defaults per backend', function (): void {
    same('3306', databasePort(['database' => 'mysql', 'db-port' => null]));
    same('5432', databasePort(['database' => 'pgsql', 'db-port' => null]));
    same('15432', databasePort(['database' => 'pgsql', 'db-port' => '15432']));
});

test('site data file names hang off the directory', function (): void {
    same('/x/site-installed', markerPath('/x'));
    same('/x/installation-progress', progressPath('/x'));
    same('/x/site-adopted', adoptedPath('/x'));
    same('/x/first-install', firstEverPath('/x'));
    same('/x/listener', listenerPath('/x'));
});

// An upgraded Windows site's application directory still carries the native,
// backslash form it was keyed with before the launcher exported the canonical
// one. Hashing a slash-normalised value keeps both forms behind one container.
test('the deployment identifier keys the native and canonical form alike', function (): void {
    $data = scratch();
    file_put_contents("$data/hash_salt", 'test-hash-salt');
    $content = settings(__DIR__ . '/../../runtime/settings.php', [
        'driver' => 'sqlite',
        'database' => "$data/site.sqlite",
        'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
        'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/',
    ]);
    $path = "$data/settings-under-test.php";
    file_put_contents($path, $content);

    putenv("DRUPACK_RUNTIME_DATA_DIR=$data");
    putenv('DRUPACK_RUNTIME_HOST=localhost');

    putenv('DRUPACK_RUNTIME_APP_DIR=C:\\Users\\theno\\AppData\\Local\\Drupack\\runtime\\app\\r2e2893a48a83');
    $databases = [];
    $settings = [];
    $config = [];
    require $path;
    $native = $settings['deployment_identifier'];

    putenv('DRUPACK_RUNTIME_APP_DIR=C:/Users/theno/AppData/Local/Drupack/runtime/app/r2e2893a48a83');
    $databases = [];
    $settings = [];
    $config = [];
    require $path;
    $canonical = $settings['deployment_identifier'];

    putenv('DRUPACK_RUNTIME_APP_DIR');
    putenv('DRUPACK_RUNTIME_HOST');
    putenv('DRUPACK_RUNTIME_DATA_DIR');

    same($native, $canonical, 'the native and canonical form of the same directory must key one container');
});

$failed = 0;
foreach ($cases as $name => $case) {
    try {
        $case();
        fwrite(STDOUT, "ok   $name\n");
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDOUT, "FAIL $name\n       {$error->getMessage()}\n");
    }
}

foreach ($scratches as $path) {
    foreach (glob("$path/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($path);
}

$total = count($cases);
fwrite(STDOUT, $failed === 0 ? "\n$total cases passed\n" : "\n$failed of $total cases failed\n");
exit($failed === 0 ? 0 : 1);
