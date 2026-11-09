<?php

declare(strict_types=1);

// One named operation per call, run by the launcher through php-cli:
//   operate.php check folder PROJECT DOCROOT
//   operate.php check site DATA
//   operate.php static-directory PROJECT DOCROOT
//   operate.php prepare DATA
//   operate.php seed DATA
// An operation prints its step lines on stderr and one JSON record on stdout. A refusal prints
// its message on stderr and exits 1. A site operation reads the start's database, administrator
// and site name options from their DRUPACK_* variables, so no credential reaches argv.

use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Drupack\Support\Need;
use Drupack\Support\SiteContract;
use Drupack\Support\SiteData;
use Drupack\Support\Step;
use Symfony\Component\HttpFoundation\Request;

function nullDevice(): string
{
    return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
}

// Runs a child with no stdin, its standard error in the file $stderr or discarded, and returns its
// trimmed standard output and exit status.
function capture(array $command, string $directory, string $failure, ?string $stderr = null): array
{
    $descriptors = [0 => ['file', nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr ?? nullDevice(), 'w']];
    $child = proc_open($command, $descriptors, $pipes, $directory);
    if (!is_resource($child)) {
        throw new RuntimeException($failure);
    }
    $output = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    return [$output, proc_close($child)];
}

function environment(string $name): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? null : $value;
}

// The executable the reader ran, exported by the launcher. One engine serves every site.
function executableName(): string
{
    return getenv('DRUPACK_RUNTIME_NAME');
}

function directory(string $path): void
{
    // Another start can create the same directory between the check and the call.
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create directory: $path");
    }
}

function urlHost(string $host): string
{
    return str_contains($host, ':') ? "[$host]" : $host;
}

// The site's composer project builds vendor/, so the Packaged site registers the support
// namespace beside it. The engine's payload carries neither vendor/ nor support/.
function registerSupport(): void
{
    (require __DIR__ . '/vendor/autoload.php')->addPsr4('Drupack\\Support\\', __DIR__ . '/support');
}

// The release's shared copy, or the site's own copy in Site data once a start has laid it.
function application(): string
{
    return getenv('DRUPACK_RUNTIME_APP_DIR');
}

function siteContract(): SiteContract
{
    static $site;
    return $site ??= SiteContract::read(__DIR__ . '/site.json');
}

// The options a start hands operate.php through these variables, which a reader may also set.
function startOptions(SiteContract $site): array
{
    return [
        'database' => environment('DRUPACK_DATABASE') ?? 'sqlite',
        'db-host' => environment('DRUPACK_DB_HOST'),
        'db-port' => environment('DRUPACK_DB_PORT'),
        'db-name' => environment('DRUPACK_DB_NAME'),
        'db-user' => environment('DRUPACK_DB_USER'),
        'db-password' => environment('DRUPACK_DB_PASSWORD'),
        'admin-user' => environment('DRUPACK_ADMIN_USER'),
        'admin-password' => environment('DRUPACK_ADMIN_PASSWORD'),
        'site-name' => environment('DRUPACK_SITE_NAME') ?? $site->siteName,
    ];
}

// The generated password is never shown: the one-time login link signs the reader in, so the
// password only keeps the account from being passwordless. It reaches only the step that sets it.
function administratorCredentials(array $options, array $steps): array
{
    if (!in_array(Need::Credentials, Step::owed($steps), true)) {
        return $options;
    }
    $options['admin-user'] ??= 'admin';
    $options['admin-password'] ??= bin2hex(random_bytes(32));
    return $options;
}

// Reads the steps this Site data owes, refuses before any of them runs when the options lack
// what one needs, and returns them with the options. The administrator's credentials are
// generated instead.
function owedSteps(SiteData $site, array $options, SiteContract $contract): array
{
    $steps = $site->steps($options['database']);
    requireNeeds($steps, $options, $contract);
    return [$steps, administratorCredentials($options, $steps)];
}

function requireNeeds(array $steps, array $options, SiteContract $site): void
{
    foreach (Step::owed($steps) as $need) {
        match ($need) {
            // A site built without a recipe ships no seed, so a SQLite first start has nothing to copy.
            Need::Recipe => $site->recipe === '' ? throw new RuntimeException(executableName() . ' has no recipe to seed a SQLite site. '
                . 'Start it with --database mysql or --database pgsql and the connection details of the database that holds its site.') : null,
            Need::Connection => requireConnection($options),
            Need::Credentials => null,
        };
    }
}

