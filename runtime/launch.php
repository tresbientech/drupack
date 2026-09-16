<?php

declare(strict_types=1);

function directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0700, true)) {
        throw new RuntimeException("Cannot create directory: $path");
    }
}

function siteLink(string $target, string $link): void
{
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
        'data-dir' => environment('PORTABLE_DRUPAL_DATA_DIR') ?? './data',
        'listen' => '127.0.0.1:8080',
        'host' => 'localhost',
        'database' => environment('PORTABLE_DRUPAL_DATABASE') ?? 'sqlite',
        'db-host' => environment('PORTABLE_DRUPAL_DB_HOST'),
        'db-port' => environment('PORTABLE_DRUPAL_DB_PORT'),
        'db-name' => environment('PORTABLE_DRUPAL_DB_NAME'),
        'db-user' => environment('PORTABLE_DRUPAL_DB_USER'),
        'db-password' => environment('PORTABLE_DRUPAL_DB_PASSWORD'),
        'admin-user' => environment('PORTABLE_DRUPAL_ADMIN_USER'),
        'admin-password' => environment('PORTABLE_DRUPAL_ADMIN_PASSWORD'),
    ];
    $command = [];
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if ($drush && !array_key_exists(substr(explode('=', $argument, 2)[0], 2), $options)) {
            $command = array_merge([$argument], $arguments);
            break;
        }
        if ($argument === '--help') {
            echo "Usage: portable-drupal php-cli launch.php [--data-dir PATH] [--listen IP:PORT] [--host HOST] [--database sqlite|mysql|pgsql]\n";
            exit(0);
        }
        $parts = explode('=', $argument, 2);
        $name = substr($parts[0], 2);
        // Launch arguments are user input, so reject unknown options and missing values.
        if (!str_starts_with($argument, '--') || !array_key_exists($name, $options)) {
            throw new InvalidArgumentException("Unknown argument: $argument");
        }
        $value = $parts[1] ?? array_shift($arguments);
        if ($value === null || $value === '' || str_starts_with($value, '--')) {
            throw new InvalidArgumentException("Missing value for --$name");
        }
        $options[$name] = $value;
    }
    return [$options, $command];
}

function validateDatabaseOptions(array $options, bool $firstStart): void
{
    $administratorOptions = ['admin-user', 'admin-password'];
    if (!in_array($options['database'], ['sqlite', 'mysql', 'pgsql'], true)) {
        throw new InvalidArgumentException('--database requires sqlite, mysql, or pgsql');
    }
    if (!$firstStart) {
        return;
    }
    if ($options['database'] === 'sqlite') {
        foreach ($administratorOptions as $name) {
            if ($options[$name] === null) {
                throw new RuntimeException('Missing Drupal administrator credentials');
            }
        }
        return;
    }
    foreach (['db-host', 'db-name', 'db-user', 'db-password'] as $name) {
        if ($options[$name] === null) {
            throw new RuntimeException("Missing database connection details for {$options['database']}");
        }
    }
    foreach ($administratorOptions as $name) {
        if ($options[$name] === null) {
            throw new RuntimeException('Missing Drupal administrator credentials');
        }
    }
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
    return str_replace('__PORTABLE_DATABASE_CONFIGURATION__', var_export($database, true), $content);
}

function writeSettings(string $path, string $template, array $database): void
{
    if (file_put_contents($path, settings($template, $database), LOCK_EX) === false) {
        throw new RuntimeException('Cannot initialize Drupal settings');
    }
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
    $process = proc_open(array_merge([$binary, 'php-cli'], $command), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException($failure);
    }
    if (proc_close($process) !== 0) {
        throw new RuntimeException($failure);
    }
}

function drushPath(): string
{
    return __DIR__ . '/vendor/drush/drush/drush.php';
}

function installDrupal(array $options, string $binary): void
{
    $drush = drushPath();
    $recipe = __DIR__ . '/recipes/drupal_cms_site_template_base';
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
    ], 'Drupal installation failed');
    runDrush($binary, [$drush, 'pm:enable', 'mcp_tools', '--yes'], 'Cannot enable MCP Tools');
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

function configureSeedAdministrator(string $binary, array $options): void
{
    $drush = drushPath();
    runDrush($binary, [$drush, 'php:eval', '$account = \\Drupal\\user\\Entity\\User::load(1); $account->set("name", getenv("PORTABLE_DRUPAL_ADMIN_USER")); $account->setPassword(getenv("PORTABLE_DRUPAL_ADMIN_PASSWORD")); $account->save();'], 'Cannot configure Drupal administrator');
}

