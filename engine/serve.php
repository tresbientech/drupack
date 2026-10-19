<?php

declare(strict_types=1);

// The engine executable's entry script. The launcher runs it for every line but
// `php`, `clean`, `self-update` and a bare `--version`, from the directory the reader
// started in. `start` and `stop` are reserved, so a folder with either name takes
// ./start or ./stop. Any other first word that names no command starts the server.

require_once __DIR__ . '/process.php';
require_once __DIR__ . '/mercure.php';

const USAGE = <<<'TEXT'
Usage: %1$s [start] [DIR] [--listen IP:PORT] [--foreground]
       %1$s stop [DIR]
       %1$s drush DRUSH_COMMAND
       %1$s dr DRUPAL_COMMAND
       %1$s php SCRIPT [ARGUMENTS]
       %1$s php -r CODE
       %1$s clean [--dry-run]
       %1$s self-update [--check]
       %1$s trust [--uninstall]

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
  self-update  Install the newest release over this file, or --check for one
  trust    Install the local root that lets every Drupack site on this
           computer serve HTTPS, or --uninstall it. It may ask for a password.

docs/cli.md explains every command.
TEXT;

const DEFAULT_LISTEN = '127.0.0.1:3787';

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

// One served folder's state: the lease, the stop record, the log and the Mercure hub's files. The entry sits in the
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
    $mercure = mercureKey($project);
    // The engine's URL names the folder's drupack.site name when it resolves, otherwise the
    // bind address itself.
    $tls = tlsChoice($binary, trim($bind, '[]'), folderLabelSource($project), null);

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

    // The engine's URL has no trailing slash, as before HTTPS.
    $url = rtrim(siteUrl(tlsScheme($tls), $tls['host'], (int) $port), '/');
    storeTlsBundle($tls, $entry);
    $link = null;
    $install = null;
    if (siteInstalled($binary, $project, $docroot) === false) {
        $install = "$url/core/install.php";
    } elseif (!is_file("$project/" . DRUSH_SCRIPT)) {
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
    fwrite(STDOUT, "  URL:     $url\n" . ($link === null ? '' : "  Login:   $link\n")
        . ($install === null ? '' : "  Install: $install\n") . "  Project: $project\n");
    fwrite(STDOUT, nameHint($tls) . tlsHint($tls));
    fwrite(STDOUT, "Starting the web server.\n");
    // The Caddyfile beside this file is fixed, and Caddy fills its values from this environment.
    // The server opens no browser, so a value inherited from an earlier start is cleared.
    putenv('DRUPACK_RUNTIME_OPEN');
    if ($mercure) {
        $hub = mercureUrl(tlsScheme($tls), $bind, (int) $port);
        exportEngineMercure($entry, $hub, false);
        // The start's --listen chooses the port, so drush reads the address back from here.
        if (file_put_contents("$entry/" . MERCURE_URL_FILE, $hub) !== strlen($hub)) {
            throw new RuntimeException("Cannot write $entry/" . MERCURE_URL_FILE);
        }
    }
    exportServerEnvironment([
        'DRUPACK_RUNTIME_PORT' => $port,
        'DRUPACK_RUNTIME_BIND' => $bind,
        'DRUPACK_RUNTIME_DOCROOT' => $docroot,
        'DRUPACK_RUNTIME_ID' => basename($entry),
        'DRUPACK_RUNTIME_URL' => siteUrl(tlsScheme($tls), probeHost($bind), (int) $port),
        'DRUPACK_RUNTIME_STOP_RECORD' => $serving->stopRecord,
    ] + mercureServerEnvironment($mercure, $entry) + tlsServerEnvironment($tls));
    runtimePhpOnPath();
    replaceProcess($binary, ['php-server', __DIR__ . '/Caddyfile'], $project, 'Cannot start FrankenPHP');
}