function requireConnection(array $options): void
{
    if ($options['database'] === 'sqlite') {
        return;
    }
    foreach (['db-host', 'db-name', 'db-user', 'db-password'] as $name) {
        if ($options[$name] === null) {
            throw new RuntimeException("Missing database connection details for {$options['database']}");
        }
    }
}

function databasePort(array $options): string
{
    return $options['db-port'] ?? ($options['database'] === 'mysql' ? '3306' : '5432');
}

function databaseConfiguration(array $options, SiteData $site): array
{
    if ($options['database'] === 'sqlite') {
        return [
            'driver' => 'sqlite',
            'database' => $site->database(),
            'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
            'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/',
        ];
    }
    $port = databasePort($options);
    if (filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
        throw new InvalidArgumentException('Invalid database port');
    }
    return [
        'driver' => $options['database'],
        'database' => $options['db-name'],
        'username' => $options['db-user'],
        'password' => $options['db-password'],
        'host' => $options['db-host'],
        'port' => (int) $port,
        'prefix' => '',
    ];
}

function settings(string $template, array $database, string $siteSettings): string
{
    $content = file_get_contents($template);
    if ($content === false) {
        throw new RuntimeException('Cannot read Drupal settings template');
    }
    return str_replace(
        ['__DRUPACK_DATABASE_CONFIGURATION__', '__DRUPACK_SITE_SETTINGS__'],
        [var_export($database, true), var_export($siteSettings, true)],
        $content,
    );
}

// A serving site requires this file on every request, and a `drush` command runs while it
// serves. The replacement is written beside it and renamed over it, so a request reads
// the old file or the new one; a truncating write would let one read half of it.
function writeSettings(string $path, string $template, array $database, string $siteSettings): void
{
    $staging = "$path.new";
    if (file_put_contents($staging, settings($template, $database, $siteSettings)) === false || !rename($staging, $path)) {
        throw new RuntimeException('Cannot initialize Drupal settings');
    }
}

function recordedOptions(array $options, SiteData $site): array
{
    $recorded = $site->connection();
    $options['database'] = $recorded['driver'];
    if ($recorded['driver'] !== 'sqlite') {
        $options['db-host'] = $recorded['host'];
        $options['db-port'] = (string) $recorded['port'];
        $options['db-name'] = $recorded['database'];
        $options['db-user'] = $recorded['username'];
        $options['db-password'] = $recorded['password'];
    }
    return $options;
}

// Drush prints its reason first and its backtrace after, so the head is the useful end.
const DIAGNOSTIC_LIMIT = 400;

// A failing step can print the connection as a URL carrying its password, so credentials go
// before anything prints.
function diagnostic(string $path): string
{
    $text = preg_replace('#(?<=://)[^:@/\s]+:[^@/\s]+(?=@)#', 'USER:PASSWORD', (string) file_get_contents($path));
    $text = trim((string) preg_replace('/\s+/', ' ', $text));
    return strlen($text) > DIAGNOSTIC_LIMIT ? substr($text, 0, DIAGNOSTIC_LIMIT) . '...' : $text;
}

function runDrush(string $binary, array $command, string $failure): void
{
    $errors = tempnam(sys_get_temp_dir(), 'drupack-step-');
    if ($errors === false) {
        throw new RuntimeException('Cannot create a temporary file for the step diagnostic');
    }
    try {
        // stderr is a file because nothing drains a pipe while the child runs; a full one would deadlock.
        [, $exitCode] = capture(array_merge([$binary, 'php-cli'], $command), application(), $failure, $errors);
        $reason = $exitCode === 0 ? '' : diagnostic($errors);
    } finally {
        unlink($errors);
    }
    if ($exitCode !== 0) {
        throw new RuntimeException($reason === '' ? $failure : "$failure: $reason");
    }
}

// Drupack's Drush entry, laid over the site from application/drush. The entry requires the
// script DRUPACK_DRUSH_SCRIPT names, which the launcher and the seed build set.
function drushPath(): string
{
    return application() . '/drush';
}

// Reads one Drush field. An unusable site answers with anything but the expected value.
function drushField(string $binary, array $command): string
{
    return capture(array_merge([$binary, 'php-cli', drushPath()], $command), application(), 'Cannot run Drush')[0];
}

