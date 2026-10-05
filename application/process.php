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

// Sets each variable of $values in this process's environment, which the server inherits
// and Caddy splices into its Caddyfile before it reads quotes. A value holding a double
// quote would end a string there, and an empty value would leave the file's setting empty.
function exportServerEnvironment(array $values): void
{
    foreach ($values as $name => $value) {
        if ($value === '') {
            throw new RuntimeException("$name is empty");
        }
        if (str_contains($value, '"')) {
            throw new InvalidArgumentException("$name must not contain a double quote: $value");
        }
        putenv("$name=$value");
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

// Whether a file manager's console started this process, which closing the window would end
// along with a background server.
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

// The state one server holds while it serves: the Serving lease, the stop record and the
// server log. A site keeps them in Site data, a folder in its cache entry.
final class Serving
{
    // How long `stop` waits for the lease to free. runtime/entrypoint.go forces the
    // server's own exit at 10 seconds.
    private const STOP_WAIT_SECONDS = 15;

    // The server writes its stop channel's port, token and PID here once it answers.
    public readonly string $stopRecord;
    private readonly string $lease;
    // What a background server writes to standard output and error, truncated per start.
    private readonly string $log;
    private $handle;

    public function __construct(string $state, string $logDirectory)
    {
        $this->lease = "$state/serving.lock";
        $this->stopRecord = "$state/stop.json";
        $this->log = "$logDirectory/server.log";
    }

    // The lease belongs to the data rather than to a port: two servers over one set of
    // data would corrupt both, whatever addresses they bind. The lock lives on the open
    // file description, which survives the exec into the server, so the kernel holds it
    // for that process and releases it when the process ends, a kill included. On
    // Windows this process waits for the server it starts, so the handle lives exactly
    // as long. False means another start serves this data.
    public function claim(): bool
    {
        $this->handle = $this->lock();
        return $this->handle !== null;
    }

    // What a start does once it holds the lease. A record from an earlier server names
    // a port and a token nobody listens on, and `stop` reads a missing record as a start
    // still preparing, so it goes. A double-click owns its console, which closing the
    // window would end along with a background server, so it serves where the reader
    // can stop it. Every other start detaches unless it was asked to serve in the
    // foreground: it runs again through the launcher's detach word, with the same
    // arguments and working directory, and relays the server's log until it answers.
    // The lease is released first, so the server takes it. $stopCommand is what the
    // relay prints for ending the server.
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

    // Ends the server that holds the lease, through the stop channel its record names,
    // and waits for the lease to free. $what and $named name the state for a message.
    // Returns the exit code. A free lease means no server runs, whatever a stale record
    // says.
    public function stop(string $what, string $named): int
    {
        if (!is_file($this->lease) || $this->free()) {
            return notRunning();
        }
        if (!is_file($this->stopRecord)) {
            throw new RuntimeException('A ' . executableName() . " start is still preparing this $what: $named");
        }
        $channel = json_decode((string) file_get_contents($this->stopRecord), true, 512, JSON_THROW_ON_ERROR);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$channel['token']}\r\nContent-Length: 0\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        @file_get_contents("http://127.0.0.1:{$channel['port']}/stop", false, $context);
        $status = $http_response_header[0] ?? 'no answer';
        if (!str_contains($status, ' 204')) {
            throw new RuntimeException('The stop channel of ' . executableName() . " refused the request ($status): $named");
        }
        $deadline = microtime(true) + self::STOP_WAIT_SECONDS;
        while (!$this->free()) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(executableName() . ' did not stop within ' . self::STOP_WAIT_SECONDS . " seconds: $named");
            }
            usleep(200000);
        }
        fwrite(STDOUT, executableName() . " stopped.\n");
        return 0;
    }

    // Whether no server holds the lease. Taking it to ask drops it again.
    private function free(): bool
    {
        $handle = $this->lock();
        if ($handle === null) {
            return false;
        }
        fclose($handle);
        return true;
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

// The lease one server holds over its state directory, for as long as it serves. Two
// servers over one set of data would corrupt both, whatever addresses they bind, so the
// claim belongs to the data rather than to a port.
//
// The lock lives on the open file description, which survives the exec into the server,
// so the kernel holds it for that process and releases it when the process ends, a kill
// included. On Windows this process waits for the server it starts, so the handle lives
// exactly as long. The caller keeps the returned handle: closing it drops the lease.
// A null return means another start serves this data.
function takeLease(string $path)
{
    $handle = fopen($path, 'c');
    if ($handle === false) {
        throw new RuntimeException("Cannot open the serving lease: $path");
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    return $handle;
}

// Whether no server holds the lease. Taking it to ask drops it again.
function leaseFree(string $path): bool
{
    $lease = takeLease($path);
    if ($lease === null) {
        return false;
    }
    fclose($lease);
    return true;
}

// Runs this start again as a background server, through the launcher's detach word, and
// relays the server's log until it answers. The lease is released first, so the server
// takes it. The server gets the same arguments and the same working directory, so every
// path the reader wrote resolves as it did here. $stop is the command the relay prints
// for ending the server.
function detachServer($lease, string $log, string $stop, array $arguments): never
{
    fclose($lease);
    if (personPresent()) {
        putenv('DRUPACK_RUNTIME_PERSON=1');
    }
    $start = startDirectory();
    replaceProcess(getenv('DRUPACK_RUNTIME_LAUNCHER'),
        array_merge(['detach', $log, $stop, '--'], $arguments, ['--foreground']), $start, 'Cannot start the server in the background');
}

// What a start does once it holds the lease. A record from an earlier server names a port
// and a token nobody listens on, and `stop` reads a missing record as a start still
// preparing, so it goes. A double-click owns its console, which closing the window would
// end along with a background server, so it serves where the reader can stop it. Every
// other start detaches unless it was asked to serve in the foreground.
function detachStart($lease, bool $foreground, string $record, string $log, string $stop, array $arguments): void
{
    if (file_exists($record)) {
        unlink($record);
    }
    if (!$foreground && !consoleOwned()) {
        detachServer($lease, $log, $stop, $arguments);
    }
}

// An IPv6 address takes brackets inside a URL authority.
function urlHost(string $host): string
{
    return str_contains($host, ':') ? "[$host]" : $host;
}

// The address a probe on this computer reaches a server bound to $bind, which a wildcard
// reaches through its own family's loopback, since an IPv6-only listener never answers on
// 127.0.0.1.
function probeHost(string $bind): string
{
    $bind = trim($bind, '[]');
    return match ($bind) {
        '0.0.0.0' => '127.0.0.1',
        '::' => '::1',
        default => $bind,
    };
}

// One word of a command a reader pastes into their shell.
function shellWord(string $value): string
{
    // cmd and PowerShell read a backslash as itself, and PowerShell reads a quoted first
    // word as a string to print, so a Windows path stays bare when nothing else needs quotes.
    if (preg_match('~^[A-Za-z0-9_./:=@%+' . (windows() ? '\\\\' : '') . '-]+$~', $value) === 1) {
        return $value;
    }
    return windows() ? '"' . $value . '"' : "'" . str_replace("'", "'\\''", $value) . "'";
}

// The command that stops what this start runs, in the words the reader ran it with.
// $target is the quoted words `stop` needs to find the same server, empty when its
// default does.
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

function notRunning(): int
{
    fwrite(STDOUT, executableName() . " is not running.\n");
    return 0;
}

// How long `stop` waits for the lease to free. runtime/entrypoint.go forces the server's
// own exit at 10 seconds.
const STOP_WAIT_SECONDS = 15;

// Ends the server that holds $lease, through the stop channel its $record names, and waits
// for the lease to free. $what and $directory name the state for a message. Returns the
// exit code. A free lease means no server runs, whatever a stale record says.
function stopServer(string $lease, string $record, string $what, string $directory): int
{
    if (!is_file($lease) || leaseFree($lease)) {
        return notRunning();
    }
    if (!is_file($record)) {
        throw new RuntimeException("A " . executableName() . " start is still preparing this $what: $directory");
    }
    $channel = json_decode((string) file_get_contents($record), true, 512, JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer {$channel['token']}\r\nContent-Length: 0\r\n",
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    @file_get_contents("http://127.0.0.1:{$channel['port']}/stop", false, $context);
    $status = $http_response_header[0] ?? 'no answer';
    if (!str_contains($status, ' 204')) {
        throw new RuntimeException("The stop channel of " . executableName() . " refused the request ($status): $directory");
    }
    $deadline = microtime(true) + STOP_WAIT_SECONDS;
    while (!leaseFree($lease)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException(executableName() . ' did not stop within ' . STOP_WAIT_SECONDS . " seconds: $directory");
        }
        usleep(200000);
    }
    fwrite(STDOUT, executableName() . " stopped.\n");
    return 0;
}