try {
    $drush = environment('PORTABLE_DRUPAL_DRUSH') === '1';
    [$options, $command] = options(array_slice($argv, 1), $drush);
    $binary = realpath('/proc/self/exe');
    if ($drush && in_array($command[0] ?? '', ['--help', '-h', 'list'], true)) {
        pcntl_exec($binary, array_merge(['php-cli', drushPath()], $command));
        throw new RuntimeException('Cannot run Drush');
    }
    $firstStart = !file_exists($options['data-dir'] . '/settings.php');
    validateDatabaseOptions($options, $firstStart);
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

    umask(0077);
    directory($options['data-dir']);
    $data = realpath($options['data-dir']);
    foreach (['runtime', 'files', 'files/translations', 'private', 'tmp', 'config'] as $name) {
        directory("$data/$name");
    }
    putenv("PORTABLE_DATA_DIR=$data");
    putenv("PORTABLE_BIND=$bind");
    putenv("PORTABLE_PORT=$port");
    putenv('PORTABLE_HOST=' . $options['host']);
    putenv("TMPDIR=$data/runtime");
    putenv("XDG_DATA_HOME=$data/runtime");
    putenv("XDG_CONFIG_HOME=$data/runtime");
    foreach ([
        'database' => 'PORTABLE_DRUPAL_DATABASE',
        'db-host' => 'PORTABLE_DRUPAL_DB_HOST',
        'db-port' => 'PORTABLE_DRUPAL_DB_PORT',
        'db-name' => 'PORTABLE_DRUPAL_DB_NAME',
        'db-user' => 'PORTABLE_DRUPAL_DB_USER',
        'db-password' => 'PORTABLE_DRUPAL_DB_PASSWORD',
        'admin-user' => 'PORTABLE_DRUPAL_ADMIN_USER',
        'admin-password' => 'PORTABLE_DRUPAL_ADMIN_PASSWORD',
    ] as $option => $environment) {
        if ($options[$option] !== null) {
            putenv("$environment={$options[$option]}");
        }
    }
    // FrankenPHP extracts before CLI parsing; re-execute once to obtain a site-specific application root.
    if (dirname(__DIR__) !== "$data/runtime") {
        $arguments = ['php-cli', 'launch.php', '--data-dir', $data, '--listen', $options['listen'], '--host', $options['host']];
        if ($drush) {
            $arguments = array_merge($arguments, $command);
        }
        pcntl_exec($binary, $arguments);
        throw new RuntimeException('Cannot restart the embedded runtime');
    }

    if (!file_exists("$data/settings.php")) {
        if ($options['database'] === 'sqlite') {
            copySeed($data);
            clearSeedCaches($data);
        }
        if (file_put_contents("$data/hash_salt", bin2hex(random_bytes(32)), LOCK_EX) === false) {
            throw new RuntimeException('Cannot initialize the site secret');
        }
        writeSettings("$data/settings.php", __DIR__ . '/settings.php', databaseConfiguration($options, $data));
    }
    siteLink("$data/settings.php", __DIR__ . '/web/sites/default/settings.php');
    siteLink("$data/files", __DIR__ . '/web/sites/default/files');
    if ($firstStart && $options['database'] === 'sqlite') {
        configureSeedAdministrator($binary, $options);
    }
    if ($options['database'] !== 'sqlite' && !file_exists("$data/site-installed")) {
        installDrupal($options, $binary);
        if (file_put_contents("$data/site-installed", '', LOCK_EX) === false) {
            throw new RuntimeException('Cannot record Drupal installation');
        }
    }
    foreach (glob(__DIR__ . '/translations/*.po') as $translation) {
        $destination = "$data/files/translations/" . basename($translation);
        if (!file_exists($destination) && !copy($translation, $destination)) {
            throw new RuntimeException("Cannot install translation resource: $destination");
        }
    }
    if ($drush) {
        $process = proc_open(array_merge([$binary, 'php-cli', drushPath()], $command), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, __DIR__);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run Drush');
        }
        exit(proc_close($process));
    }
    fwrite(STDOUT, "Drupal: http://{$options['host']}:$port\nSite data: $data\n");
    pcntl_exec($binary, ['php-server']);
    throw new RuntimeException('Cannot start FrankenPHP');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
