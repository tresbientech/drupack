# Give the Site data layout one owner

Proposed on 2026-09-21. Not implemented.

## The question

Ten file names describe a Site data directory. `runtime/launch.php` spells them inline at 18 places. The conformance suite spells them again 33 times across seven modules. Which code owns that list?

## What ships today

The layout is `settings.php`, `site.sqlite`, `hash_salt`, `files`, `listener`, `startup.lock`, `site-installed`, `installation-progress`, `site-adopted` and `first-install`.

Four names have a helper each: `markerPath` (`runtime/launch.php:176`), `progressPath` (`:181`), `adoptedPath` (`:188`), `firstEverPath` (`:196`). The other six appear as interpolation. `"$data/settings.php"` sits at `:399`, `:665` and `:846`. `"$data/site.sqlite"` sits at `:344` and `:605`.

`remainingSteps()` (`:269`) reads five of those names and returns one of five step lists. `initialize()` (`:695`) writes the journal, the marker and two cleanups. Nothing exercises either one below a built executable.

`initialization_cases.py` holds 11 cases in 6 classes, 666 lines. Its `reset_installation_state()` (`:118`) hardcodes the whole layout so a case can undo it.

## Why this decision is worth making

`remainingSteps()` carries the hardest judgement in the product. It separates a finished site from an interrupted one. It separates a foreign database from one Drupack is still installing. It decides whether a start may resume or must refuse.

Five commits changed it between 2026-09-18 and 2026-09-19:

- `record initialization progress and lock starts`
- `harden initialization against races and reruns`
- `persist whether a database was ever seen fresh`
- `fail loudly when the adopted marker is lost`
- `refuse adoption of a site with the seed administrator`

Each one had a single way to be checked: build the executable, run the suite. The input to the decision is a directory state that `mkdir` and `touch` can build in a millisecond.

## Decision

Add `runtime/site-data.php` with one final class. `launch.php` requires it.

```php
final class SiteData
{
    // Resolves the path, creates the directory and its subdirectories.
    public static function open(string $path): self;

    public function path(): string;

    // The steps a start still owes this directory, for the backend it asked for.
    // Throws when the directory holds a database Drupack must not write over.
    public function remainingSteps(string $backend): array;

    // Runs each step, journals the remainder after it, marks the directory finished.
    public function initialize(array $steps, callable $runStep): void;

    // The address the last start served on, or null before any start recorded one.
    public function listener(): ?array;
    public function recordListener(string $listen, string $host): void;

    // One start at a time prepares this directory. Throws when another holds it.
    public function lockStartup(): mixed;

    // The only paths that leave the class.
    public function settingsPath(): string;
    public function databasePath(): string;
    public function filesPath(): string;
    public function secretPath(): string;
}
```

### How a caller uses it

```php
$site = SiteData::open($options['data-dir']);
$steps = $site->remainingSteps($options['database']);
$options = administratorCredentials($options, $steps);

$lock = $steps === [] ? null : $site->lockStartup();
$site->initialize($site->remainingSteps($options['database']), function (string $step) use ($site, $options, $binary): void {
    runStep($step, $site, $options, $binary);
});
```

### What the class hides

- The ten file names, and the rule that `open()` creates seven subdirectories under `umask(0077)`.
- The five-branch step decision, including the `first-install` write that a resumed start reads.
- The progress journal, the completion marker, and the two markers `initialize()` removes on success.
- The `flock` call and the resolved-path rule that gives equivalent paths one lock.

### What stays outside

Step bodies. `copySeed`, `writeSettings`, `installSite`, `adoptSite` and `removeRecipeModules` keep their current place and receive a `SiteData` instead of a `$data` string. Folding them in would put nine concerns behind one interface.

## Dependency strategy

Local-substitutable. A temporary directory answers every question `remainingSteps()`, `listener()` and `lockStartup()` ask. `initialize()` takes its step body as a callable, so a test passes a recorder and asserts the order and the journal without Drush.

`php8.5` already runs on a developer machine. The new tests need no container and no built executable.

## Tests

Nothing is deleted before its replacement passes. Most conformance cases earn their place and stay; the table names the four that shrink.

