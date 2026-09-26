<?php

declare(strict_types=1);

// The engine executable's entry script. The launcher runs it for every word but
// `php` and `clean`, from the directory the reader started in.

require __DIR__ . '/process.php';

const USAGE = <<<'TEXT'
Usage: %1$s serve [DIR] [--listen IP:PORT]
       %1$s dr DRUSH_COMMAND
       %1$s php SCRIPT|-r CODE [ARGUMENTS]
       %1$s clean [--dry-run]

Commands:
  serve    Serve the Drupal project in DIR, the working directory by default,
           with its own settings. --listen defaults to %2$s.
  dr       Run the Drush of the project holding the working directory
  php      Run a PHP script, or -r CODE, on this executable's PHP
  clean    Remove the unpacked engine files from the cache

docs/cli.md explains every command.
TEXT;

const DEFAULT_LISTEN = '127.0.0.1:8888';

// composer.json is the reader's file, so a docroot it names is checked before use.
function docroot(string $project): string
{
    $composer = json_decode((string) @file_get_contents("$project/composer.json"), true);
    $root = trim($composer['extra']['drupal-scaffold']['locations']['web-root'] ?? 'web', './');
    $docroot = $root === '' ? $project : "$project/$root";
    if (!is_file("$docroot/index.php")) {
        throw new RuntimeException("$project holds no Drupal docroot: $docroot/index.php does not exist");
    }
    return $docroot;
}

function drushScript(string $project): string
{
    $drush = "$project/vendor/drush/drush/drush.php";
    if (!is_file($drush)) {
        throw new RuntimeException("$project has no Drush: $drush does not exist. Run composer require drush/drush in it.");
    }
    return $drush;
}

// composer.lock is the reader's file. An extension it declares that this runtime
// lacks fails only once a request reaches the code needing it, so a start refuses.
// Composer's own platform_check.php tests the PHP version alone by default.
function checkPlatform(string $binary, string $project): void
{
    $check = "$project/vendor/composer/platform_check.php";
    if (is_file($check)) {
        $child = proc_open([$binary, 'php-cli', $check], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($child)) {
            throw new RuntimeException("Cannot run $check");
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (proc_close($child) !== 0) {
            throw new RuntimeException("This runtime cannot run $project.\n" . trim($output));
        }
    }
    $lock = json_decode((string) @file_get_contents("$project/composer.lock"), true);
    if (!is_array($lock)) {
        return;
    }
    $declared = [];
    foreach (array_keys($lock['platform'] ?? []) as $requirement) {
        $declared[$requirement][] = "$project/composer.json";
    }
    foreach ($lock['packages'] ?? [] as $package) {
        foreach (array_keys($package['require'] ?? []) as $requirement) {
            $declared[$requirement][] = $package['name'];
        }
    }
    $missing = [];
    foreach ($declared as $requirement => $declarers) {
        // Composer spells an extension's spaces as dashes: ext-zend-opcache.
        if (str_starts_with($requirement, 'ext-') && !extension_loaded(str_replace('-', ' ', substr($requirement, 4)))) {
            $missing[] = '  ' . substr($requirement, 4) . ': ' . implode(', ', $declarers);
        }
    }
    if ($missing !== []) {
        throw new RuntimeException("$project/composer.lock declares PHP extensions this runtime lacks:\n" . implode("\n", $missing));
    }
}

function serve(string $binary, array $arguments): never
{
    $directory = null;
    $listen = DEFAULT_LISTEN;
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if ($argument === '--listen') {
            $listen = array_shift($arguments) ?? throw new RuntimeException('--listen takes IP:PORT');
        } elseif (str_starts_with($argument, '--listen=')) {
            $listen = substr($argument, strlen('--listen='));
        } elseif ($directory === null && !str_starts_with($argument, '-')) {
            $directory = $argument;
        } else {
            throw new RuntimeException("Unknown argument $argument. Run " . executableName() . ' --help.');
        }
    }
    if (preg_match('/^(.+):(\d{1,5})$/', $listen, $parts) !== 1 || (int) $parts[2] > 65535) {
        throw new RuntimeException("--listen takes IP:PORT, got $listen");
    }
    [, $bind, $port] = $parts;
    $project = realpath($directory ?? '.');
    if ($project === false || !is_dir($project)) {
        throw new RuntimeException("No such directory: $directory");
    }
    $project = canonical($project);
    $docroot = canonical(docroot($project));
    checkPlatform($binary, $project);

    putenv("DRUPACK_RUNTIME_DOCROOT=$docroot");
    putenv("DRUPACK_RUNTIME_BIND=$bind");
    putenv("DRUPACK_RUNTIME_PORT=$port");
    putenv('DRUPACK_RUNTIME_ID=' . substr(hash('sha256', $project), 0, 16));
    $url = "http://$listen";
    fwrite(STDOUT, "Creating a one-time login link.\n");
    $login = proc_open([$binary, 'php-cli', drushScript($project), 'user:login', "--uri=$url"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project);
    $link = trim(stream_get_contents($pipes[1]));
    $failure = trim(stream_get_contents($pipes[2]));
    if (proc_close($login) !== 0 || $link === '') {
        // The folder's own settings may block uid 1 or name no reachable database yet;
        // the server still starts, and Drupal's own error page says which.
        fwrite(STDERR, "Cannot mint a one-time login link: $failure\nGet one once the site answers with: " . executableName() . " dr user:login --uri=$url\n");
        $link = null;
    }
    fwrite(STDOUT, "  URL:     $url\n" . ($link === null ? '' : "  Login:   $link\n") . "  Project: $project\n");
    fwrite(STDOUT, "Starting the web server. Press Ctrl+C to stop.\n");
    replaceProcess($binary, ['run', '--config', __DIR__ . '/Caddyfile', '--adapter', 'caddyfile'], $project, 'Cannot start FrankenPHP');
}

// The nearest installed Composer project at or above the working directory. Drupal
// core and many modules carry a composer.json of their own, and no vendor/.
function project(): string
{
    $start = canonical(getcwd());
    for ($directory = $start; ; $directory = dirname($directory)) {
        if (is_file("$directory/vendor/autoload.php")) {
            return $directory;
        }
        if (dirname($directory) === $directory) {
            throw new RuntimeException("No vendor/autoload.php in $start or any directory above it. Run composer install in the project.");
        }
    }
}

function drush(string $binary, array $arguments): never
{
    $drush = drushScript(project());
    // Drush starts its own child processes with the php on PATH, which bin/ points
    // back at this runtime.
    putenv('PATH=' . __DIR__ . '/bin' . PATH_SEPARATOR . getenv('PATH'));
    replaceProcess($binary, array_merge(['php-cli', $drush], $arguments), getcwd(), 'Cannot run Drush');
}

// application/tests/serve_test.php loads this file for its functions alone.
if (defined('DRUPACK_SERVE_LIBRARY')) {
    return;
}

try {
    $binary = getenv('DRUPACK_RUNTIME_BINARY');
    $word = $argv[1] ?? '--help';
    match ($word) {
        'serve' => serve($binary, array_slice($argv, 2)),
        'dr' => drush($binary, array_slice($argv, 2)),
        '--help', '-h', 'help' => fwrite(STDOUT, sprintf(USAGE, executableName(), DEFAULT_LISTEN) . "\n"),
        default => throw new RuntimeException("Unknown command $word. Run " . executableName() . ' --help.'),
    };
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
