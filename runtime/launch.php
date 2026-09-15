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

function options(array $arguments): array
{
    $options = ['data-dir' => './data', 'listen' => '127.0.0.1:8080', 'host' => 'localhost'];
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if ($argument === '--help') {
            echo "Usage: portable-drupal php-cli launch.php [--data-dir PATH] [--listen IP:PORT] [--host HOST]\n";
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
    return $options;
}

try {
    $options = options(array_slice($argv, 1));
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
    $binary = realpath('/proc/self/exe');

    // FrankenPHP extracts before CLI parsing; re-execute once to obtain a site-specific application root.
    if (dirname(__DIR__) !== "$data/runtime") {
        pcntl_exec($binary, ['php-cli', 'launch.php', '--data-dir', $data, '--listen', $options['listen'], '--host', $options['host']]);
        throw new RuntimeException('Cannot restart the embedded runtime');
    }

    if (!file_exists("$data/settings.php")) {
        if (!copy(__DIR__ . '/settings.php', "$data/settings.php")) {
            throw new RuntimeException('Cannot initialize Drupal settings');
        }
        if (file_put_contents("$data/hash_salt", bin2hex(random_bytes(32)), LOCK_EX) === false) {
            throw new RuntimeException('Cannot initialize the site secret');
        }
    }
    siteLink("$data/settings.php", __DIR__ . '/web/sites/default/settings.php');
    siteLink("$data/files", __DIR__ . '/web/sites/default/files');
    foreach (glob(__DIR__ . '/translations/*.po') as $translation) {
        $destination = "$data/files/translations/" . basename($translation);
        if (!file_exists($destination) && !copy($translation, $destination)) {
            throw new RuntimeException("Cannot install translation resource: $destination");
        }
    }
    fwrite(STDOUT, "Drupal: http://{$options['host']}:$port\nSite data: $data\n");
    pcntl_exec($binary, ['php-server']);
    throw new RuntimeException('Cannot start FrankenPHP');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
