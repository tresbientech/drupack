<?php

declare(strict_types=1);

// Unit cases for the engine's operations in application/operate.php. Run with:
//   ./dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/operate_test.php"
// The constant loads operate.php for its functions alone.

define('DRUPACK_OPERATE_LIBRARY', true);
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

test('check refuses a project the runtime cannot run before it probes', function () {
    $project = project_with('check-refusal', ['composer.lock' => json_encode(['platform' => ['ext-drupack-absent' => '*']])]);
    throws('drupack-absent', fn () => check('', $project, "$project/web"));
});

$status = runCases();
chdir(sys_get_temp_dir());
exec('rm -rf ' . escapeshellarg($root));

exit($status);
