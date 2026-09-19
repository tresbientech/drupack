<?php

declare(strict_types=1);

function directory(string $path): void
{
    // Another start can create the same directory between the check and the call.
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create directory: $path");
    }
}

function windows(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

function nullDevice(): string
{
    return windows() ? 'NUL' : '/dev/null';
}

function process(string $binary, array $arguments, array $descriptors, string $directory, string $failure): int
{
    $command = array_merge([$binary], $arguments);
    $child = proc_open($command, $descriptors, $pipes, $directory);
    if (!is_resource($child)) {
        throw new RuntimeException($failure);
    }
    return proc_close($child);
}

// On Windows the child starts in $directory. pcntl_exec keeps the current working directory.
function replaceProcess(string $binary, array $arguments, string $directory, string $failure): never
{
    if (!windows()) {
        pcntl_exec($binary, $arguments);
        throw new RuntimeException($failure);
    }
    exit(process($binary, $arguments, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $directory, $failure));
}

function windowsJunction(string $target, string $link): void
{
    // proc_open quotes array arguments, and cmd.exe does not run a quoted "mklink". mklink rejects forward slashes.
    $command = 'mklink /J ' . escapeshellarg(str_replace('/', '\\', $link)) . ' ' . escapeshellarg(str_replace('/', '\\', $target));
    $child = proc_open($command, [0 => ['file', nullDevice(), 'r'], 1 => ['file', nullDevice(), 'w'], 2 => ['file', nullDevice(), 'w']], $pipes, __DIR__);
    if (!is_resource($child) || proc_close($child) !== 0) {
        throw new RuntimeException("Cannot link site storage: $link");
    }
}

function settingsProxy(string $link): void
{
    $content = "<?php\nrequire getenv('DRUPACK_RUNTIME_DATA_DIR') . DIRECTORY_SEPARATOR . 'settings.php';\n";
    if (is_file($link)) {
        if (file_get_contents($link) === $content) {
            return;
        }
        throw new RuntimeException("Unexpected application path: $link");
    }
    if (file_put_contents($link, $content, LOCK_EX) === false) {
        throw new RuntimeException("Cannot write site configuration: $link");
    }
}

function siteLink(string $target, string $link): void
{
    if (windows()) {
        if (is_file($target)) {
            settingsProxy($link);
            return;
        }
        if (is_dir($link) && realpath($link) === realpath($target)) {
            return;
        }
        if (file_exists($link) || is_link($link)) {
            throw new RuntimeException("Unexpected application path: $link");
        }
        windowsJunction($target, $link);
        return;
    }
    // Existing application paths must never redirect writes into another site.
    if (is_link($link) && readlink($link) === $target) {
        return;
    }
    if (file_exists($link) || is_link($link)) {
        throw new RuntimeException("Unexpected application path: $link");
    }
    if (!symlink($target, $link)) {
        throw new RuntimeException("Cannot link site storage: $link");
    }
}

function environment(string $name): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? null : $value;
}

function options(array $arguments, bool $drush): array
{
    $options = [
        'data-dir' => environment('DRUPACK_DATA_DIR') ?? './data',
        // The listener defaults after the recorded one is read, so an absent option is still visible here.
        'listen' => null,
        'host' => null,
        'database' => environment('DRUPACK_DATABASE') ?? 'sqlite',
        'db-host' => environment('DRUPACK_DB_HOST'),
        'db-port' => environment('DRUPACK_DB_PORT'),
        'db-name' => environment('DRUPACK_DB_NAME'),
        'db-user' => environment('DRUPACK_DB_USER'),
        'db-password' => environment('DRUPACK_DB_PASSWORD'),
        'admin-user' => environment('DRUPACK_ADMIN_USER'),
        'admin-password' => environment('DRUPACK_ADMIN_PASSWORD'),
        'site-name' => environment('DRUPACK_SITE_NAME') ?? 'Drupal Mercury Demo',
        'no-browser' => null,
    ];
    $command = [];
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if ($drush && !array_key_exists(substr(explode('=', $argument, 2)[0], 2), $options)) {
            $command = array_merge([$argument], $arguments);
            break;
        }
        if ($argument === '--help') {
            echo "Usage: drupack php-cli launch.php [--data-dir PATH] [--listen IP:PORT] [--host HOST] [--database sqlite|mysql|pgsql] [--site-name NAME] [--no-browser]\n";
            exit(0);
        }
        $parts = explode('=', $argument, 2);
        $name = substr($parts[0], 2);
        // Launch arguments are user input, so reject unknown options and missing values.
        if (!str_starts_with($argument, '--') || !array_key_exists($name, $options)) {
            throw new InvalidArgumentException("Unknown argument: $argument");
        }
        if ($name === 'no-browser') {
            $options[$name] = '1';
            continue;
        }
        $value = $parts[1] ?? array_shift($arguments);
        if ($value === null || $value === '' || str_starts_with($value, '--')) {
            throw new InvalidArgumentException("Missing value for --$name");
        }
        $options[$name] = $value;
    }
    return [$options, $command];
}