function databaseHoldsTables(array $options): bool
{
    $connection = new PDO(
        sprintf('%s:host=%s;port=%s;dbname=%s', $options['database'], $options['db-host'], databasePort($options), $options['db-name']),
        $options['db-user'],
        $options['db-password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    // PostgreSQL resolves its own schemas from the search path, which need not name public first.
    $schema = $options['database'] === 'mysql' ? '= DATABASE()' : '= ANY(current_schemas(false))';
    $statement = $connection->query("SELECT count(*) FROM information_schema.tables WHERE table_schema $schema");
    return (int) $statement->fetchColumn() > 0;
}

function installDrupal(array $options, string $binary, SiteContract $contract): void
{
    $drush = drushPath();
    $recipe = application() . '/' . $contract->recipe;
    if (!is_file($drush) || !is_dir($recipe)) {
        throw new RuntimeException('Bundled Drupal installation files are unavailable');
    }
    // Every local account can list a process's arguments, so the database credentials
    // come from the settings step's settings.php and the password from the environment.
    runDrush($binary, [
        $drush,
        'site:install',
        $recipe,
        '--yes',
        '--account-name=' . $options['admin-user'],
        '--site-name=' . $options['site-name'],
    ], 'Drupal installation failed');
    configureAdministrator($binary);
}

// The database is the user's only copy: an installed site is kept, and other tables stop the start.
// A database this initialization began installing into held no tables then, so a site that
// boots there now may have been cut off mid-recipe, and it is never adopted. Its tables stay
// until a person empties the database: nothing here proves they are still the install's own.
function installSite(SiteData $site, array $options, string $binary, SiteContract $contract): bool
{
    $started = $site->installStarted();
    // Without the recorded settings the options come from the command line, which may name
    // any database, so a resume names none of them to empty.
    if ($started && !file_exists($site->settings())) {
        throw new RuntimeException("This Site data holds an interrupted installation but no settings.php: $site->directory. "
            . "Restore its settings.php, or start " . executableName() . " with an empty Site data directory.");
    }
    if (!$started && drushField($binary, ['status', '--field=bootstrap']) === 'Successful') {
        fwrite(STDERR, "This database already holds a site. " . executableName() . " enabled nothing on it, and keeps its own administrator account.\n");
        return true;
    }
    if ($contract->recipe === '') {
        throw new RuntimeException("The database for $site->directory holds no installed site, and " . executableName()
            . " has no recipe to install one. Name the database that holds its site, then start " . executableName() . " again.");
    }
    // A SQLite database is a file this start just created, so it holds no tables to protect.
    if ($options['database'] !== 'sqlite' && databaseHoldsTables($options)) {
        if ($started) {
            throw new RuntimeException("This Site data began installing into the {$options['database']} database {$options['db-name']} on "
                . urlHost($options['db-host']) . ':' . databasePort($options) . ", which now holds tables: $site->directory. "
                . "If they are that installation's, empty the database, then start " . executableName() . " again. "
                . "If it holds a site of yours, start " . executableName() . " with an empty Site data directory.");
        }
        throw new RuntimeException("The database for $site->directory holds tables without an installed site. Empty it or name another database, then start " . executableName() . " again.");
    }
    if (!$started) {
        $site->startInstall();
    }
    installDrupal($options, $binary, $contract);
    return false;
}

// The seed build installs the administrator with this password, and the first start replaces
// it. It ships in every executable.
function seedPassword(): string
{
    return 'drupack-seed-password';
}

// File presence cannot tell a finished site from an interrupted one, so adoption asks Drupal.
// A first start interrupted before its administrator step can still bootstrap, with the seed
// password in place, and adoption must not hand that back: the password is public. The name
// is not a signal: a site installed with --admin-user drupack-admin is a legitimate account.
function adoptSite(string $data, string $binary): void
{
    if (drushField($binary, ['status', '--field=bootstrap']) !== 'Successful') {
        throw new RuntimeException("This Site data holds settings but no installed site: $data. Inspect it with: " . executableName() . " drush --data-dir $data status");
    }
    $expression = '$account = \\Drupal\\user\\Entity\\User::load(1); print \\Drupal::service("password")->check('
        . var_export(seedPassword(), true) . ', $account->getPassword()) ? "yes" : "no";';
    if (drushField($binary, ['php:eval', $expression]) === 'yes') {
        throw new RuntimeException("This Site data holds a site whose administrator still accepts the packaged seed password: $data. "
            . "Set a new password, then start " . executableName() . " again: " . executableName() . " drush --data-dir $data php:eval "
            . '\'$account = \\Drupal\\user\\Entity\\User::load(1); $account->setPassword("new-password"); $account->save();\'');
    }
}

function copySeed(SiteData $site): void
{
    $seed = application() . '/seed';
    if (!copy("$seed/site.sqlite", $site->database())) {
        throw new RuntimeException('Cannot initialize the Seed site database');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$seed/files", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $destination = getenv('DRUPACK_RUNTIME_FILES_DIR') . '/' . $files->getSubPathName();
        if ($file->isDir()) {
            directory($destination);
        } elseif (!copy($file->getPathname(), $destination)) {
            throw new RuntimeException("Cannot initialize Seed site file: $destination");
        }
    }
}

function clearSeedCaches(SiteData $site): void
{
    $database = new PDO('sqlite:' . $site->database());
    $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name GLOB 'cache_*'");
    foreach ($tables as $table) {
        $database->exec('DELETE FROM "' . str_replace('"', '""', $table['name']) . '"');
    }
}

function configureAdministrator(string $binary): void
{
    runDrush($binary, [drushPath(), 'php:eval', '$account = \\Drupal\\user\\Entity\\User::load(1); $account->set("name", getenv("DRUPACK_ADMIN_USER")); $account->setPassword(getenv("DRUPACK_ADMIN_PASSWORD")); $account->save();'], 'Cannot configure Drupal administrator');
}

// Every copy of a release carries the seed's cron key, private key and site UUID, so the
// first start replaces them with values of its own.
function configureSeedIdentity(string $binary): void
{
    runDrush($binary, [drushPath(), 'php:eval', '$random = fn() => \\Drupal\\Component\\Utility\\Crypt::randomBytesBase64(55); \\Drupal::state()->set("system.cron_key", $random()); \\Drupal::service("private_key")->set($random()); \\Drupal::configFactory()->getEditable("system.site")->set("uuid", \\Drupal::service("uuid")->generate())->save();'], 'Cannot replace the seed site\'s keys');
}

// The first start renames the seed's build-time site name.
function configureSeedSiteName(string $binary, string $name): void
{
    runDrush($binary, [drushPath(), 'config:set', 'system.site', 'name', $name, '--yes'], 'Cannot set the site name');
}

// Drupal CMS recipes enable these during site:install. automatic_updates 4.1.0 stalls a cron
// request, and package_manager cannot write into the read-only application. A resumed modules step can run after an earlier one
// already removed them, and Drush refuses to uninstall a module that is not enabled.
function removeRecipeModules(string $binary): void
{
    $enabled = json_decode(drushField($binary, ['pm:list', '--status=enabled', '--format=json']), true, 512, JSON_THROW_ON_ERROR);
    $present = array_intersect(['automatic_updates', 'package_manager'], array_keys($enabled));
    if ($present !== []) {
        runDrush($binary, array_merge([drushPath(), 'pm:uninstall'], $present, ['--yes']), 'Cannot remove automatic updates');
    }
}

function runStep(Step $step, SiteData $site, array $options, string $binary, bool $adopted): bool
{
    switch ($step) {
        case Step::Seed:
            copySeed($site);
            clearSeedCaches($site);
            return false;
        case Step::Settings:
            // A repeated step keeps the existing secret, so the site's sessions and tokens survive it.
            if (!file_exists($site->secret())
                && file_put_contents($site->secret(), bin2hex(random_bytes(32)), LOCK_EX) === false) {
                throw new RuntimeException('Cannot initialize the site secret');
            }
            writeSettings($site->settings(), application() . '/settings.php', databaseConfiguration($options, $site), siteContract()->settings);
            return false;
        case Step::Administrator:
            configureAdministrator($binary);
            configureSeedIdentity($binary);
            configureSeedSiteName($binary, $options['site-name']);
            return false;
        case Step::Install:
            return installSite($site, $options, $binary, siteContract());
        case Step::Modules:
            if (!$adopted) {
                removeRecipeModules($binary);
            }
            return false;
        case Step::Adopt:
            adoptSite($site->directory, $binary);
            return false;
    }
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

// Null when the probe cannot tell, such as for a database it cannot reach.
function siteInstalled(string $binary, string $project, string $docroot): ?bool
{
    [$answer, $status] = capture([$binary, 'php-cli', __DIR__ . '/installed.php', $project, $docroot], $project, 'Cannot run the installation probe');
    return $status === 0 ? $answer === 'yes' : null;
}

// The platform check, then the installation probe. installed is null when the probe cannot tell.
function check(string $binary, string $project, string $docroot): array
{
    checkPlatform($binary, $project);
    return ['installed' => siteInstalled($binary, $project, $docroot)];
}

// The tome_static_directory the project's settings.php sets, or null when it sets none. Reads
// settings.php alone: no kernel boots and no database opens.
function staticDirectory(string $project, string $docroot): array
{
    $autoloader = require "$project/vendor/autoload.php";
    chdir($docroot);
    Settings::initialize($docroot, DrupalKernel::findSitePath(Request::create('/')), $autoloader);
    $directory = Settings::get('tome_static_directory');
    if ($directory !== null && (!is_string($directory) || $directory === '')) {
        throw new RuntimeException("tome_static_directory in the project's settings.php must name a directory.");
    }
    return ['directory' => $directory];
}

// Whether the Packaged site at $data owes no step. Refuses, before the launcher writes any Site
// data, when the options lack what an owed step needs.
function checkSite(string $data, SiteContract $contract): array
{
    [$steps] = owedSteps(new SiteData($data), startOptions($contract), $contract);
    return ['installed' => $steps === []];
}

// Runs the steps $data owes, under the launcher's lease. They are read again here: another start
// may have changed them since `check site`, and the list can owe the administrator step with
// credentials generated now. credentials reports whether a step needed them, adopted whether the
// database already held a site.
function prepare(string $binary, string $data, SiteContract $contract): array
{
    $site = new SiteData($data);
    [$steps, $options] = owedSteps($site, startOptions($contract), $contract);
    // The administrator step's Drush reads them from the environment, never from argv.
    putenv("DRUPACK_ADMIN_USER={$options['admin-user']}");
    putenv("DRUPACK_ADMIN_PASSWORD={$options['admin-password']}");
    // A start refreshes the recorded settings from this release's template. A pending settings
    // step writes the file itself, so its contents are read once they are final. A resumed step
    // then names the recorded database, never one the command line names.
    if (file_exists($site->settings()) && !in_array(Step::Settings, $steps, true)) {
        $options = recordedOptions($options, $site);
        writeSettings($site->settings(), application() . '/settings.php', databaseConfiguration($options, $site), $contract->settings);
    }
    // The remaining steps reach the disk before the first one changes anything, and the
    // completion marker follows the last one.
    $number = 0;
    $adopted = $site->initialize($steps, function (Step $step, bool $adopted) use (&$number, $steps, $site, $options, $binary): bool {
        fwrite(STDERR, sprintf("[%d/%d] %s\n", ++$number, count($steps), $step->report()));
        return runStep($step, $site, $options, $binary, $adopted);
    });
    return ['adopted' => $adopted, 'credentials' => in_array(Need::Credentials, Step::owed($steps), true)];
}

// Installs the Seed site into $data through the steps a MySQL first start runs, against SQLite.
// The build holds the seed's password; the first start of a copy replaces it.
function seed(string $binary, string $data, SiteContract $contract): array
{
    $options = [...startOptions($contract), 'database' => 'sqlite', 'admin-user' => 'drupack-admin', 'admin-password' => seedPassword()];
    putenv("DRUPACK_ADMIN_USER={$options['admin-user']}");
    putenv("DRUPACK_ADMIN_PASSWORD={$options['admin-password']}");
    $site = new SiteData($data);
    $steps = [Step::Settings, Step::Install, Step::Modules];
    $site->initialize($steps, function (Step $step, bool $adopted) use ($site, $options, $binary): bool {
        fwrite(STDERR, $step->report() . "\n");
        return runStep($step, $site, $options, $binary, $adopted);
    });
    return [];
}

function operate(string $binary, array $words): array
{
    return match (true) {
        $words[0] === 'check' && $words[1] === 'folder' => check($binary, $words[2], $words[3]),
        $words[0] === 'check' && $words[1] === 'site' => checkSite($words[2], siteContract()),
        $words[0] === 'static-directory' => staticDirectory($words[1], $words[2]),
        $words[0] === 'prepare' => prepare($binary, $words[1], siteContract()),
        $words[0] === 'seed' => seed($binary, $words[1], siteContract()),
    };
}

// application/tests/operate_test.php and prepare_test.php load this file for its functions alone.
if (defined('DRUPACK_OPERATE_LIBRARY')) {
    return;
}

try {
    if ($argv[1] === 'prepare' || $argv[1] === 'seed' || $argv[1] === 'check' && $argv[2] === 'site') {
        registerSupport();
    }
    $record = operate(getenv('DRUPACK_RUNTIME_BINARY'), array_slice($argv, 1));
    fwrite(STDOUT, json_encode($record, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
