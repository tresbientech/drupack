<?php

declare(strict_types=1);

// Shared by launch.php and serve.php, neither of which loads an autoloader for these.

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

// A file manager's console started this process; closing it would end a background server.
function consoleOwned(): bool
{
    return environment('DRUPACK_RUNTIME_CONSOLE_OWNED') === '1';
}

// A person is present when a terminal started this, or a file manager's console did. A
// detached start has no terminal of its own, so it passes on whether its parent had one.
function personPresent(): bool
{
    return stream_isatty(STDIN) || consoleOwned() || environment('DRUPACK_RUNTIME_PERSON') === '1';
}

final class Serving
{
    public readonly string $stopRecord;
    private readonly string $lease;
    private readonly string $log;
    private $handle;

    public function __construct(string $state, string $logDirectory)
    {
        $this->lease = "$state/serving.lock";
        $this->stopRecord = "$state/stop.json";
        $this->log = "$logDirectory/server.log";
    }

    // The lease belongs to the data, not a port. The lock lives on the open file description,
    // which survives the exec into the server, so the kernel releases it when that process
    // ends, a kill included. On Windows this process waits for the server, so the handle lives
    // as long. False means another start serves this data.
    public function claim(): bool
    {
        $this->handle = $this->lock();
        return $this->handle !== null;
    }

    // A record from an earlier server names a port and token nobody listens on, and `stop` reads
    // a missing record as a start still preparing, so it goes. A double-click owns its console,
    // which closing would end along with a background server, so it serves in the foreground.
    // Every other start runs again through the launcher's detach word and relays the server's
    // log until it answers. The lease is released first so the server takes it.
    public function detach(bool $foreground, string $stopCommand, array $arguments): void
    {
        if (file_exists($this->stopRecord)) {
            unlink($this->stopRecord);
        }
        if ($foreground || consoleOwned()) {
            return;
        }
        fclose($this->handle);
        if (personPresent()) {
            putenv('DRUPACK_RUNTIME_PERSON=1');
        }
        replaceProcess(getenv('DRUPACK_RUNTIME_LAUNCHER'),
            array_merge(['detach', $this->log, $stopCommand, '--'], $arguments, ['--foreground']),
            startDirectory(), 'Cannot start the server in the background');
    }

    private function lock()
    {
        $handle = fopen($this->lease, 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the serving lease: $this->lease");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }
}

function urlHost(string $host): string
{
    return str_contains($host, ':') ? "[$host]" : $host;
}

function siteUrl(string $scheme, string $host, int $port): string
{
    return "$scheme://" . urlHost($host) . ":$port/";
}

// PHP verifies HTTPS against DRUPACK_CA_FILE, which holds public roots alone. An HTTPS site
// keeps that bundle plus its own root here, so Drush and cron reach the site's own hub.
const TLS_BUNDLE = 'ca-bundle.pem';

// Points Drush at the bundle the last start wrote into $directory. A site whose last start
// served HTTP has none, and keeps the public roots.
function useTlsBundle(string $directory): void
{
    if (is_file("$directory/" . TLS_BUNDLE)) {
        putenv("DRUPACK_CA_FILE=$directory/" . TLS_BUNDLE);
    }
}

function listenerParts(string $listen, string $option = '--listen'): array
{
    // Listener values enter Caddy configuration and must contain only an IP address and port.
    if (!preg_match('/^(\[[0-9a-fA-F:]+\]|[0-9.]+):([0-9]+)$/D', $listen, $listener)) {
        throw new InvalidArgumentException("$option requires IP:PORT, with IPv6 enclosed in brackets");
    }
    $bind = trim($listener[1], '[]');
    $port = (int) $listener[2];
    if (!filter_var($bind, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535) {
        throw new InvalidArgumentException("Invalid $option address or port");
    }
    return [$bind, $port];
}

// --static-listen resolved: $option, or the site listener's address on the port after $port.
// Returns the bind, the port and the IP:PORT text the record keeps.
function staticListener(?string $option, string $bind, int $port): array
{
    $listen = $option ?? urlHost($bind) . ':' . ($port + 1);
    [$staticBind, $staticPort] = listenerParts($listen, '--static-listen');
    if ($staticPort === $port) {
        throw new InvalidArgumentException("--static-listen must name a port other than the site's $port");
    }
    return [$staticBind, $staticPort, $listen];
}

function listenerPlan(string $bind, int $port, string $host, string $scheme): array
{
    return [
        'bind' => $bind,
        'port' => $port,
        'host' => $host,
        'scheme' => $scheme,
        'url' => siteUrl($scheme, $host, $port),
        'probe' => siteUrl($scheme, probeHost($bind), $port),
    ];
}

// Drush's, Tome's and a request's child processes run the php on PATH, which bin/ points
// back at this runtime.
function runtimePhpOnPath(string $directory): void
{
    putenv("PATH=$directory/bin" . PATH_SEPARATOR . getenv('PATH'));
}

// A wildcard is reached through its own family's loopback, since an IPv6-only listener never
// answers on 127.0.0.1.
function probeHost(string $bind): string
{
    $bind = trim($bind, '[]');
    return match ($bind) {
        '0.0.0.0' => '127.0.0.1',
        '::' => '::1',
        default => $bind,
    };
}

function shellWord(string $value): string
{
    // cmd and PowerShell read a backslash as itself, and PowerShell reads a quoted first
    // word as a string to print, so a Windows path stays bare when nothing else needs quotes.
    if (preg_match('~^[A-Za-z0-9_./:=@%+' . (windows() ? '\\\\' : '') . '-]+$~', $value) === 1) {
        return $value;
    }
    return windows() ? '"' . $value . '"' : "'" . str_replace("'", "'\\''", $value) . "'";
}

// $target is the quoted words `stop` needs to find the same server, empty when its default does.
function stopCommand(string $target): string
{
    $invoked = environment('DRUPACK_RUNTIME_INVOKED');
    $word = shellWord($invoked);
    // PowerShell hands a program its full path, where cmd hands over the typed word, and
    // runs a quoted first word only after `&`. Both shells run `.\NAME`.
    if (windows() && preg_match('~^([A-Za-z]:)?[\\\\/]~', $invoked) === 1) {
        $folder = fn (string $path): string => rtrim(strtr($path, '/', '\\'), '\\');
        if (strcasecmp($folder(dirname($invoked)), $folder(startDirectory())) === 0) {
            $word = '.\\' . basename($invoked);
        } elseif ($word[0] === '"') {
            $word = '& ' . $word;
        }
    }
    return $word . ' stop' . $target;
}

// The directory the reader started in. The launcher records it, since launch.php moves
// to the application's; the engine executable's serve.php stays where it started.
function startDirectory(): string
{
    return environment('DRUPACK_RUNTIME_CWD') ?? getcwd();
}
