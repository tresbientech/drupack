<?php

declare(strict_types=1);

// build/qa.sh runs this file with the arguments a and b. Composer and Drush read
// their command from $argv[1], so a php-cli that puts its own binary in $argv[0]
// breaks both.

require __DIR__ . '/cases.php';

test('php-cli hands a script its own path, then its arguments', function () use ($argv): void {
    same(__FILE__, realpath($argv[0]));
    same(['a', 'b'], array_slice($argv, 1));
});

exit(runCases());
