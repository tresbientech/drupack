<?php

declare(strict_types=1);

// Shared by launch.php, operate.php and serve.php, none of which loads an autoloader for these.

function windows(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

// realpath() returns backslashes on Windows. Every exported or printed path takes the
// canonical forward slashes instead.
function canonical(string $path): string
{
    return canonicalSeparator($path, DIRECTORY_SEPARATOR);
}

// Takes the separator so a test on any host can drive the Windows case. A backslash is a legal
// file name character where it is not the separator, so a '/' host gets the path back untouched.
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

function environment(string $name): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? null : $value;
}

// The executable the reader ran, exported by the launcher. One engine serves every site.
function executableName(): string
{
    return getenv('DRUPACK_RUNTIME_NAME');
}

function directory(string $path): void
{
    // Another start can create the same directory between the check and the call.
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create directory: $path");
    }
}

// Carries Drush's reason for a failed mint, which a start that can serve without the link
// reports. getMessage() stays the fixed text a start that cannot serve exits with.
final class LoginLinkFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Cannot obtain a one-time login link for the administrator account');
    }
}

// $link is a working credential from a subprocess, bound for a terminal or a browser
// command, so it must start with $url.
function drushLoginLink(string $binary, string $drush, string $directory, array $arguments, string $url): string
{
    $errors = tempnam(sys_get_temp_dir(), 'drupack-mint-');
    if ($errors === false) {
        throw new RuntimeException('Cannot create a temporary file for the mint diagnostic');
    }
    try {
        // stderr is a file: with two pipes, Drush filling one's buffer while this drains the other
        // to exhaustion deadlocks.
        $descriptors = [0 => ['file', nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']];
        // Drush wraps error text at COLUMNS, 80 with no terminal; a wide value keeps a failed
        // mint's reason on one line.
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

function urlHost(string $host): string
{
    return str_contains($host, ':') ? "[$host]" : $host;
}
