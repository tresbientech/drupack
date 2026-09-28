<?php

declare(strict_types=1);

// Unit cases for the functions in engine/php.php. Run with:
//   ./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/php_test.php"
// The constant loads php.php for its functions alone.

define('DRUPACK_PHP_LIBRARY', true);
require __DIR__ . '/../../engine/php.php';
putenv('DRUPACK_RUNTIME_NAME=drupack');

require __DIR__ . '/cases.php';

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

$status = runCases();
exit($status);
