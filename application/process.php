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
