<?php

declare(strict_types=1);

// Unit cases for the functions in engine/serve.php. Run with:
//   ./dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/serve_test.php"
// The constant loads serve.php for its functions alone.

define('DRUPACK_SERVE_LIBRARY', true);
require __DIR__ . '/../../engine/serve.php';

require __DIR__ . '/cases.php';
$root = sys_get_temp_dir() . '/drupack-serve-test-' . bin2hex(random_bytes(8));

// A project directory holding the given files, each path relative to it.
function project_with(string $name, array $files): string
{
    $project = "{$GLOBALS['root']}/$name";
    foreach ($files as $path => $content) {
        @mkdir(dirname("$project/$path"), 0700, true);
        file_put_contents("$project/$path", $content);
    }
    return $project;
}

test('a project naming no web root serves web', function () {
    $project = project_with('default', ['composer.json' => '{}', 'web/index.php' => '<?php', 'web/core/lib/Drupal.php' => '<?php']);
    same("$project/web", docroot($project));
});

test('a project serves the web root its scaffold names', function () {
    $project = project_with('named', [
        'composer.json' => '{"extra": {"drupal-scaffold": {"locations": {"web-root": "./docroot/"}}}}',
        'docroot/index.php' => '<?php',
        'docroot/core/lib/Drupal.php' => '<?php',
    ]);
    same("$project/docroot", docroot($project));
});

test('a project whose web root is the project itself serves the project', function () {
    $project = project_with('flat', [
        'composer.json' => '{"extra": {"drupal-scaffold": {"locations": {"web-root": "./"}}}}',
        'index.php' => '<?php',
        'core/lib/Drupal.php' => '<?php',
    ]);
    same($project, docroot($project));
});

test('a WordPress site is refused', function () {
    $project = project_with('wordpress', [
        'composer.json' => '{"extra": {"drupal-scaffold": {"locations": {"web-root": "./"}}}}',
        'index.php' => '<?php',
        'wp-includes/version.php' => '<?php',
    ]);
    throws("$project is not a Drupal project: $project/core/lib/Drupal.php does not exist", fn () => docroot($project));
});

test('a web root with no index.php is refused', function () {
    $project = project_with('empty', ['composer.json' => '{}']);
    throws("$project/web/index.php does not exist", fn () => docroot($project));
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

test('docs/cli.md names every engine command the usage names', function () {
    $page = (string) file_get_contents(__DIR__ . '/../../docs/cli.md');
    $section = substr($page, strpos($page, '## The engine executable'));
    preg_match_all('/^  ([a-z]+) {2,}/m', USAGE, $commands);
    foreach ($commands[1] as $command) {
        if (!str_contains($section, "drupack $command")) {
            throw new RuntimeException("docs/cli.md's engine section omits $command");
        }
    }
    same(['start', 'stop', 'drush', 'dr', 'php', 'clean'], $commands[1]);
});

$status = runCases();
chdir(sys_get_temp_dir());
exec('rm -rf ' . escapeshellarg($root));

exit($status);
