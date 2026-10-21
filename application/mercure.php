<?php

declare(strict_types=1);

// Also the iss and aud of every token.
function mercureUrl(string $scheme, string $bind, int $port): string
{
    return siteUrl($scheme, probeHost($bind), $port) . '.well-known/mercure';
}

function mercureServerEnvironment(bool $on, string $directory): array
{
    return [
        'DRUPACK_RUNTIME_MERCURE_SNIPPET' => $on ? 'mercure.caddy' : 'none.caddy',
        'DRUPACK_RUNTIME_MERCURE_DB' => "$directory/mercure.db",
    ];
}

// `drush` creates nothing, so before a start has created the secrets it exports no hub.
function exportMercure(string $publisherSecret, string $subscriberSecret, string $url, bool $drush): void
{
    $secrets = [
        'DRUPACK_MERCURE_PUBLISHER_SECRET' => $publisherSecret,
        'DRUPACK_MERCURE_SUBSCRIBER_SECRET' => $subscriberSecret,
    ];
    if ($drush && array_filter($secrets, 'file_exists') !== $secrets) {
        return;
    }
    foreach ($secrets as $name => $path) {
        // Exclusive creation refuses a file or link already at the path. The mask holds
        // for this file alone, since an engine start leaves the reader's umask in place.
        $mask = umask(0077);
        $handle = @fopen($path, 'x');
        umask($mask);
        if ($handle !== false) {
            $written = fwrite($handle, bin2hex(random_bytes(32)));
            fclose($handle);
            if ($written !== 64) {
                throw new RuntimeException("Cannot write the Mercure secret: $path");
            }
        } elseif (!is_file($path)) {
            throw new RuntimeException("Cannot create the Mercure secret: $path");
        }
        putenv("$name=" . file_get_contents($path));
    }
    putenv("DRUPACK_MERCURE_URL=$url");
}
