<?php

declare(strict_types=1);

// The engine executable's entry script. The launcher runs it for every line but
// `php`, `clean` and a bare `--version`, from the directory the reader started in.
// `start` and `stop` are reserved, so a folder with either name takes ./start or
// ./stop. Any other first word that names no command starts the server.

require_once __DIR__ . '/process.php';

const USAGE = <<<'TEXT'
Usage: %1$s [start] [DIR] [--listen IP:PORT] [--foreground]
       %1$s stop [DIR]
       %1$s drush DRUSH_COMMAND
       %1$s dr DRUPAL_COMMAND
       %1$s php SCRIPT [ARGUMENTS]
       %1$s php -r CODE
       %1$s clean [--dry-run]

Serves the Drupal project in DIR, the working directory by default, with its
own settings, in the background. --foreground serves in this terminal until a
signal stops it. --listen defaults to %2$s.

Commands:
  start    Serve the project, which a bare command does too
  stop     Stop the server of the project in DIR
  drush    Run the Drush of the project holding the working directory
  dr       Run Drupal core's command line of that project
  php      Run a script or -r CODE on the bundled PHP, with no PHP option
  clean    Remove the unpacked engine files from the cache

docs/cli.md explains every command.
TEXT;

const DEFAULT_LISTEN = '127.0.0.1:8888';

// composer.json is the reader's file, so a docroot it names is checked before use.
// The Caddyfile's guards and front controller are Drupal's, so a docroot without
// Drupal core, such as a WordPress site's, is refused.
function docroot(string $project): string
{
    $composer = json_decode((string) @file_get_contents("$project/composer.json"), true);
    $root = trim($composer['extra']['drupal-scaffold']['locations']['web-root'] ?? 'web', './');
    $docroot = $root === '' ? $project : "$project/$root";
    foreach (['index.php', 'core/lib/Drupal.php'] as $file) {
        if (!is_file("$docroot/$file")) {
            throw new RuntimeException("$project is not a Drupal project: $docroot/$file does not exist. "
                . executableName() . ' serves Drupal alone.');
        }
    }
    return $docroot;
}

// The script a project's Composer install put at $path, named for the refusal.
function projectScript(string $project, string $path, string $name, string $remedy): string
{
    $script = "$project/$path";
    if (!is_file($script)) {
        throw new RuntimeException("$project has no $name: $script does not exist. $remedy");
    }
    return $script;
}

const DRUSH_SCRIPT = 'vendor/drush/drush/drush.php';

function drushScript(string $project): string
{
    return projectScript($project, DRUSH_SCRIPT, 'Drush', 'Run composer require drush/drush in it.');
}

