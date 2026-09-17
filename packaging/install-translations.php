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

$lock = json_decode(file_get_contents('/app/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
mkdir('/app/translations');
$downloaded = 0;
$unavailable = 0;
foreach (translationProjects($lock['packages']) as $project => $version) {
    foreach (['fr', 'zh-hans', 'es', 'hi', 'ar'] as $language) {
        $name = "$project-$version.$language.po";
        $body = fetchTranslation("https://ftp.drupal.org/files/translations/all/$project/$name");
        if ($body === null) {
            if ($project === 'drupal') {
                throw new RuntimeException("Required core translation unavailable: $language");
            }
            $unavailable++;
            continue;
        }
        if (file_put_contents("/app/translations/$name", $body) === false) {
            throw new RuntimeException("Cannot write translation: $name");
        }
        $downloaded++;
    }
}
printf("Translations: %d downloaded, %d unavailable\n", $downloaded, $unavailable);
