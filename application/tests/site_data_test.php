<?php

declare(strict_types=1);

// Unit cases for Drupack\Support\SiteData. Run with:
//   ./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/site_data_test.php"
// launch.php registers the support namespace and loads process.php, whose
// executableName() and directory() the class calls.

define('DRUPACK_LAUNCH_LIBRARY', true);
require __DIR__ . '/../launch.php';
require __DIR__ . '/cases.php';

use Drupack\Support\SiteData;

const FIXTURE_NAME = 'fixture-site';
putenv('DRUPACK_RUNTIME_NAME=' . FIXTURE_NAME);
$scratches = [];

function scratch(): SiteData
{
    $path = sys_get_temp_dir() . '/drupack-site-data-test-' . bin2hex(random_bytes(8));
    if (!mkdir($path, 0700, true)) {
        throw new RuntimeException("Cannot create $path");
    }
    $GLOBALS['scratches'][] = $path;
    return new SiteData($path);
}

// A $run that records each call and answers from $found, keyed by step.
function recorder(array &$calls, array $found = []): callable
{
    return function (string $step, bool $firstEver, bool $adopted) use (&$calls, $found): bool {
        $calls[] = [$step, $firstEver, $adopted];
        return $found[$step] ?? false;
    };
}

// The state files a finished initialization leaves behind, beside the site's own files.
function stateFiles(SiteData $site): array
{
    return array_values(array_intersect(
        ['site-installed', 'installation-progress', 'site-adopted', 'first-install'],
        array_map('basename', glob("$site->directory/*") ?: []),
    ));
}

test('a finished site has no remaining steps', function (): void {
    $site = scratch();
    file_put_contents("$site->directory/site-installed", '');
    same([], $site->steps('sqlite'));
});

test('recorded progress names the remaining steps', function (): void {
    $site = scratch();
    file_put_contents("$site->directory/installation-progress", json_encode(['modules']));
    same(['modules'], $site->steps('pgsql'));
});

test('the finished marker beats recorded progress', function (): void {
    $site = scratch();
    file_put_contents("$site->directory/site-installed", '');
    file_put_contents("$site->directory/installation-progress", json_encode(['modules']));
    same([], $site->steps('pgsql'));
});

test('unreadable progress refuses', function (): void {
    $site = scratch();
    file_put_contents("$site->directory/installation-progress", 'not json');
    throws('Cannot read the recorded initialization progress', fn() => $site->steps('sqlite'));
});

test('settings without a marker adopt the site', function (): void {
    $site = scratch();
    file_put_contents($site->settings(), '');
    same(['adopt'], $site->steps('sqlite'));
});

test('a database without settings refuses', function (): void {
    $site = scratch();
    file_put_contents($site->database(), '');
    throws('holds a database without settings', fn() => $site->steps('sqlite'));
});

test('settings beat a database when neither marker exists', function (): void {
    $site = scratch();
    file_put_contents($site->settings(), '');
    file_put_contents($site->database(), '');
    same(['adopt'], $site->steps('sqlite'));
});

test('an empty site data directory seeds sqlite', function (): void {
    same(['seed', 'settings', 'administrator'], scratch()->steps('sqlite'));
});

test('an empty site data directory installs on a database server', function (): void {
    $site = scratch();
    same(['settings', 'install', 'modules'], $site->steps('pgsql'));
    same(true, file_exists("$site->directory/first-install"), 'a fresh database is recorded before the install step');
});

test('an unreadable listener record refuses', function (): void {
    $site = scratch();
    file_put_contents("$site->directory/listener", 'not json');
    throws('Cannot read the recorded listener', fn() => $site->listener());
});

test('a refusal names the executable the launcher exported', function (): void {
    $site = scratch();
    file_put_contents("$site->directory/listener", 'not json');
    putenv('DRUPACK_RUNTIME_NAME=acme-intranet');
    try {
        throws('then start acme-intranet again', fn() => $site->listener());
    } finally {
        putenv('DRUPACK_RUNTIME_NAME=' . FIXTURE_NAME);
    }
});

test('a listener record missing its host refuses', function (): void {
    $site = scratch();
    file_put_contents("$site->directory/listener", json_encode(['listen' => '127.0.0.1:8080']));
    throws('Recorded listener has no host', fn() => $site->listener());
});

test('a recorded listener reads back whole, and an old record names no files directory', function (): void {
    $site = scratch();
    same(null, $site->listener());
    $site->recordListener('127.0.0.1:8080', 'example.test', '/srv/files');
    same(['listen' => '127.0.0.1:8080', 'host' => 'example.test', 'files-dir' => '/srv/files'], $site->listener());
    file_put_contents("$site->directory/listener", json_encode(['listen' => '127.0.0.1:8080', 'host' => 'localhost']));
    same(null, $site->listener()['files-dir']);
});

test('the recorded connection is the database block of the recorded settings', function (): void {
    $site = scratch();
    $database = ['driver' => 'pgsql', 'host' => 'db', 'port' => 5432, 'database' => 'site', 'username' => 'u', 'password' => 'p'];
    file_put_contents($site->settings(), '<?php $databases["default"]["default"] = ' . var_export($database, true) . ';');
    same($database, $site->connection());
});

test('a finished initialization leaves no progress, adoption or first-install file behind', function (): void {
    $site = scratch();
    $steps = $site->steps('pgsql');
    $calls = [];
    same(true, $site->initialize($steps, recorder($calls, ['install' => true])));
    same(['site-installed'], stateFiles($site));
    same(true, $site->installed());
    same([], $site->steps('pgsql'));
});

test('adoption passes to every step after the one that found a site', function (): void {
    $site = scratch();
    $calls = [];
    $site->initialize(['settings', 'install', 'modules'], recorder($calls, ['install' => true]));
    same([['settings', false, false], ['install', false, false], ['modules', false, true]], $calls);
});

test('a step that throws leaves progress naming it onward', function (): void {
    $site = scratch();
    $steps = $site->steps('sqlite');
    throws('seed copy failed', fn() => $site->initialize($steps, function (string $step): bool {
        if ($step === 'settings') {
            throw new RuntimeException('seed copy failed');
        }
        return false;
    }));
    same(['settings', 'administrator'], $site->steps('sqlite'));
    same(false, $site->installed());
});

test('first-install passes true only for a database the steps recorded as new', function (): void {
    $fresh = scratch();
    $calls = [];
    $fresh->initialize($fresh->steps('pgsql'), recorder($calls));
    same([true, true, true], array_column($calls, 1));
    $resumed = scratch();
    file_put_contents("$resumed->directory/installation-progress", json_encode(['install', 'modules']));
    $calls = [];
    $resumed->initialize($resumed->steps('pgsql'), recorder($calls));
    same([false, false], array_column($calls, 1));
});

test('no steps runs nothing and adopts nothing', function (): void {
    $site = scratch();
    $calls = [];
    same(false, $site->initialize([], recorder($calls)));
    same([], $calls);
    same([], stateFiles($site));
});

$status = runCases();

foreach ($scratches as $path) {
    foreach (glob("$path/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($path);
}

exit($status);
