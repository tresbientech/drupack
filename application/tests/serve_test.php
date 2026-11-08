<?php

declare(strict_types=1);

// Unit cases for the functions in engine/serve.php and engine/operate.php. Run with:
//   ./dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/serve_test.php"
// The constant loads both for their functions alone.

define('DRUPACK_SERVE_LIBRARY', true);
require __DIR__ . '/../../engine/serve.php';
require __DIR__ . '/../../engine/operate.php';

require __DIR__ . '/cases.php';
$root = sys_get_temp_dir() . '/drupack-serve-test-' . bin2hex(random_bytes(8));

function project_with(string $name, array $files): string
{
    $project = "{$GLOBALS['root']}/$name";
    foreach ($files as $path => $content) {
        @mkdir(dirname("$project/$path"), 0700, true);
        file_put_contents("$project/$path", $content);
    }
    return $project;
}

test('the engine treats only 127.0.0.0/8 and ::1 as loopback', function () {
    foreach (['127.0.0.1' => true, '::1' => true, '0.0.0.0' => false, '::' => false, '10.0.0.2' => false] as $bind => $expected) {
        same($expected, loopback($bind), $bind);
    }
});

// A stub stands in for the engine's launcher: it prints $stdout, and $stderr then exits 1
// when $stderr is set.
function launcher_answering(string $name, string $stdout, string $stderr = ''): void
{
    $stub = project_with($name, ['launcher' => "#!/bin/sh\nprintf '%s' " . escapeshellarg($stdout)
        . ($stderr === '' ? '' : "\nprintf '%s\\n' " . escapeshellarg($stderr) . " >&2\nexit 1") . "\n"]);
    chmod("$stub/launcher", 0700);
    putenv("DRUPACK_RUNTIME_LAUNCHER=$stub/launcher");
}

test('the launcher\'s settings reach serve.php, static null when the launcher leaves it out', function () {
    launcher_answering('settings', '{"docroot":"web","mercure":true,"subdomain":"shop"}');
    same(['docroot' => 'web', 'mercure' => true, 'subdomain' => 'shop', 'static' => null], composerSettings('/project'));
    launcher_answering('static-settings', '{"docroot":"web","mercure":false,"static":{"dynamic":["/search*"]}}');
    same(['docroot' => 'web', 'mercure' => false, 'static' => ['dynamic' => ['/search*']]], composerSettings('/project'));
});

test('the launcher\'s refusal reaches the reader as it is', function () {
    launcher_answering('refusal', '', '/project/composer.json: extra.drupack.mercure must be true or false, not "on"');
    throws('/project/composer.json: extra.drupack.mercure must be true or false, not "on"', fn () => composerSettings('/project'));
});

test('a project without Drush names the missing path', function () {
    $project = project_with('nodrush', ['composer.json' => '{}']);
    throws("$project/vendor/drush/drush/drush.php does not exist", fn () => drushScript($project));
});

test('a project on a Drupal core without its command line names the missing path', function () {
    $project = project_with('nocore', ['composer.json' => '{}']);
    throws("$project has no Drupal core command line: $project/vendor/bin/dr does not exist", fn () => coreScript($project));
});

test('the project is the nearest installed Composer project above', function () {
    $project = project_with('walk', ['vendor/autoload.php' => '<?php', 'web/core/composer.json' => '{}']);
    chdir("$project/web/core");
    same($project, project());
});

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

test('check records that no probe ran for a platform check alone', function () {
    $project = project_with('check-platform', ['composer.json' => '{}']);
    same(['installed' => null], check('', $project, null));
});

test('check refuses a project the runtime cannot run before it probes', function () {
    $project = project_with('check-refusal', ['composer.lock' => json_encode(['platform' => ['ext-drupack-absent' => '*']])]);
    throws('drupack-absent', fn () => check('', $project, "$project/web"));
});

// A stub stands in for the runtime: it answers Drush's user:login with $stdout, and with $stderr
// on standard error.
function runtime_minting(string $name, string $stdout, string $stderr = ''): string
{
    $stub = project_with($name, ['runtime' => "#!/bin/sh\nprintf '%s' " . escapeshellarg($stdout)
        . ($stderr === '' ? '' : "\nprintf '%s' " . escapeshellarg($stderr) . ' >&2') . "\n"]);
    chmod("$stub/runtime", 0700);
    return "$stub/runtime";
}

test('login records the link Drush mints at the URL', function () {
    $runtime = runtime_minting('login-link', 'http://localhost:3787/user/reset/1/abc');
    same(['link' => 'http://localhost:3787/user/reset/1/abc'], mintLoginLink($runtime, 'http://localhost:3787', 'drush.php', sys_get_temp_dir()));
});

test('login records the reason Drush gave when it mints no link', function () {
    $runtime = runtime_minting('login-failure', '', "Account is\nblocked");
    same(['failure' => 'Account is blocked'], mintLoginLink($runtime, 'http://localhost:3787', 'drush.php', sys_get_temp_dir()));
});

test('a folder keeps its state in a cache entry its canonical path names', function () {
    putenv('DRUPACK_RUNTIME_CACHE_ROOT=/cache');
    $entry = folderEntry('/home/reader/project');
    same('/cache/folders/', substr($entry, 0, strlen('/cache/folders/')));
    same(1, preg_match('/^[a-z][a-z0-9.-]{0,31}$/', basename($entry)));
    same($entry, folderEntry('/home/reader/project'));
    if ($entry === folderEntry('/home/reader/other')) {
        throw new RuntimeException('two folders share an entry');
    }
});

test('a wildcard listener is probed on its own family\'s loopback', function () {
    same('127.0.0.1', probeHost('0.0.0.0'));
    same('::1', probeHost('[::]'));
    same('::1', probeHost('::'));
    same('fd00::2', probeHost('[fd00::2]'));
    same('192.168.1.4', probeHost('192.168.1.4'));
    same('[::1]', urlHost(probeHost('[::]')));
});

$status = runCases();
chdir(sys_get_temp_dir());
exec('rm -rf ' . escapeshellarg($root));

exit($status);