// The generated password is never shown: the printed one-time login link takes the reader to
// the account form, where they choose their own. It only keeps the account from being
// passwordless. It reaches only the Drupal step that sets it.
function administratorCredentials(array $options, array $steps): array
{
    if (!credentialsRequired($steps)) {
        return $options;
    }
    $options['admin-user'] ??= 'admin';
    $options['admin-password'] ??= bin2hex(random_bytes(32));
    return $options;
}

// The server process waits for its own first response, prints the readiness line and opens
// the browser when asked. A request spends a one-time login link, so readiness is polled on
// $url and the browser opens on $target.
function openWhenServing(string $url, string $target, bool $browser): void
{
    putenv("DRUPACK_RUNTIME_URL=$url");
    putenv("DRUPACK_RUNTIME_OPEN=$target");
    putenv('DRUPACK_RUNTIME_BROWSER=' . ($browser ? '1' : '0'));
}

function markerPath(string $directory): string
{
    return "$directory/site-installed";
}

function progressPath(string $directory): string
{
    return "$directory/installation-progress";
}

// Marks a database the install step found already occupied, so a later or resumed
// modules step changes nothing on it.
function adoptedPath(string $directory): string
{
    return "$directory/site-adopted";
}

// Recorded the moment a database is first seen fresh, so a crash between that
// moment and the install step's own bootstrap check still tells a resume the
// difference between a foreign database and one Drupack is still installing.
function firstEverPath(string $directory): string
{
    return "$directory/first-install";
}

// Every start records where it serves, so a later `dr` addresses the site on the port it
// actually uses. Site data written before this record falls back to the listener defaults.
function listenerPath(string $directory): string
{
    return "$directory/listener";
}

function recordedListener(array $options, string $directory): array
{
    if (!file_exists(listenerPath($directory))) {
        return $options;
    }
    $record = json_decode((string) file_get_contents(listenerPath($directory)), true);
    if (!is_array($record)) {
        throw new RuntimeException('Cannot read the recorded listener: ' . listenerPath($directory)
            . ". Remove that file, then start Drupack again to record it.");
    }
    $options['listen'] ??= $record['listen'] ?? throw new RuntimeException('Recorded listener has no listen address');
    $options['host'] ??= $record['host'] ?? throw new RuntimeException('Recorded listener has no host');
    return $options;
}

function writeListener(string $directory, array $options): void
{
    $record = json_encode(['listen' => $options['listen'], 'host' => $options['host']]);
    if (file_put_contents(listenerPath($directory), $record, LOCK_EX) === false) {
        throw new RuntimeException('Cannot record the listener');
    }
}

function credentialsRequired(array $steps): bool
{
    return array_intersect(['administrator', 'install'], $steps) !== [];
}

