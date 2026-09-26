<?php

declare(strict_types=1);

// Unit cases for the functions in engine/php.php. Run with:
//   ./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/php_test.php"
// The constant loads php.php for its functions alone.

define('DRUPACK_PHP_LIBRARY', true);
require __DIR__ . '/../../engine/php.php';
putenv('DRUPACK_RUNTIME_NAME=drupack');

$cases = [];

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

test('a script takes the arguments after it, options included', function () {
    same(['mode' => 'script', 'value' => 'vendor/bin/drush', 'settings' => ['memory_limit=-1'], 'rest' => ['status', '-v']],
        parsePhp(['-d', 'memory_limit=-1', 'vendor/bin/drush', 'status', '-v']));
});

test('-d takes its setting joined or apart', function () {
    same(['a=1', 'b'], parsePhp(['-da=1', '-d', 'b', '-v'])['settings']);
});

test('-r code takes the arguments after an optional --', function () {
    same(['mode' => 'code', 'value' => 'echo 1;', 'settings' => [], 'rest' => ['x']], parsePhp(['-r', 'echo 1;', '--', 'x']));
});

test('the informational options name their mode', function () {
    foreach (['-v' => 'version', '--version' => 'version', '-m' => 'modules', '-i' => 'info', '--ini' => 'ini', '-h' => 'help'] as $option => $mode) {
        same($mode, parsePhp([$option])['mode']);
    }
    same(['extension', 'json'], [parsePhp(['--ri', 'json'])['mode'], parsePhp(['--ri', 'json'])['value']]);
});

test('an option php.php cannot honour is refused by name', function () {
    throws('php option -S is not supported', fn () => parsePhp(['-S', 'localhost:8000']));
    throws('php option -n is not supported', fn () => parsePhp(['-n', 'script.php']));
});

test('no script and no mode is refused', function () {
    throws('drupack php reads no standard input', fn () => parsePhp([]));
});

test('an option missing its value is refused', function () {
    throws('php -r takes a value', fn () => parsePhp(['-r']));
});

test('the same settings share one ini directory', function () {
    $directory = settingsDirectory(['memory_limit=64M', 'display_errors']);
    same("memory_limit=64M\ndisplay_errors=1\n", file_get_contents("$directory/settings.ini"));
    same($directory, settingsDirectory(['memory_limit=64M', 'display_errors']));
});

test('lint names a parse error and passes valid code', function () {
    $file = tempnam(sys_get_temp_dir(), 'drupack-php-lint-');
    file_put_contents($file, '<?php echo 1;');
    same(0, lint($file));
    file_put_contents($file, '<?php echo ;');
    same(255, lint($file));
    unlink($file);
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
$total = count($cases);
fwrite(STDOUT, $failed === 0 ? "\n$total cases passed\n" : "\n$failed of $total cases failed\n");
exit($failed === 0 ? 0 : 1);
