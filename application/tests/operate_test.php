<?php

declare(strict_types=1);

// Unit cases for the engine's operations in application/operate.php. Run with:
//   ./dist/fixture-linux-amd64 php-cli "$PWD/application/tests/operate_test.php"
// The constant loads operate.php for its functions alone. The tests' Composer install supplies
// the version classes the engine reads from its composer.phar.

define('DRUPACK_OPERATE_LIBRARY', true);
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../operate.php';

require __DIR__ . '/cases.php';
$root = sys_get_temp_dir() . '/drupack-operate-test-' . bin2hex(random_bytes(8));

function project_with(string $name, array $files): string
{
    $project = "{$GLOBALS['root']}/$name";
    foreach ($files as $path => $content) {
        @mkdir(dirname("$project/$path"), 0700, true);
        file_put_contents("$project/$path", $content);
    }
    return $project;
}

test('a lock declaring an extension the runtime lacks is refused, naming its package', function () {
    $project = project_with('missing', ['composer.lock' => json_encode([
        'platform' => ['ext-json' => '*', 'ext-drupack-absent-root' => '*'],
        'packages' => [
            ['name' => 'acme/cache', 'require' => ['ext-drupack-absent' => '*', 'ext-zend-opcache' => '*']],
            ['name' => 'acme/plain', 'require' => ['php' => '>=8.3']],
        ],
    ])]);
    throws("  drupack-absent-root: $project/composer.json\n  drupack-absent: acme/cache", fn () => checkPlatform('', $project));
});

test('a lock declaring loaded extensions alone passes', function () {
    $project = project_with('present', ['composer.lock' => json_encode([
        'packages' => [['name' => 'acme/cache', 'require' => ['ext-zend-opcache' => '*', 'ext-pdo_sqlite' => '*']]],
    ])]);
    checkPlatform('', $project);
});

test('an extension version outside a constraint is refused, naming each declarer', function () {
    $project = project_with('outdated', ['composer.lock' => json_encode([
        'platform' => ['ext-json' => '>=99'],
        'packages' => [['name' => 'acme/old', 'require' => ['ext-JSON' => '<1']]],
    ])]);
    $version = phpversion('json');
    throws("  json $version does not satisfy >=99: $project/composer.json\n  json $version does not satisfy <1: acme/old", fn () => checkPlatform('', $project));
});

test('one refusal lists missing extensions and outdated versions', function () {
    $project = project_with('both', ['composer.lock' => json_encode([
        'packages' => [['name' => 'acme/mixed', 'require' => ['ext-drupack-absent' => '^1', 'ext-json' => '>=99']]],
    ])]);
    throws("  drupack-absent: acme/mixed\n$project/composer.lock declares PHP extension versions this runtime does not meet:\n  json ", fn () => checkPlatform('', $project));
});

test('versions inside their constraints pass', function () {
    $project = project_with('satisfied', ['composer.lock' => json_encode([
        'platform' => ['ext-json' => '>=8 <99'],
        'packages' => [['name' => 'acme/fine', 'require' => ['ext-JSON' => '^7 || >=8', 'ext-zend-opcache' => '>=8', 'php' => '>=99']]],
    ])]);
    checkPlatform('', $project);
});

test('a constraint that does not parse is an error', function () {
    $project = project_with('malformed', ['composer.lock' => json_encode(['platform' => ['ext-json' => 'not a constraint']])]);
    throws('Could not parse version constraint', fn () => checkPlatform('', $project));
});

test('an extension version reads as Composer reads it', function () {
    foreach ([
        '8.5.12-dev' => '8.5.12-dev',
        '3.1.2 (final)' => '3.1.2',
        '1.2.3.4 custom' => '1.2.3.4',
        'mysqlnd 8.5.11' => '0',
        '' => '0',
    ] as $reported => $version) {
        same($version, composerExtensionVersion((string) $reported));
    }
});

test('check refuses a project the runtime cannot run before it probes', function () {
    $project = project_with('check-refusal', ['composer.lock' => json_encode(['platform' => ['ext-drupack-absent' => '*']])]);
    throws('drupack-absent', fn () => check('', $project, "$project/web"));
});

$status = runCases();
chdir(sys_get_temp_dir());
exec('rm -rf ' . escapeshellarg($root));

exit($status);
