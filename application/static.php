<?php

declare(strict_types=1);

// The static listener: --static-listen resolved into $bind and $port, or the site's bind on
// the port after its own. The host and scheme are the site listener's.
function staticPlan(string $bind, int $port, string $host, string $scheme): array
{
    return ['bind' => $bind, 'port' => $port, 'url' => siteUrl($scheme, $host, $port)];
}

// The Drush words of `export`: Tome's export at $url, then the reader's words. A reader who
// names the URL gets none added, and the engine passes null then, having read no record.
function exportCommand(?string $url, array $words): array
{
    $command = ['tome:static', '--yes'];
    if (!namesUri($words)) {
        $command[] = "--uri=$url";
    }
    return array_merge($command, $words);
}

// Refuses an export from a Drupal without tome_static, the module tome:static comes from. A Drush
// that cannot bootstrap prints no answer, and the export's own run then reports the reason.
function requireTomeStatic(string $binary, string $drush, string $directory): void
{
    $descriptors = [0 => ['file', nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', nullDevice(), 'w']];
    $code = 'echo (int) \Drupal::moduleHandler()->moduleExists("tome_static");';
    $child = proc_open([$binary, 'php-cli', $drush, 'php:eval', $code], $descriptors, $pipes, $directory);
    if (!is_resource($child)) {
        throw new RuntimeException('Cannot run Drush');
    }
    $answer = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    proc_close($child);
    if ($answer === '0') {
        throw new RuntimeException('export runs tome:static, and this Drupal has no tome_static module enabled. Enable it: '
            . executableName() . ' drush pm:install tome_static');
    }
}

// Whether the words name Drush's --uri, in its long, joined or -l forms, before any --.
function namesUri(array $words): bool
{
    foreach ($words as $word) {
        if ($word === '--') {
            return false;
        }
        if ($word === '--uri' || str_starts_with($word, '--uri=') || str_starts_with($word, '-l')) {
            return true;
        }
    }
    return false;
}
