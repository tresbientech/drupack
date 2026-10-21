<?php

declare(strict_types=1);

namespace Drupack\Support;

use Composer\Autoload\ClassLoader;
use RuntimeException;

final class SiteData
{
    private const FINISHED = 'site-installed';
    private const PROGRESS = 'installation-progress';
    // The install step found a site already in the database, so the modules step changes nothing.
    private const ADOPTED = 'site-adopted';
    private const INSTALL_STARTED = 'install-started';
    // The scheme of a site no record names, from a release that served HTTP alone.
    public const UNRECORDED_SCHEME = 'http';
    // The host `drush` addresses on Site data no start has served yet.
    public const UNRECORDED_HOST = 'localhost';
    private const LISTENER = 'listener';

    public function __construct(public readonly string $directory)
    {
    }

    public function settings(): string
    {
        return "$this->directory/settings.php";
    }

    public function database(): string
    {
        return "$this->directory/site.sqlite";
    }

    public function secret(): string
    {
        return "$this->directory/hash_salt";
    }

    public function mercurePublisherSecret(): string
    {
        return "$this->directory/mercure_publisher_secret";
    }

    public function mercureSubscriberSecret(): string
    {
        return "$this->directory/mercure_subscriber_secret";
    }

    public function application(): string
    {
        return "$this->directory/app";
    }

    public function files(): string
    {
        return "$this->directory/files";
    }

    public function logs(): string
    {
        return "$this->directory/logs";
    }

    public function runtime(): string
    {
        return "$this->directory/runtime";
    }

    public function prepare(): void
    {
        foreach (['runtime', 'private', 'tmp', 'config', 'logs'] as $name) {
            \directory("$this->directory/$name");
        }
    }

    public function listener(): ?array
    {
        $path = $this->path(self::LISTENER);
        if (!file_exists($path)) {
            return null;
        }
        $record = json_decode($this->read($path), true);
        if (!is_array($record)) {
            throw new RuntimeException("Cannot read the recorded listener: $path. Remove that file, then start "
                . \executableName() . ' again to record it.');
        }
        return [
            'listen' => $record['listen'] ?? throw new RuntimeException('Recorded listener has no listen address'),
            'host' => $record['host'] ?? throw new RuntimeException('Recorded listener has no host'),
            'files-dir' => $record['files-dir'] ?? null,
            // Site data a release up to 1.0.0-beta8 recorded names no scheme. Those served HTTP.
            'scheme' => $record['scheme'] ?? self::UNRECORDED_SCHEME,
        ];
    }

    public function recordListener(string $listen, string $host, ?string $filesDirectory, string $scheme): void
    {
        $this->write(self::LISTENER, json_encode(['listen' => $listen, 'host' => $host, 'files-dir' => $filesDirectory, 'scheme' => $scheme]),
            'Cannot record the listener');
    }

    public function connection(): array
    {
        // settings.php expects the two variables Settings::initialize() gives it. This read
        // wants $databases alone, so the loader it registers on goes unused.
        $app_root = __DIR__;
        $class_loader = new ClassLoader();
        $databases = [];
        require $this->settings();
        return $databases['default']['default'];
    }

    public function installed(): bool
    {
        return file_exists($this->path(self::FINISHED));
    }

    /**
     * The steps a start owes. Progress lives beside the recorded settings, so a start tells a
     * finished site from an interrupted one and repeats no step that already wrote to a database.
     *
     * @return list<Step>
     */
    public function steps(string $backend): array
    {
        if ($this->installed()) {
            return [];
        }
        $progress = $this->path(self::PROGRESS);
        if (file_exists($progress)) {
            // Another release may have written the file, with names this one does not know.
            $names = json_decode($this->read($progress), true);
            $steps = is_array($names) ? array_map(fn(mixed $name) => is_string($name) ? Step::tryFrom($name) : null, $names) : [null];
            if (in_array(null, $steps, true)) {
                throw new RuntimeException("Cannot read the recorded initialization progress: $progress. Remove that file, then start "
                    . \executableName() . ' again to check the site.');
            }
            return array_values($steps);
        }
        if (file_exists($this->settings())) {
            return [Step::Adopt];
        }
        // The database is the user's only copy, so a start without recorded settings never seeds over one.
        if (file_exists($this->database())) {
            throw new RuntimeException("This Site data holds a database without settings: $this->directory. Restore its settings.php, or start "
                . \executableName() . ' with an empty Site data directory.');
        }
        if ($backend === 'sqlite') {
            return [Step::Seed, Step::Settings, Step::Administrator];
        }
        // A record with no progress beside it belongs to an initialization nobody resumes,
        // so it says nothing about what the database holds now.
        if ($this->installStarted()) {
            unlink($this->path(self::INSTALL_STARTED));
        }
        return [Step::Settings, Step::Install, Step::Modules];
    }

    /**
     * Runs $run once per step, in order, and records the remaining steps after each call
     * returns, so a crash resumes at the step that failed. $run gets the step and whether
     * an earlier step found a site already installed there. It returns true when this
     * step found one. After the last step, writes the finished marker and removes every
     * other state file. Returns whether the start adopted an existing site.
     *
     * @param list<Step> $steps
     * @param callable(Step, bool): bool $run
     */
    public function initialize(array $steps, callable $run): bool
    {
        if ($steps === []) {
            return false;
        }
        $this->recordProgress($steps);
        foreach ($steps as $index => $step) {
            if ($run($step, file_exists($this->path(self::ADOPTED)))) {
                $this->write(self::ADOPTED, '', 'Cannot record that this database already held a site');
            }
            $this->recordProgress(array_slice($steps, $index + 1));
        }
        $this->write(self::FINISHED, '', 'Cannot record the finished installation');
        unlink($this->path(self::PROGRESS));
        $adopted = file_exists($this->path(self::ADOPTED));
        if ($adopted) {
            unlink($this->path(self::ADOPTED));
        }
        if ($this->installStarted()) {
            unlink($this->path(self::INSTALL_STARTED));
        }
        return $adopted;
    }

    public function startInstall(): void
    {
        $this->write(self::INSTALL_STARTED, '', 'Cannot record that the installation began');
    }

    public function installStarted(): bool
    {
        return file_exists($this->path(self::INSTALL_STARTED));
    }

    /** @param list<Step> $steps */
    private function recordProgress(array $steps): void
    {
        $this->write(self::PROGRESS, json_encode(array_map(fn(Step $step) => $step->value, $steps)),
            'Cannot record the initialization progress');
    }

    private function write(string $name, string $contents, string $failure): void
    {
        if (file_put_contents($this->path($name), $contents, LOCK_EX) === false) {
            throw new RuntimeException($failure);
        }
    }

    // A start reads these records before it holds the lease, while another start may be
    // writing one. write() truncates under an exclusive lock, so the shared lock waits
    // for the whole record.
    private function read(string $path): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false || !flock($handle, LOCK_SH)) {
            throw new RuntimeException("Cannot read $path");
        }
        $contents = stream_get_contents($handle);
        fclose($handle);
        return (string) $contents;
    }

    private function path(string $name): string
    {
        return "$this->directory/$name";
    }
}