// Progress lives beside the recorded settings, so a start tells a finished site from an interrupted
// one and repeats no step that already wrote to a database.
function remainingSteps(string $directory, string $backend): array
{
    if (file_exists(markerPath($directory))) {
        return [];
    }
    if (file_exists(progressPath($directory))) {
        $steps = json_decode((string) file_get_contents(progressPath($directory)), true);
        if (!is_array($steps)) {
            throw new RuntimeException('Cannot read the recorded initialization progress: ' . progressPath($directory)
                . ". Remove that file, then start Drupack again to check the site.");
        }
        return $steps;
    }
    if (file_exists("$directory/settings.php")) {
        return ['adopt'];
    }
    // The database is the user's only copy, so a start without recorded settings never seeds over one.
    if (file_exists("$directory/site.sqlite")) {
        throw new RuntimeException("This Site data holds a database without settings: $directory. Restore its settings.php, or start Drupack with an empty Site data directory.");
    }
    if ($backend === 'sqlite') {
        return ['seed', 'settings', 'administrator'];
    }
    // The directory may not exist yet on the check that runs before it is created;
    // the authoritative check that runs under the startup lock always finds it.
    if (is_dir($directory) && file_put_contents(firstEverPath($directory), '', LOCK_EX) === false) {
        throw new RuntimeException('Cannot record that this database is new to Drupack');
    }
    return ['settings', 'install', 'modules'];
}

function writeProgress(string $data, array $steps): void
{
    if (file_put_contents(progressPath($data), json_encode($steps), LOCK_EX) === false) {
        throw new RuntimeException('Cannot record the initialization progress');
    }
}

// A first start on a database server takes its connection from the command line. SQLite needs
// none, and a resumed start reads the connection from the recorded settings.
function requireDatabaseOptions(array $options, array $steps): void
{
    if (!in_array('settings', $steps, true) || $options['database'] === 'sqlite') {
        return;
    }
    foreach (['db-host', 'db-name', 'db-user', 'db-password'] as $name) {
        if ($options[$name] === null) {
            throw new RuntimeException("Missing database connection details for {$options['database']}");
        }
    }
}

// One start at a time initializes a Site data directory. The resolved path gives equivalent paths one lock.
function startupLock(string $data)
{
    $handle = fopen("$data/startup.lock", 'c');
    if ($handle === false) {
        throw new RuntimeException("Cannot open the startup lock: $data/startup.lock");
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException("Another Drupack start is preparing this Site data: $data");
    }
    return $handle;
}

function databasePort(array $options): string
{
    return $options['db-port'] ?? ($options['database'] === 'mysql' ? '3306' : '5432');
}