// The project's composer.json extra.drupack settings. composer.json is the reader's file,
// so a file that is not a JSON object is refused.
function drupackExtra(string $project): array
{
    $path = "$project/composer.json";
    if (!is_file($path)) {
        return [];
    }
    $composer = json_decode((string) file_get_contents($path), true);
    if (!is_array($composer)) {
        throw new RuntimeException("$path is not a JSON object");
    }
    $drupack = $composer['extra']['drupack'] ?? [];
    if (!is_array($drupack)) {
        throw new RuntimeException("$path: extra.drupack must be a JSON object");
    }
    return $drupack;
}

// Whether the project turns the Mercure hub on. A value other than true or false is refused.
function mercureKey(string $project): bool
{
    $value = drupackExtra($project)['mercure'] ?? false;
    if (!is_bool($value)) {
        throw new RuntimeException("$project/composer.json: extra.drupack.mercure must be true or false");
    }
    return $value;
}

// The tls-source arguments that name the folder's label: its subdomain, otherwise the
// folder's name. composer.json is the reader's file, so a subdomain that is not one DNS
// label is refused; pack's siteconfig holds the same rule.
function folderLabelSource(string $project): array
{
    $value = drupackExtra($project)['subdomain'] ?? null;
    if ($value === null) {
        return ['--folder', basename($project)];
    }
    if (!is_string($value) || preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $value) !== 1) {
        throw new RuntimeException("$project/composer.json: extra.drupack.subdomain must be one DNS label: "
            . 'a to z, 0 to 9 and dashes, at most 63, no dash at either end');
    }
    return ['--folder', $value];
}

// The engine keeps the hub's secrets and address in the folder's cache entry, so the
// project folder gains no file.
const MERCURE_URL_FILE = 'mercure_url';

function exportEngineMercure(string $entry, string $url, bool $drush): void
{
    exportMercure("$entry/mercure_publisher_secret", "$entry/mercure_subscriber_secret", $url, $drush);
}

// Drush in a project with the hub sees the variables the last start created. It creates
// nothing, so a project no start has served exports no hub.
function drush(string $binary, array $arguments): never
{
    $project = project();
    $entry = folderEntry($project);
    useTlsBundle($entry);
    if (mercureKey($project) && is_file("$entry/" . MERCURE_URL_FILE)) {
        exportEngineMercure($entry, file_get_contents("$entry/" . MERCURE_URL_FILE), true);
    }
    runScript($binary, drushScript($project), $arguments);
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

// Whether the database the project's settings name holds an installed site, or null when
// the probe cannot tell, such as for a database it cannot reach.
function siteInstalled(string $binary, string $project, string $docroot): ?bool
{
    $descriptors = [0 => ['file', nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', nullDevice(), 'w']];
    $child = proc_open([$binary, 'php-cli', __DIR__ . '/installed.php', $project, $docroot], $descriptors, $pipes, $project);
    if (!is_resource($child)) {
        throw new RuntimeException('Cannot run the installation probe');
    }
    $answer = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    $status = proc_close($child);
    return $status === 0 ? $answer === 'yes' : null;
}

// Drush, core's command line, the installer and Package Manager start Composer and their
// own child processes with the php on PATH, which bin/ points back at this runtime.
function runtimePhpOnPath(): void
{
    putenv('PATH=' . __DIR__ . '/bin' . PATH_SEPARATOR . getenv('PATH'));
}

function runScript(string $binary, string $script, array $arguments): never
{
    runtimePhpOnPath();
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
        'drush' => drush($binary, array_slice($argv, 2)),
        'dr' => runScript($binary, coreScript(project()), array_slice($argv, 2)),
        'trust' => replaceProcess($binary, array_merge(['tls-trust'], array_slice($argv, 2)), __DIR__, 'Cannot run trust'),
        '--help', '-h' => fwrite(STDOUT, sprintf(USAGE, executableName(), DEFAULT_LISTEN) . "\n"),
        default => serve($binary, array_slice($argv, 1)),
    };
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