// Drupal core ships its own command line, vendor/bin/dr, from Drupal 11.4 on.
function coreScript(string $project): string
{
    return projectScript($project, 'vendor/bin/dr', "Drupal core command line", 'Drupal 11.4 and later ship it.');
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

// One served folder's state: the lease, the stop record and the log. The entry sits in the
// cache beside the unpacked engine, keyed by the folder, so the folder itself gains no file.
// Windows spells one path in several casings, so the key reads a single one.
function folderEntry(string $project): string
{
    // The letter keeps the name inside ADR 0010's cache segment form.
    return getenv('DRUPACK_RUNTIME_CACHE_ROOT') . '/folders/f'
        . substr(hash('sha256', windows() ? strtolower($project) : $project), 0, 16);
}

// The project folder a word names, the reader's directory with none. A directory the
// reader typed may not exist.
function projectFolder(?string $directory): string
{
    $project = realpath($directory ?? '.');
    if ($project === false || !is_dir($project)) {
        throw new RuntimeException("No such directory: $directory. Run " . executableName() . ' --help.');
    }
    return canonical($project);
}

// `stop` takes the folder alone. A folder that was never served has no entry, and the
// lookup creates none.
function stopFolder(array $arguments): int
{
    if (count($arguments) > 1 || str_starts_with($arguments[0] ?? '', '-')) {
        throw new RuntimeException('stop takes a folder alone. Run ' . executableName() . ' --help.');
    }
    $project = projectFolder($arguments[0] ?? null);
    $entry = folderEntry($project);
    return (new Serving($entry, $entry))->stop('folder', $project);
}

function serve(string $binary, array $arguments): never
{
    $typed = $arguments;
    $directory = null;
    $listen = DEFAULT_LISTEN;
    $foreground = false;
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if ($argument === '--listen') {
            $listen = array_shift($arguments) ?? throw new RuntimeException('--listen takes IP:PORT');
        } elseif (str_starts_with($argument, '--listen=')) {
            $listen = substr($argument, strlen('--listen='));
        } elseif ($argument === '--foreground') {
            $foreground = true;
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
    $project = projectFolder($directory);
    $docroot = canonical(docroot($project));
    checkPlatform($binary, $project);

    // The reader's terminal gets every refusal above and the lease's below, so a start that
    // detaches has nothing left to refuse but the server's own failures.
    $entry = folderEntry($project);
    directory($entry);
    $serving = new Serving($entry, $entry);
    if (!$serving->claim()) {
        throw new RuntimeException(executableName() . " already serves $project");
    }
    $serving->detach($foreground,
        stopCommand($directory === null ? '' : ' ' . shellWord($directory)), array_merge(['start'], $typed));

    $url = "http://$listen";
    $link = null;
    if (!is_file("$project/" . DRUSH_SCRIPT)) {
        fwrite(STDERR, "No login link: $project has no Drush. composer require drush/drush adds one.\n");
    } else {
        fwrite(STDOUT, "Creating a one-time login link.\n");
        try {
            $link = drushLoginLink($binary, "$project/" . DRUSH_SCRIPT, $project, ["--uri=$url"], $url);
        } catch (LoginLinkFailure $failure) {
            // The folder's own settings may block uid 1 or name no reachable database yet;
            // the server still starts, and Drupal's own error page says which.
            fwrite(STDERR, "Cannot mint a one-time login link: {$failure->reason}\nGet one once the site answers with: " . executableName() . " drush user:login --uri=$url\n");
        }
    }
    fwrite(STDOUT, "  URL:     $url\n" . ($link === null ? '' : "  Login:   $link\n") . "  Project: $project\n");
    fwrite(STDOUT, "Starting the web server.\n");
    // The Caddyfile beside this file is fixed, and Caddy fills its values from this environment.
    // The server opens no browser, so a value inherited from an earlier start is cleared.
    putenv('DRUPACK_RUNTIME_OPEN');
    exportServerEnvironment([
        'DRUPACK_RUNTIME_PORT' => $port,
        'DRUPACK_RUNTIME_BIND' => $bind,
        'DRUPACK_RUNTIME_DOCROOT' => $docroot,
        'DRUPACK_RUNTIME_ID' => basename($entry),
        'DRUPACK_RUNTIME_URL' => 'http://' . urlHost(probeHost($bind)) . ":$port",
        'DRUPACK_RUNTIME_STOP_RECORD' => $serving->stopRecord,
    ]);
    replaceProcess($binary, ['php-server', __DIR__ . '/Caddyfile'], $project, 'Cannot start FrankenPHP');
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

function runScript(string $binary, string $script, array $arguments): never
{
    // Drush and core's command line start their own child processes with the php on
    // PATH, which bin/ points back at this runtime.
    putenv('PATH=' . __DIR__ . '/bin' . PATH_SEPARATOR . getenv('PATH'));
    replaceProcess($binary, array_merge(['php-cli', $script], $arguments), getcwd(), "Cannot run $script");
}

// application/tests/serve_test.php loads this file for its functions alone.
if (defined('DRUPACK_SERVE_LIBRARY')) {
    return;
}

try {
    $binary = getenv('DRUPACK_RUNTIME_BINARY');
    match ($argv[1] ?? null) {
        'stop' => exit(stopFolder(array_slice($argv, 2))),
        'start' => serve($binary, array_slice($argv, 2)),
        'drush' => runScript($binary, drushScript(project()), array_slice($argv, 2)),
        'dr' => runScript($binary, coreScript(project()), array_slice($argv, 2)),
        '--help', '-h' => fwrite(STDOUT, sprintf(USAGE, executableName(), DEFAULT_LISTEN) . "\n"),
        default => serve($binary, array_slice($argv, 1)),
    };
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
