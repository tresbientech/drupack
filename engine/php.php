<?php

declare(strict_types=1);

// The engine executable's php command, which bin/php runs for Drush's child
// processes too. FrankenPHP's php-cli runs a script, or -r code without $argv,
// and reads no PHP option. This file answers the informational options itself,
// runs -r code in its own global scope, and passes -d settings to a script
// through an ini file in a directory PHP_INI_SCAN_DIR names.

require_once __DIR__ . '/process.php';

const PHP_OPTIONS = '-d SETTING, -r CODE, -f FILE, -l FILE, -v, -m, -i, --ini, --ri EXTENSION and -h';

// What php's own command line would do with $arguments: a mode, its value, the
// -d settings in order, and the arguments the script or code receives. The
// arguments come from the reader, so every option this file cannot honour is
// refused by name.
function parsePhp(array $arguments): array
{
    $parsed = ['mode' => null, 'value' => null, 'settings' => [], 'rest' => []];
    $take = static function (string $option) use (&$arguments): string {
        return array_shift($arguments) ?? throw new RuntimeException("php $option takes a value");
    };
    while ($arguments !== [] && $parsed['mode'] === null) {
        $argument = array_shift($arguments);
        match (true) {
            $argument === '-d', $argument === '--define' => $parsed['settings'][] = $take($argument),
            str_starts_with($argument, '-d') => $parsed['settings'][] = substr($argument, 2),
            in_array($argument, ['-v', '--version'], true) => $parsed['mode'] = 'version',
            in_array($argument, ['-m', '--modules'], true) => $parsed['mode'] = 'modules',
            in_array($argument, ['-i', '--info'], true) => $parsed['mode'] = 'info',
            $argument === '--ini' => $parsed['mode'] = 'ini',
            in_array($argument, ['-h', '--help', '-?'], true) => $parsed['mode'] = 'help',
            in_array($argument, ['-q', '--no-header', '-H', '--hide-args'], true) => null,
            in_array($argument, ['-l', '--syntax-check'], true) => [$parsed['mode'], $parsed['value']] = ['lint', $take($argument)],
            in_array($argument, ['--ri', '--rextinfo'], true) => [$parsed['mode'], $parsed['value']] = ['extension', $take($argument)],
            in_array($argument, ['-r', '--run'], true) => [$parsed['mode'], $parsed['value']] = ['code', $take($argument)],
            in_array($argument, ['-f', '--file'], true) => [$parsed['mode'], $parsed['value']] = ['script', $take($argument)],
            $argument === '--' => [$parsed['mode'], $parsed['value']] = ['script', $take($argument)],
            str_starts_with($argument, '-') => throw new RuntimeException(
                'php option ' . $argument . ' is not supported. ' . executableName() . ' php takes ' . PHP_OPTIONS . '.'),
            default => [$parsed['mode'], $parsed['value']] = ['script', $argument],
        };
    }
    $parsed['mode'] ??= throw new RuntimeException(executableName() . ' php reads no standard input. It takes a script or ' . PHP_OPTIONS . '.');
    // php passes what follows -r code to it after an optional --.
    if ($parsed['mode'] === 'code' && ($arguments[0] ?? null) === '--') {
        array_shift($arguments);
    }
    $parsed['rest'] = $arguments;
    return $parsed;
}

// The directory holding one ini file with these settings, reused by every run
// that passes the same ones.
function settingsDirectory(array $settings): string
{
    $lines = array_map(static fn (string $setting): string => str_contains($setting, '=') ? $setting : "$setting=1", $settings);
    $directory = sys_get_temp_dir() . '/drupack-php-' . substr(hash('sha256', implode("\n", $lines)), 0, 16);
    if (!is_file("$directory/settings.ini")) {
        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create $directory");
        }
        $staging = "$directory/settings.ini." . bin2hex(random_bytes(4));
        if (file_put_contents($staging, implode("\n", $lines) . "\n") === false || !rename($staging, "$directory/settings.ini")) {
            throw new RuntimeException("Cannot write $directory/settings.ini");
        }
    }
    return $directory;
}

function applySettings(array $settings): void
{
    foreach ($settings as $setting) {
        [$name, $value] = array_pad(explode('=', $setting, 2), 2, '1');
        ini_set($name, $value);
    }
}

