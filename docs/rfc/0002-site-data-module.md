# RFC 0002: one module owns Site data

Proposed on 2026-09-28. Not implemented.

## Goal

One PHP class answers what a Site data directory holds and what state it is in.
`launch.php` asks it instead of spelling file names and reading markers itself.

## Problem

`launch.php` knows eleven Site data names:

| Name | How `launch.php` reaches it |
|---|---|
| `site-installed`, `installation-progress`, `site-adopted`, `first-install` | a `…Path()` helper each |
| `serving.lock`, `listener` | a `…Path()` helper each |
| `settings.php` | spelled inline, 16 times |
| `site.sqlite` | spelled inline, 14 times |
| `app` | spelled inline |
| `files`, `logs`, `runtime`, `private`, `tmp`, `config` | spelled inline in the start body |

The state machine over those files has no owner either:

- `remainingSteps()` reads the marker, the progress file, `settings.php` and `site.sqlite`, and writes `first-install`.
- `initialize()` writes progress after each step, writes the marker, then removes progress, `site-adopted` and `first-install`.
- `runStep()` writes `site-adopted` and reads `first-install`.
- `recordedListener()`, `recordedFilesDirectory()` and `writeListener()` own the listener record's JSON.
- `recordedOptions()` requires `settings.php` to read the recorded connection.

A new state file means edits in three functions, and nothing checks that
`initialize()` removes every file `remainingSteps()` reads. The backlog entry
"An owner for the Site data layout" counts the same spread.

## Proposed interface

A final class in `application/support/`, beside the other `Drupack\Support`
classes:

```php
final class SiteData
{
    public function __construct(public readonly string $directory) {}

    // Layout: every path a caller needs, named once.
    public function settings(): string;
    public function database(): string;
    public function application(): string;
    public function files(): string;          // the default, before a --files-dir
    public function logs(): string;
    public function runtime(): string;
    public function lease(): string;
    public function prepare(): void;          // creates runtime, private, tmp, config, logs

    // Initialization state.
    public function steps(string $backend): array;
    public function stepDone(array $remaining): void;
    public function stepAdopted(): void;
    public function firstEver(): bool;
    public function adopted(): bool;
    public function complete(): bool;         // writes the marker, clears the rest, returns adopted

    // Records.
    public function listener(): ?array;
    public function recordListener(string $listen, string $host, ?string $filesDirectory): void;
    public function connection(): array;      // the database block of the recorded settings.php
}
```

A caller in the start body:

```php
$site = new SiteData($data);
$site->prepare();
$steps = $site->steps($options['database']);
foreach ($steps as $index => $step) {
    runStep($step, $site, $options, $binary);
    $site->stepDone(array_slice($steps, $index + 1));
}
$adopted = $site->complete();
```

## What it hides

- The eleven file names and which of them are directories.
- The progress file's JSON and its refusal text when unreadable.
- The four markers and the order `complete()` writes and removes them in.
- The listener record's JSON, including records written before `files-dir` existed.
- The `settings.php` read, with the two variables Drupal's loader expects.

Option merging stays in `launch.php`. `recordedListener()` keeps the rule that
an explicit option beats the record, and reads the record from `listener()`.

## Dependency strategy

Local-substitutable. The class touches the filesystem and nothing else. Its
tests use a temporary directory, as `launch_test.php` does today. `connection()`
needs the Composer class loader, which the test file already loads.

## Testing strategy

New tests go in `application/tests/site_data_test.php`, run by `qa.sh`. Each
one builds a directory on disk, calls the class, and checks a return value or
a file. The conformance suite keeps its own spellings, since it tests the
executable from outside.

| Existing case in `launch_test.php` | Replacement |
|---|---|
| a finished site has no remaining steps | same name, on `steps()` |
| recorded progress names the remaining steps | same name, on `steps()` |
| the finished marker beats recorded progress | same name, on `steps()` |
| unreadable progress refuses | same name, on `steps()` |
| settings without a marker adopt the site | same name, on `steps()` |
| a database without settings refuses | same name, on `steps()` |
| settings beat a database when neither marker exists | same name, on `steps()` |
| an empty site data directory seeds sqlite | same name, on `steps()` |
| an empty site data directory installs on a database server | same name, on `steps()` |
| a resumed start reads its connection from recorded settings | same name, on `connection()` |
| an unreadable listener record refuses | same name, on `listener()` |
| a listener record missing its host refuses | same name, on `listener()` |
| site data file names hang off the directory | deleted: the marker names become private, and the `steps()` and `complete()` cases read them |

New cases with no predecessor:

- `complete()` leaves no progress, adoption or first-install file behind.
- `complete()` reports an adopted site once, then false on a second call.
- `steps()` on a server backend records `first-install` only when the directory exists.

The listener merge cases stay in `launch_test.php`, on `recordedListener()`:
"an explicit option beats the listener record", "site data with no listener
record changes nothing", "a listener record fills an absent address and host",
"a listener record fills an absent files directory", "an explicit files
directory beats the listener record", "a record without a files directory
leaves the default to Site data". "A refusal names the
executable the launcher exported" and both `servedListener()` cases stay too.

Each replacement lands in the commit that deletes the case it replaces.

## Debug story

When a start repeats or skips a step six months from now, the engineer copies
the Site data directory and calls `steps()` on the copy. The answer, and the
marker files present, show which rule fired.

## Considered options

- Helpers for the five inline names, and no class. This is the backlog's lean.
  It fixes the spelling. It leaves the state transitions split over three
  functions, where a new marker still needs three coordinated edits.
- A class that also runs the steps. It would hide Drush, the seed copy and the
  database checks as well, eight concerns in one class. Steps stay in `launch.php`.

Recommendation: the class above. It hides four concerns behind one object.

## Consequences

- `launch.php` loses the six `…Path()` helpers, `remainingSteps()`,
  `writeProgress()`, `listenerRecord()`, `writeListener()` and `recordedOptions()`.
- The start body passes one `SiteData` where it passes `$data` today.
- The backlog entry "An owner for the Site data layout" closes.