function databaseConfiguration(array $options, string $data): array
{
    if ($options['database'] === 'sqlite') {
        return [
            'driver' => 'sqlite',
            'database' => "$data/site.sqlite",
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

function settings(string $template, array $database): string
{
    $content = file_get_contents($template);
    if ($content === false) {
        throw new RuntimeException('Cannot read Drupal settings template');
    }
    return str_replace('__DRUPACK_DATABASE_CONFIGURATION__', var_export($database, true), $content);
}

function writeSettings(string $path, string $template, array $database): void
{
    if (file_put_contents($path, settings($template, $database), LOCK_EX) === false) {
        throw new RuntimeException('Cannot initialize Drupal settings');
    }
}

// The recorded settings decide the backend once they exist, so later starts need no database options.
function recordedOptions(array $options, string $directory): array
{
    $databases = [];
    require "$directory/settings.php";
    $recorded = $databases['default']['default'];
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

function linkSite(string $data): void
{
    siteLink("$data/settings.php", __DIR__ . '/web/sites/default/settings.php');
    siteLink("$data/files", __DIR__ . '/web/sites/default/files');
}

function databaseUrl(array $options): string
{
    $host = str_contains($options['db-host'], ':') ? "[{$options['db-host']}]" : $options['db-host'];
    $port = databasePort($options);
    return sprintf(
        '%s://%s:%s@%s:%s/%s',
        $options['database'],
        rawurlencode($options['db-user']),
        rawurlencode($options['db-password']),
        $host,
        $port,
        rawurlencode($options['db-name']),
    );
}

function runDrush(string $binary, array $command, string $failure): void
{
    $exitCode = process($binary, array_merge(['php-cli'], $command), [0 => ['file', nullDevice(), 'r'], 1 => ['file', nullDevice(), 'w'], 2 => ['file', nullDevice(), 'w']], __DIR__, $failure);
    if ($exitCode !== 0) {
        throw new RuntimeException($failure);
    }
}

function drushPath(): string
{
    return __DIR__ . '/vendor/drush/drush/drush.php';
}

// Reads one Drush field. An unusable site answers with anything but the expected value.
function drushField(string $binary, array $command): string
{
    $descriptors = [0 => ['file', nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', nullDevice(), 'w']];
    $child = proc_open(array_merge([$binary, 'php-cli', drushPath()], $command), $descriptors, $pipes, __DIR__);
    if (!is_resource($child)) {
        throw new RuntimeException('Cannot run Drush');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($child);
    return trim((string) $output);
}

// Drush prints the link and nothing else, addressing the site through DRUSH_OPTIONS_URI. It
// must open no browser of its own: the site is not serving yet, and the Go entrypoint opens one
// once it answers. The link is a working credential arriving from a subprocess and heading for
// a browser command, so its origin is checked before anything uses it.
function loginLink(string $binary, string $url): string
{
    $link = drushField($binary, ['user:login', '--no-browser']);
    if (!str_starts_with($link, $url)) {
        throw new RuntimeException('Cannot obtain a one-time login link for the administrator account');
    }
    return $link;
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

function installDrupal(array $options, string $binary): void
{
    $drush = drushPath();
    $recipe = __DIR__ . '/recipes/mercury_demo';
    if (!is_file($drush) || !is_dir($recipe)) {
        throw new RuntimeException('Bundled Drupal installation files are unavailable');
    }
    runDrush($binary, [
        $drush,
        'site:install',
        $recipe,
        '--yes',
        '--db-url=' . databaseUrl($options),
        '--account-name=' . $options['admin-user'],
        '--account-pass=' . $options['admin-password'],
        '--site-name=' . $options['site-name'],
    ], 'Drupal installation failed');
}

// The database is the user's only copy: an installed site is kept, and other tables stop the start.
// A resumed installation already owns this database, so only a first-ever run treats it as adopted.
function installSite(string $data, array $options, string $binary, bool $firstEver): bool
{
    if (drushField($binary, ['status', '--field=bootstrap']) === 'Successful') {
        if (!$firstEver) {
            return false;
        }
        fwrite(STDOUT, "This database already holds a site. Drupack enabled nothing on it, and keeps its own administrator account.\n");
        return true;
    }
    if (databaseHoldsTables($options)) {
        throw new RuntimeException("The database for $data holds tables without an installed site. Empty it or name another database, then start Drupack again.");
    }
    installDrupal($options, $binary);
    return false;
}

// The Dockerfile installs the seed with this password, on the site:install line that
// builds /app/seed, and tests/initialization.sh checks that they match. It ships in
// the Dockerfile and in every executable.
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
        throw new RuntimeException("This Site data holds settings but no installed site: $data. Inspect it with: drupack dr --data-dir $data status");
    }
    $expression = '$account = \\Drupal\\user\\Entity\\User::load(1); print \\Drupal::service("password")->check('
        . var_export(seedPassword(), true) . ', $account->getPassword()) ? "yes" : "no";';
    if (drushField($binary, ['php:eval', $expression]) === 'yes') {
        throw new RuntimeException("This Site data holds a site whose administrator still accepts the packaged seed password: $data. "
            . "Set a new password, then start Drupack again: drupack dr --data-dir $data php:eval "
            . '\'$account = \\Drupal\\user\\Entity\\User::load(1); $account->setPassword("new-password"); $account->save();\'');
    }
}

function copySeed(string $data): void
{
    $seed = __DIR__ . '/seed';
    if (!copy("$seed/site.sqlite", "$data/site.sqlite")) {
        throw new RuntimeException('Cannot initialize the Seed site database');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$seed/files", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $destination = "$data/files/" . $files->getSubPathName();
        if ($file->isDir()) {
            directory($destination);
        } elseif (!copy($file->getPathname(), $destination)) {
            throw new RuntimeException("Cannot initialize Seed site file: $destination");
        }
    }
}

function clearSeedCaches(string $data): void
{
    $database = new PDO("sqlite:$data/site.sqlite");
    $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name GLOB 'cache_*'");
    foreach ($tables as $table) {
        $database->exec('DELETE FROM "' . str_replace('"', '""', $table['name']) . '"');
    }
}

function configureSeedAdministrator(string $binary): void
{
    runDrush($binary, [drushPath(), 'php:eval', '$account = \\Drupal\\user\\Entity\\User::load(1); $account->set("name", getenv("DRUPACK_ADMIN_USER")); $account->setPassword(getenv("DRUPACK_ADMIN_PASSWORD")); $account->save();'], 'Cannot configure Drupal administrator');
}

// Drush names the seed when the Dockerfile installs it. The first start renames it, so a
// seeded site and an installed one carry the same name.
function configureSeedSiteName(string $binary, string $name): void
{
    runDrush($binary, [drushPath(), 'config:set', 'system.site', 'name', $name, '--yes'], 'Cannot set the site name');
}

// The Mercury Demo recipe enables these during site:install; the Dockerfile removes them
// from the seed the same way. A resumed modules step can run after an earlier one
// already removed them, and Drush refuses to uninstall a module that is not enabled.
function removeRecipeModules(string $binary): void
{
    $enabled = json_decode(drushField($binary, ['pm:list', '--status=enabled', '--format=json']), true, 512, JSON_THROW_ON_ERROR);
    $present = array_intersect(['automatic_updates', 'package_manager'], array_keys($enabled));
    if ($present !== []) {
        runDrush($binary, array_merge([drushPath(), 'pm:uninstall'], $present, ['--yes']), 'Cannot remove automatic updates');
    }
}

function runStep(string $step, string $data, array $options, string $binary): void
{
    switch ($step) {
        case 'seed':
            copySeed($data);
            clearSeedCaches($data);
            return;
        case 'settings':
            // A repeated step keeps the existing secret, so the site's sessions and tokens survive it.
            if (!file_exists("$data/hash_salt")
                && file_put_contents("$data/hash_salt", bin2hex(random_bytes(32)), LOCK_EX) === false) {
                throw new RuntimeException('Cannot initialize the site secret');
            }
            writeSettings("$data/settings.php", __DIR__ . '/settings.php', databaseConfiguration($options, $data));
            linkSite($data);
            return;
        case 'administrator':
            configureSeedAdministrator($binary);
            configureSeedSiteName($binary, $options['site-name']);
            return;
        case 'install':
            if (installSite($data, $options, $binary, file_exists(firstEverPath($data)))
                && file_put_contents(adoptedPath($data), '', LOCK_EX) === false) {
                throw new RuntimeException('Cannot record that this database already held a site');
            }
            return;
        case 'modules':
            if (file_exists(adoptedPath($data))) {
                return;
            }
            removeRecipeModules($binary);
            runDrush($binary, [drushPath(), 'pm:enable', 'mcp_tools', '--yes'], 'Cannot enable MCP Tools');
            return;
        case 'adopt':
            adoptSite($data, $binary);
            return;
        default:
            throw new RuntimeException("Unknown initialization step: $step");
    }
}

// Runs under the startup lock. The remaining steps reach the disk before the first one changes anything,
// and the completion marker follows the last one.
function initialize(string $data, array $steps, array $options, string $binary): void
{
    if ($steps === []) {
        return;
    }
    writeProgress($data, $steps);
    foreach ($steps as $index => $step) {
        runStep($step, $data, $options, $binary);
        writeProgress($data, array_slice($steps, $index + 1));
    }
    if (file_put_contents(markerPath($data), '', LOCK_EX) === false) {
        throw new RuntimeException('Cannot record the finished installation');
    }
    unlink(progressPath($data));
    if (file_exists(adoptedPath($data))) {
        unlink(adoptedPath($data));
    }
    if (file_exists(firstEverPath($data))) {
        unlink(firstEverPath($data));
    }
}

try {
    $drush = environment('DRUPACK_RUNTIME_DRUSH') === '1';
    [$options, $command] = options(array_slice($argv, 1), $drush);
    // PHP_BINARY is empty in embedded FrankenPHP; the Go entrypoint exports its own path.
    $binary = getenv('DRUPACK_RUNTIME_BINARY');
    if ($drush && in_array($command[0] ?? '', ['--help', '-h', 'list'], true)) {
        replaceProcess($binary, array_merge(['php-cli', drushPath()], $command), __DIR__, 'Cannot run Drush');
    }
    // Launch arguments are user input, so the backend must name a supported driver.
    if (!in_array($options['database'], ['sqlite', 'mysql', 'pgsql'], true)) {
        throw new InvalidArgumentException('--database requires sqlite, mysql, or pgsql');
    }
    $steps = [];
    if ($drush) {
        // `dr` initializes nothing and takes no startup lock, so Drush works while the server runs.
        if (!file_exists($options['data-dir'] . '/settings.php')) {
            throw new RuntimeException("This Site data has no site yet: {$options['data-dir']}. Start Drupack once to create one.");
        }
        // `dr` serves nothing of its own, so it addresses the site where the last start served.
        $options = recordedListener($options, $options['data-dir']);
    } else {
        $steps = remainingSteps($options['data-dir'], $options['database']);
        $options = administratorCredentials($options, $steps);
    }
    // 7225 spells PACK on a phone keypad, so the default names the product. It is unassigned
    // in /etc/services. It sits above 1024, so no start needs root, and below 32768, so an
    // outbound connection's ephemeral port never holds it first.
    $options['listen'] ??= '127.0.0.1:7225';
    $options['host'] ??= 'localhost';
    requireDatabaseOptions($options, $steps);
    // Listener values enter Caddy configuration and must contain only an IP address and port.
    if (!preg_match('/^(\[[0-9a-fA-F:]+\]|[0-9.]+):([0-9]+)$/D', $options['listen'], $listener)) {
        throw new InvalidArgumentException('--listen requires IP:PORT, with IPv6 enclosed in brackets');
    }
    $bind = trim($listener[1], '[]');
    $port = (int) $listener[2];
    if (!filter_var($bind, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException('Invalid listener address or port');
    }
    // The permitted request host is external input and is converted to an exact Drupal pattern.
    if (!filter_var($options['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        && !filter_var($options['host'], FILTER_VALIDATE_IP)) {
        throw new InvalidArgumentException('Invalid --host value');
    }
    // The log path reaches the Caddyfile as raw text before Caddy tokenizes it, so a quote breaks it.
    if (str_contains($options['data-dir'], '"')) {
        throw new InvalidArgumentException('--data-dir must not contain a double quote');
    }

    umask(0077);
    directory($options['data-dir']);
    $data = realpath($options['data-dir']);
    foreach (['runtime', 'files', 'files/translations', 'private', 'tmp', 'config', 'logs'] as $name) {
        directory("$data/$name");
    }
    putenv("DRUPACK_RUNTIME_DATA_DIR=$data");
    putenv("DRUPACK_RUNTIME_BIND=$bind");
    putenv("DRUPACK_RUNTIME_PORT=$port");
    putenv('DRUPACK_RUNTIME_HOST=' . $options['host']);
    // The address a reader types, never the bind address. Drush builds absolute URLs from this
    // variable. Without it Drupal falls back to http://default, and every printed or mailed
    // link names an unreachable host.
    $url = "http://{$options['host']}:$port/";
    putenv("DRUSH_OPTIONS_URI=$url");
    $logPath = "$data/logs/caddy.log";
    putenv("DRUPACK_RUNTIME_LOG_PATH=$logPath");
    $runtime = realpath("$data/runtime");
    // FrankenPHP extracts the embedded application under the process temporary directory.
    putenv("TMPDIR=$runtime");
    putenv("TEMP=$runtime");
    putenv("TMP=$runtime");
    putenv("XDG_DATA_HOME=$runtime");
    putenv("XDG_CONFIG_HOME=$runtime");
    foreach ([
        'database' => 'DRUPACK_DATABASE',
        'db-host' => 'DRUPACK_DB_HOST',
        'db-port' => 'DRUPACK_DB_PORT',
        'db-name' => 'DRUPACK_DB_NAME',
        'db-user' => 'DRUPACK_DB_USER',
        'db-password' => 'DRUPACK_DB_PASSWORD',
        'admin-user' => 'DRUPACK_ADMIN_USER',
        'admin-password' => 'DRUPACK_ADMIN_PASSWORD',
        'site-name' => 'DRUPACK_SITE_NAME',
    ] as $option => $environment) {
        if ($options[$option] !== null) {
            putenv("$environment={$options[$option]}");
        }
    }
    // FrankenPHP extracts before CLI parsing; re-execute once to obtain a site-specific application root.
    if (dirname(__DIR__) !== $runtime) {
        if (environment('DRUPACK_RUNTIME_RESTARTED') === '1') {
            throw new RuntimeException("The embedded application did not move under $runtime");
        }
        putenv('DRUPACK_RUNTIME_RESTARTED=1');
        $arguments = ['--data-dir', $data, '--listen', $options['listen'], '--host', $options['host']];
        if ($options['no-browser'] !== null) {
            $arguments[] = '--no-browser';
        }
        if ($drush) {
            $arguments = array_merge(['dr'], $arguments, $command);
        }
        replaceProcess($binary, $arguments, $runtime, 'Cannot restart the embedded runtime');
    }

    if (!$drush) {
        writeListener($data, $options);
    }
    $lock = null;
    if ($steps !== []) {
        // The state check and the writes that follow must not interleave with another start.
        $lock = startupLock($data);
        $steps = remainingSteps($data, $options['database']);
    }
    // A start that runs no step here found an already-initialized site, so only the start
    // that creates or resumes one opens a browser on its own.
    $created = $steps !== [];
    if (file_exists("$data/settings.php")) {
        linkSite($data);
        // A pending settings step rewrites the file, so its contents are read once they are final.
        if (!in_array('settings', $steps, true)) {
            $options = recordedOptions($options, $data);
        }
    }
    initialize($data, $steps, $options, $binary);
    if ($lock !== null) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    foreach (glob(__DIR__ . '/translations/*.po') as $translation) {
        $destination = "$data/files/translations/" . basename($translation);
        if (!file_exists($destination) && !copy($translation, $destination)) {
            throw new RuntimeException("Cannot install translation resource: $destination");
        }
    }
    if ($drush) {
        exit(process($binary, array_merge(['php-cli', drushPath()], $command), [0 => STDIN, 1 => STDOUT, 2 => STDERR], __DIR__, 'Cannot run Drush'));
    }
    // A start that set the administrator account hands its reader a way in without a password.
    // A later start prints none: the reader holds a password by then, and `dr user:login`
    // issues a fresh link at any time.
    $link = credentialsRequired($steps) ? loginLink($binary, $url) : null;
    $readiness = "Drupack is ready\n\n  URL:       $url\n";
    if ($link !== null) {
        $readiness .= "  Login:     $link\n";
    }
    fwrite(STDOUT, $readiness . "  Site data: $data\n  Log:       $logPath\n\nPress Ctrl+C to stop.\n");
    // A first start opens the browser for the person who ran it. A script, a
    // container and a test have no terminal on standard input, so they get none.
    // A file manager on Windows has no other way to reach its reader.
    $interactive = $created && stream_isatty(STDIN);
    $browser = $options['no-browser'] === null && ($interactive || environment('DRUPACK_RUNTIME_CONSOLE_OWNED') === '1');
    openWhenServing($url, $link ?? $url, $browser);
    replaceProcess($binary, ['php-server'], __DIR__, 'Cannot start FrankenPHP');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