function lint(string $file): int
{
    $code = @file_get_contents($file);
    if ($code === false) {
        fwrite(STDERR, "Could not open input file: $file\n");
        return 1;
    }
    try {
        token_get_all($code, TOKEN_PARSE);
    } catch (ParseError $error) {
        fwrite(STDOUT, "PHP Parse error:  {$error->getMessage()} in $file on line {$error->getLine()}\nErrors parsing $file\n");
        return 255;
    }
    fwrite(STDOUT, "No syntax errors detected in $file\n");
    return 0;
}

function describe(string $mode, ?string $value): int
{
    switch ($mode) {
        case 'version':
            fwrite(STDOUT, sprintf("PHP %s (cli) (%s)\nZend Engine v%s\n", PHP_VERSION, PHP_ZTS ? 'ZTS' : 'NTS', zend_version()));
            return 0;
        case 'modules':
            $php = get_loaded_extensions();
            $zend = get_loaded_extensions(true);
            natcasesort($php);
            natcasesort($zend);
            fwrite(STDOUT, "[PHP Modules]\n" . implode("\n", $php) . "\n\n[Zend Modules]\n" . implode("\n", $zend) . "\n\n");
            return 0;
        case 'info':
            phpinfo();
            return 0;
        case 'ini':
            $loaded = php_ini_loaded_file();
            fwrite(STDOUT, 'Configuration File (php.ini) Path: ' . ($loaded === false ? '' : dirname($loaded)) . "\n"
                . 'Loaded Configuration File:         ' . ($loaded === false ? '(none)' : $loaded) . "\n"
                . 'Scan for additional .ini files in: ' . (getenv('PHP_INI_SCAN_DIR') ?: '(none)') . "\n"
                . 'Additional .ini files parsed:      ' . (php_ini_scanned_files() ?: '(none)') . "\n");
            return 0;
        case 'extension':
            if (!extension_loaded($value)) {
                fwrite(STDOUT, "Extension '$value' not present.\n");
                return 1;
            }
            (new ReflectionExtension($value))->info();
            return 0;
        case 'lint':
            return lint($value);
        case 'help':
            fwrite(STDOUT, sprintf("Usage: %s php [OPTIONS] SCRIPT [ARGUMENTS]\n       %s php [OPTIONS] -r CODE [--] [ARGUMENTS]\n\nOptions: %s.\n",
                executableName(), executableName(), PHP_OPTIONS));
            return 0;
    }
}

// application/tests/php_test.php loads this file for its functions alone.
if (defined('DRUPACK_PHP_LIBRARY')) {
    return;
}

try {
    $php = parsePhp(array_slice($argv, 1));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
$binary = getenv('DRUPACK_RUNTIME_BINARY');
// Settings a script or code needs at startup reach PHP as a scanned ini file. A
// directory PHP_INI_SCAN_DIR already names stays, before this one.
if ($php['settings'] !== [] && in_array($php['mode'], ['script', 'code'], true)) {
    $scan = getenv('PHP_INI_SCAN_DIR');
    putenv('PHP_INI_SCAN_DIR=' . ($scan === false || $scan === '' ? '' : $scan . PATH_SEPARATOR) . settingsDirectory($php['settings']));
    $again = $php['mode'] === 'script'
        ? array_merge([$php['value']], $php['rest'])
        : array_merge([__FILE__, '-r', $php['value'], '--'], $php['rest']);
    replaceProcess($binary, array_merge(['php-cli'], $again), getcwd(), 'Cannot run PHP');
}
if ($php['mode'] === 'script') {
    replaceProcess($binary, array_merge(['php-cli', $php['value']], $php['rest']), getcwd(), 'Cannot run PHP');
}
if ($php['mode'] === 'code') {
    // php names -r code "Standard input code" in $argv.
    $argv = array_merge(['Standard input code'], $php['rest']);
    $argc = count($argv);
    $_SERVER['argv'] = $argv;
    $_SERVER['argc'] = $argc;
    $code = $php['value'];
    unset($php, $binary);
    // The reader's own -r code, which php -r runs the same way.
    eval($code);
    exit(0);
}
applySettings($php['settings']);
exit(describe($php['mode'], $php['value']));
