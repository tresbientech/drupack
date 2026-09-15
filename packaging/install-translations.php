<?php

declare(strict_types=1);

$snapshot = json_decode(file_get_contents('/build/translations.json'), true, flags: JSON_THROW_ON_ERROR);
if (hash_file('sha256', '/app/composer.lock') !== $snapshot['composer_lock_sha256']) {
    throw new RuntimeException('Refresh the translation snapshot for this Composer lock file');
}
if (hash_file('sha256', '/build/translations.tar.gz') !== $snapshot['archive_sha256']) {
    throw new RuntimeException('Translation archive checksum mismatch');
}
$archive = new PharData('/build/translations.tar.gz');
$archive->extractTo('/app/translations');
foreach ($snapshot['files'] as $name => $file) {
    if (hash_file('sha256', '/app/translations/' . $name) !== $file['sha256']) {
        throw new RuntimeException("Translation checksum mismatch: $name");
    }
}
copy('/build/translations.json', '/app/translations/manifest.json');
