<?php

declare(strict_types=1);

namespace Drupack\Support;

use Composer\Autoload\ClassLoader;
use RuntimeException;

// One Site data directory: the paths a start uses, the records it keeps, and the
// initialization state a first start leaves on disk until its last step.
final class SiteData
{
    // Written after the last step.
    private const FINISHED = 'site-installed';
    // The steps still owed, as JSON, while an initialization runs.
    private const PROGRESS = 'installation-progress';
    // The install step found a site already in the database, so the modules step changes nothing.
    private const ADOPTED = 'site-adopted';
    // Recorded the moment a database is first seen fresh, so a crash between that
    // moment and the install step's own bootstrap check still tells a resume the
    // difference between a foreign database and one Drupack is still installing.
    private const FIRST_EVER = 'first-install';
    // Every start records where it serves, so a later `drush` addresses the site on the
    // port it actually uses, and keeps the files directory the last start named.
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

    // The site's own application, laid here when its contract names writable directories.
    public function application(): string
    {
        return "$this->directory/app";
    }

    // The files directory when no start named another.
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

    public function lease(): string
    {
        return "$this->directory/serving.lock";
    }

    public function prepare(): void
    {
        foreach (['runtime', 'private', 'tmp', 'config', 'logs'] as $name) {
            \directory("$this->directory/$name");
        }
    }

    // The record the last start wrote, or null when none has. A record written before
    // --files-dir existed names no files directory.
    public function listener(): ?array
    {
        $path = $this->path(self::LISTENER);
        if (!file_exists($path)) {
            return null;
        }
        $record = json_decode((string) file_get_contents($path), true);
        if (!is_array($record)) {
            throw new RuntimeException("Cannot read the recorded listener: $path. Remove that file, then start "
                . \executableName() . ' again to record it.');
        }
        return [
            'listen' => $record['listen'] ?? throw new RuntimeException('Recorded listener has no listen address'),
            'host' => $record['host'] ?? throw new RuntimeException('Recorded listener has no host'),
            'files-dir' => $record['files-dir'] ?? null,
        ];
    }

    public function recordListener(string $listen, string $host, ?string $filesDirectory): void
    {
        $this->write(self::LISTENER, json_encode(['listen' => $listen, 'host' => $host, 'files-dir' => $filesDirectory]),
            'Cannot record the listener');
    }

    // The database block of the recorded settings.
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

    // Whether an initialization finished here.
    public function installed(): bool
    {
        return file_exists($this->path(self::FINISHED));
    }

    // The steps a start owes, from what the directory holds. Progress lives beside the
    // recorded settings, so a start tells a finished site from an interrupted one and
    // repeats no step that already wrote to a database.
    public function steps(string $backend): array
    {
        if ($this->installed()) {
            return [];
        }
        $progress = $this->path(self::PROGRESS);
        if (file_exists($progress)) {
            $steps = json_decode((string) file_get_contents($progress), true);
            if (!is_array($steps)) {
                throw new RuntimeException("Cannot read the recorded initialization progress: $progress. Remove that file, then start "
                    . \executableName() . ' again to check the site.');
            }
            return $steps;
        }
        if (file_exists($this->settings())) {
            return ['adopt'];
        }
        // The database is the user's only copy, so a start without recorded settings never seeds over one.
        if (file_exists($this->database())) {
            throw new RuntimeException("This Site data holds a database without settings: $this->directory. Restore its settings.php, or start "
                . \executableName() . ' with an empty Site data directory.');
        }
        if ($backend === 'sqlite') {
            return ['seed', 'settings', 'administrator'];
        }
        // The directory may not exist yet on the check that runs before it is created;
        // the authoritative check that runs under the serving lease always finds it.
        if (is_dir($this->directory)) {
            $this->write(self::FIRST_EVER, '', 'Cannot record that this database is new to ' . \executableName());
        }
        return ['settings', 'install', 'modules'];
    }

    /**
     * Runs $run once per step, in order, and records the remaining steps after each call
     * returns, so a crash resumes at the step that failed. $run gets the step, whether
     * this database was new to Drupack when first seen, and whether an earlier step found
     * a site already installed there. It returns true when this step found one. After
     * the last step, writes the finished marker and removes every other state file.
     * Returns whether the start adopted an existing site.
     *
     * @param callable(string, bool, bool): bool $run
     */
    public function initialize(array $steps, callable $run): bool
    {
        if ($steps === []) {
            return false;
        }
        $this->recordProgress($steps);
        $firstEver = file_exists($this->path(self::FIRST_EVER));
        foreach ($steps as $index => $step) {
            if ($run($step, $firstEver, file_exists($this->path(self::ADOPTED)))) {
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
        if ($firstEver) {
            unlink($this->path(self::FIRST_EVER));
        }
        return $adopted;
    }

    private function recordProgress(array $steps): void
    {
        $this->write(self::PROGRESS, json_encode($steps), 'Cannot record the initialization progress');
    }

    private function write(string $name, string $contents, string $failure): void
    {
        if (file_put_contents($this->path($name), $contents, LOCK_EX) === false) {
            throw new RuntimeException($failure);
        }
    }

    private function path(string $name): string
    {
        return "$this->directory/$name";
    }
}