| Case today | After |
|---|---|
| `FirstStartAndListener.test_first_start_creates_the_site_and_serves_through_drush` | Stays. It proves a served site, not the decision. |
| `RecordedBackendAndAdoption.test_a_completed_site_keeps_its_recorded_backend` | Shrinks. The `site-installed` half becomes a `remainingSteps` test returning `[]`. The recorded-backend read stays e2e. |
| `RecordedBackendAndAdoption.test_adoption_and_refusals` | Moves. Four `remainingSteps` tests: settings without marker returns `['adopt']`; `site.sqlite` without settings throws; an unreadable journal throws; a fresh directory returns the seed list. The Drupal-side adoption refusal stays e2e. |
| `RecordedBackendAndAdoption.test_a_blocked_administrator_does_not_stop_a_later_start` | Stays. It asserts Drupal account state. |
| `InterruptedStartAndRace.test_interrupted_start_then_concurrent_race` | Stays. It proves the lock under real concurrency and a real SIGKILL. |
| `EquivalentPathLock.test_an_equivalent_path_takes_the_same_lock` | Shrinks. Two equivalent paths through `open()` return one `path()`, as a unit test. The contention half stays e2e. |
| `SiteName.test_a_chosen_site_name_survives_a_later_start` | Stays. |
| `SiteName.test_the_site_name_also_comes_from_the_environment` | Stays. |
| `PostgresqlLifecycle.test_first_start_then_interrupted_recovery_without_reinstalling` | Shrinks. The journal replay becomes a unit test. The PostgreSQL half stays. |
| `PostgresqlLifecycle.test_first_start_never_reinstalls_a_database_that_already_holds_a_site` | Stays. It needs a real database. |
| `PostgresqlLifecycle.test_first_start_refuses_a_database_that_holds_other_tables` | Stays. It needs a real database. |

New tests with no counterpart today, one per uncovered branch:

- A directory holding `installation-progress` with `["modules"]` returns `["modules"]`.
- A non-SQLite first start writes `first-install` and returns `['settings', 'install', 'modules']`.
- `initialize()` on an empty step list writes no journal and no marker.
- `initialize()` removes `site-adopted` and `first-install` after the last step.
- A step that throws leaves the journal naming the steps still owed.
- `listener()` on a directory with no record returns null.
- `recordListener()` then `listener()` round-trips the listen address and the host.

`reset_installation_state()` (`initialization_cases.py:118`) keeps its hardcoded list. The suite tests a shipped product from outside, so it may not import the product's own constant.

## Consequences

- One file names the layout. A new marker costs one method, not a search across two languages.
- The step decision gains tests that run in under a second on a developer machine.
- `runtime/` gains a second PHP file. `packaging/dev-entry.sh:20` copies four names by name and must copy five.
- `launch.php` loses about 90 lines and gains one `require`.
- The class gives `runStep` a `SiteData`, so every step body changes signature. That is one commit touching six functions.

## Considered options

- Free functions taking a `$data` string, with the names in constants. Cheaper, and it leaves `open()`'s directory creation and the lock with no home. The interpolation sites stay legal, so drift returns.
- Fold the step bodies in as methods. One interface for the whole install. It hides nine concerns, which is the god-module shape this repo has avoided so far.
- Move the decision into Go, beside the launcher's tested cache code. The decision needs Drush and PDO, which live in PHP.
- Leave it and add conformance cases. Each new case costs a Docker build and about 35 seconds of CI, and the branch count keeps growing.

## Order of work

1. `runtime/site-data.php` with the class, and its unit tests. No caller changes.
2. `launch.php` calls the class for the listener record and the lock. Suite stays green.
3. `launch.php` calls `remainingSteps()` and `initialize()`. Delete the replaced functions in the same commit.
4. Step bodies take a `SiteData`. Delete the last interpolated path.
5. `packaging/dev-entry.sh` copies the new file.

Steps 1 and 2 stand alone. A reviewer sees the class before any behaviour moves.

## Out of scope

- The Site data layout itself. This RFC moves the names, not the files.
- `recordedOptions()` (`:381`), which reads a settings file Drupal owns.
- The address parsing and the port probe, in [address ownership](address-ownership.md).
