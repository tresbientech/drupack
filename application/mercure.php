<?php

declare(strict_types=1);

// The Mercure hub's secrets and variables, which launch.php and the engine executable's
// serve.php share. Both load process.php first.

// The hub's address on a listener, which is also the iss and aud of every token.
function mercureUrl(string $bind, int $port): string
{
    return 'http://' . urlHost(probeHost($bind)) . ":$port/.well-known/mercure";
}

// A start creates each missing secret, readable by its owner alone. Both a start and
// `drush` then export the hub's address and both secrets to the PHP they run. `drush`
// creates nothing, so before a start has created the secrets it exports no hub.
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
