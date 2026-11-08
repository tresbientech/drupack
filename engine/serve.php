<?php

declare(strict_types=1);

// The launcher parses every line and answers start, stop, drush and help. It runs this script
// for export and dr, and for the detach of a start that has not asked to stay in the terminal.

require_once __DIR__ . '/process.php';
require_once __DIR__ . '/mercure.php';
require_once __DIR__ . '/static.php';

// The engine's launcher reads composer.json, the reader's file, by the rules a build packs a
// site by. It answers with the docroot relative to the project, the Mercure flag, the static
// settings, and the subdomain when the file sets one. It leaves static out when the file
// sets none, which reads as null here.
function composerSettings(string $project): array
{
    $descriptors = [0 => ['file', nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $child = proc_open([getenv('DRUPACK_RUNTIME_LAUNCHER'), 'composer-settings', $project], $descriptors, $pipes);
    if (!is_resource($child)) {
        throw new RuntimeException('Cannot read the settings of ' . $project);
    }
    $output = stream_get_contents($pipes[1]);
    $refusal = stream_get_contents($pipes[2]);
    if (proc_close($child) !== 0) {
        throw new RuntimeException(trim($refusal));
    }
    return json_decode($output, true, flags: JSON_THROW_ON_ERROR) + ['static' => null];
}

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

function coreScript(string $project): string
{
    return projectScript($project, 'vendor/bin/dr', "Drupal core command line", 'Drupal 11.4 and later ship it.');
}

// One served folder's state lives in the cache beside the unpacked engine, keyed by the
// folder, so the folder gains no file. Windows spells one path in several casings, so the
// key reads a single one.
function folderEntry(string $project): string
{
    return getenv('DRUPACK_RUNTIME_CACHE_ROOT') . '/folders/f'
        . substr(hash('sha256', windows() ? strtolower($project) : $project), 0, 16);
}

function projectFolder(?string $directory): string
{
    $project = realpath($directory ?? '.');
    if ($project === false || !is_dir($project)) {
        throw new RuntimeException("No such directory: $directory. Run " . executableName() . ' --help.');
    }
    return canonical($project);
}

// The launcher has refused what a start can refuse and runs the start itself with --foreground.
// This claims the lease only to refuse a second server in the reader's terminal, then runs the
// start again in the background and relays its log. $directory is the folder as the reader wrote
// it, '' for the working directory, and $typed their words after `start`.
function detach(string $directory, array $typed): void
{
    $project = projectFolder($directory === '' ? null : $directory);
    $entry = folderEntry($project);
    directory($entry);
    $serving = new Serving($entry, $entry);
    if (!$serving->claim()) {
        throw new RuntimeException(executableName() . " already serves $project");
    }
    $serving->detach(false, stopCommand($directory === '' ? '' : ' ' . shellWord($directory)), array_merge(['start'], $typed));
}

const MERCURE_URL_FILE = 'mercure_url';

function exportEngineMercure(string $entry, string $url, bool $drush): void
{
    exportMercure("$entry/mercure_publisher_secret", "$entry/mercure_subscriber_secret", $url, $drush);
}

const STATIC_URL_FILE = 'static_url';

// A project exported before its first start has no recorded URL, so the words must name one.
function export(string $binary, array $arguments): never
{
    $project = project();
    $entry = folderEntry($project);
    $settings = composerSettings($project);
    if ($settings['static'] === null) {
        throw new RuntimeException("$project/composer.json sets no extra.drupack.static, so there is nothing to export.");
    }
    $url = null;
    if (!namesUri($arguments)) {
        $url = @file_get_contents("$entry/" . STATIC_URL_FILE);
        if ($url === false) {
            throw new RuntimeException("Cannot read a recorded static URL for $project. Start it once, or pass --uri URL.");
        }
    }
    runProjectDrush($binary, $project, $entry, $settings, exportCommand($url, $arguments));
}

// Creates nothing, so a project no start has served exports no hub. $words run Tome's
// command, so the project's Drupal must have tome_static enabled.
function runProjectDrush(string $binary, string $project, string $entry, array $settings, array $words): never
{
    useTlsBundle($entry);
    if ($settings['mercure'] && is_file("$entry/" . MERCURE_URL_FILE)) {
        exportEngineMercure($entry, file_get_contents("$entry/" . MERCURE_URL_FILE), true);
    }
    // engine/drush requires the project's Drush, so Tome's children rerun an extensionless argv[0].
    putenv('DRUPACK_DRUSH_SCRIPT=' . drushScript($project));
    requireTomeStatic($binary, __DIR__ . '/drush', getcwd());
    runScript($binary, __DIR__ . '/drush', $words);
}

// Drupal core and many modules carry a composer.json and no vendor/.
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
    runtimePhpOnPath(__DIR__);
    replaceProcess($binary, array_merge(['php-cli', $script], $arguments), getcwd(), "Cannot run $script");
}

// application/tests/serve_test.php loads this file for its functions alone.
if (defined('DRUPACK_SERVE_LIBRARY')) {
    return;
}

try {
    $binary = getenv('DRUPACK_RUNTIME_BINARY');
    match ($argv[1]) {
        'detach' => detach($argv[2], array_slice($argv, 3)),
        'export' => export($binary, array_slice($argv, 2)),
        'dr' => runScript($binary, coreScript(project()), array_slice($argv, 2)),
    };
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
