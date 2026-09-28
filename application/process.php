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

// Writes $template to $destination with each __DRUPACK_NAME__ token replaced by
// $values[NAME]. The values reach Caddy's parser as raw text, where a double quote ends
// a string, so a value holding one refuses. A token left without a value refuses too,
// rather than reach the server as literal text.
function renderTemplate(string $template, array $values, string $destination): void
{
    $content = file_get_contents($template);
    if ($content === false) {
        throw new RuntimeException("Cannot read $template");
    }
    foreach ($values as $name => $value) {
        if (str_contains($value, '"')) {
            throw new InvalidArgumentException("$name must not contain a double quote: $value");
        }
        $content = str_replace("__DRUPACK_{$name}__", $value, $content);
    }
    if (preg_match('/__DRUPACK_[A-Z_]+__/', $content, $left) === 1) {
        throw new RuntimeException("$template names {$left[0]}, which has no value");
    }
    // The staging file sits beside the destination, so the rename never crosses a volume.
    $staging = "$destination.new";
    if (file_put_contents($staging, $content) === false || !rename($staging, $destination)) {
        throw new RuntimeException("Cannot write $destination");
    }
}

function directory(string $path): void
{
    // Another start can create the same directory between the check and the call.
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create directory: $path");
    }
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
