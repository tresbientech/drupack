<?php

declare(strict_types=1);

// Unit cases for the functions in engine/serve.php. Run with:
//   ./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/serve_test.php"
// The constant loads serve.php for its functions alone.

define('DRUPACK_SERVE_LIBRARY', true);
require __DIR__ . '/../../engine/serve.php';

$cases = [];
$root = sys_get_temp_dir() . '/drupack-serve-test-' . bin2hex(random_bytes(8));

function test(string $name, callable $case): void
{
    $GLOBALS['cases'][$name] = $case;
}

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
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

test('docs/cli.md names every engine command the usage names', function () {
    $page = (string) file_get_contents(__DIR__ . '/../../docs/cli.md');
    $section = substr($page, strpos($page, '## The engine executable'));
    preg_match_all('/^  ([a-z]+) {2,}/m', USAGE, $commands);
    foreach ($commands[1] as $command) {
        if (!str_contains($section, "drupack $command")) {
            throw new RuntimeException("docs/cli.md's engine section omits $command");
        }
    }
    same(['drush', 'php', 'clean'], $commands[1]);
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
chdir(sys_get_temp_dir());
exec('rm -rf ' . escapeshellarg($root));

$total = count($cases);
fwrite(STDOUT, $failed === 0 ? "\n$total cases passed\n" : "\n$failed of $total cases failed\n");
exit($failed === 0 ? 0 : 1);
