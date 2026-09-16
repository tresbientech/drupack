<?php

declare(strict_types=1);

function translationProjects(array $packages): array
{
    $projects = [];
    foreach ($packages as $package) {
        if ($package['name'] === 'drupal/core') {
            $projects['drupal'] = $package['version'];
        } elseif (in_array($package['type'], ['drupal-module', 'drupal-theme', 'drupal-profile'], true)) {
            // Composer repository metadata may omit Drupal's version annotation.
            $projects[substr($package['name'], 7)] = $package['extra']['drupal']['version'] ?? $package['version'];
        }
    }
    ksort($projects);
    return $projects;
}

function fetchTranslation(string $url): ?string
{
    $request = curl_init($url);
    curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
    $body = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    // Translation HTTP responses are external data; only an explicit 404 means unavailable.
    if ($status === 404) {
        return null;
    }
    if ($status !== 200 || $body === false || !str_contains($body, 'msgid')) {
        throw new RuntimeException("Cannot fetch translation: $url (HTTP $status)");
    }
    return $body;
}

$root = dirname(__DIR__);
$lock = json_decode(file_get_contents("$root/composer.lock"), true, flags: JSON_THROW_ON_ERROR);
$snapshot = ['composer_lock_sha256' => hash_file('sha256', "$root/composer.lock"), 'files' => [], 'missing' => []];
$temporary = tempnam(sys_get_temp_dir(), 'drupack-translations-');
unlink($temporary);
$archive = new PharData($temporary . '.tar');
foreach (translationProjects($lock['packages']) as $project => $version) {
    foreach (['fr', 'zh-hans', 'es', 'hi', 'ar'] as $language) {
        $name = "$project-$version.$language.po";
        $url = "https://ftp.drupal.org/files/translations/all/$project/$name";
        $body = fetchTranslation($url);
        if ($body === null) {
            if ($project === 'drupal') {
                throw new RuntimeException("Required core translation unavailable: $language");
            }
            $snapshot['missing'][] = $name;
            continue;
        }
        $archive->addFromString($name, $body);
        $snapshot['files'][$name] = ['url' => $url, 'sha256' => hash('sha256', $body)];
        echo "$name\n";
    }
}
$archive->compress(Phar::GZ);
copy($temporary . '.tar.gz', __DIR__ . '/translations.tar.gz');
$snapshot['archive_sha256'] = hash_file('sha256', __DIR__ . '/translations.tar.gz');
file_put_contents(__DIR__ . '/translations.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
unlink($temporary . '.tar');
unlink($temporary . '.tar.gz');
printf("Snapshot: %d files, %d missing translations\n", count($snapshot['files']), count($snapshot['missing']));
