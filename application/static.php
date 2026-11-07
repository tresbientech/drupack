<?php

declare(strict_types=1);

// The static listener: --static-listen resolved into $bind and $port, or the site's bind on
// the port after its own. The host and scheme are the site listener's.
function staticPlan(string $bind, int $port, string $host, string $scheme): array
{
    return ['bind' => $bind, 'port' => $port, 'url' => siteUrl($scheme, $host, $port)];
}

// The variables the Caddyfile reads for the static port. Off, the site imports none.caddy
// and nothing else. $tls is the TLS source: none, mkcert or drupack. The dynamic patterns
// travel space joined, in a variable the server environment refuses empty.
function staticServerEnvironment(?array $static, ?array $plan, string $tls): array
{
    if ($static === null) {
        return ['DRUPACK_RUNTIME_STATIC_SNIPPET' => 'none.caddy'];
    }
    $dynamic = $static['dynamic'] ?? [];
    $environment = [
        'DRUPACK_RUNTIME_STATIC_SNIPPET' => "static-site-$tls.caddy",
        'DRUPACK_RUNTIME_STATIC_BIND' => $plan['bind'],
        'DRUPACK_RUNTIME_STATIC_PORT' => (string) $plan['port'],
        'DRUPACK_RUNTIME_STATIC_DYNAMIC_SNIPPET' => $dynamic === [] ? 'none.caddy' : 'static-dynamic.caddy',
    ];
    if ($dynamic !== []) {
        $environment['DRUPACK_RUNTIME_STATIC_DYNAMIC'] = implode(' ', $dynamic);
    }
    return $environment;
}

// The Drush words of `export`: Tome's export at the static URL, then the reader's words. A
// reader who names the URL gets none added.
function exportCommand(string $url, array $words): array
{
    $command = ['tome:static', '--yes'];
    if (!namesUri($words)) {
        $command[] = "--uri=$url";
    }
    return array_merge($command, $words);
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
