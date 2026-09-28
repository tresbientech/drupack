<?php

declare(strict_types=1);

// Process and path helpers that launch.php and the engine executable's serve.php
// share. Neither loads a Composer autoloader for them.

function windows(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

// realpath() returns the native form, backslashes included on Windows. Every path
// Drupack exports or prints takes the canonical form instead, so a Windows reader's
// terminal agrees with the forward slashes Drupal's own stack traces already carry.
function canonical(string $path): string
{
    return canonicalSeparator($path, DIRECTORY_SEPARATOR);
}

// canonicalSeparator takes the separator, the same way canonical.go's internal helper
// does, so a test on any host can drive the Windows case. A backslash is a legal
// character in a file name where it is not the separator, so a host whose separator
// is already '/' gets the path back untouched.
function canonicalSeparator(string $path, string $separator): string
{
    if ($separator === '/') {
        return $path;
    }
    return str_replace($separator, '/', $path);
}

function nullDevice(): string
{
    return windows() ? 'NUL' : '/dev/null';
}

function process(string $binary, array $arguments, array $descriptors, string $directory, string $failure): int
{
    $command = array_merge([$binary], $arguments);
    $child = proc_open($command, $descriptors, $pipes, $directory);
    if (!is_resource($child)) {
        throw new RuntimeException($failure);
    }
    return proc_close($child);
}

// On Windows the child starts in $directory. pcntl_exec keeps the current working directory.
function replaceProcess(string $binary, array $arguments, string $directory, string $failure): never
{
    if (!windows()) {
        pcntl_exec($binary, $arguments);
        throw new RuntimeException($failure);
    }
    exit(process($binary, $arguments, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $directory, $failure));
}

function environment(string $name): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? null : $value;
}

// The executable the reader ran, which the launcher exports. Every message naming a
// command names it, since one engine serves every site built on it.
function executableName(): string
{
    return getenv('DRUPACK_RUNTIME_NAME');
}

// Carries the reason Drush gave for a failed mint, since a start that can still serve
// without the link reports that reason; getMessage() stays the fixed text a start that
// cannot serve without the link exits with.
final class LoginLinkFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Cannot obtain a one-time login link for the administrator account');
    }
}

// Runs the Drush script at $drush from $directory for a one-time login link, opening no
// browser. The link is a working credential arriving from a subprocess and heading for a
// terminal or a browser command, so it must start with $url.
function drushLoginLink(string $binary, string $drush, string $directory, array $arguments, string $url): string
{
    $errors = tempnam(sys_get_temp_dir(), 'drupack-mint-');
    if ($errors === false) {
        throw new RuntimeException('Cannot create a temporary file for the mint diagnostic');
    }
    try {
        // stderr is a file, not a pipe: two pipes deadlock if Drush fills one's kernel
        // buffer while this drains only the other to exhaustion first, and nothing here
        // reads both at once.
        $descriptors = [0 => ['file', nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']];
        // Drush wraps its boxed error text to a column count it reads from COLUMNS, defaulting
        // to 80 with no terminal attached; a wide one keeps a failed mint's reason on one line
        // instead of hard-wrapping mid-word.
        $environment = getenv();
        $environment['COLUMNS'] = '1000';
        $child = proc_open(array_merge([$binary, 'php-cli', $drush, 'user:login', '--no-browser'], $arguments), $descriptors, $pipes, $directory, $environment);
        if (!is_resource($child)) {
            throw new RuntimeException('Cannot run Drush');
        }
        $link = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($child);
        $reason = preg_replace('/\s+/', ' ', trim((string) file_get_contents($errors)));
    } finally {
        unlink($errors);
    }
    if (!str_starts_with($link, $url)) {
        throw new LoginLinkFailure($reason);
    }
    return $link;
}
