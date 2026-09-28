# RFC 0002: one module owns Site data

Proposed on 2026-09-28. Not implemented.

## Goal

One PHP class answers what a Site data directory holds and what state it is in,
and it runs the initialization loop that moves it between states. `launch.php`
asks it instead of spelling file names and reading markers itself.

## Problem

`launch.php` knows twelve Site data names:

| Name | How `launch.php` reaches it |
|---|---|
| `site-installed`, `installation-progress`, `site-adopted`, `first-install` | a `…Path()` helper each |
| `serving.lock`, `listener` | a `…Path()` helper each |
| `settings.php` | spelled inline, 16 times |
| `site.sqlite` | spelled inline, 14 times |
| `hash_salt`, `app` | spelled inline |
| `files`, `logs`, `runtime`, `private`, `tmp`, `config` | spelled inline in the start body |

The state machine over those files has no owner either:

- `remainingSteps()` reads the marker, the progress file, `settings.php` and `site.sqlite`, and writes `first-install`.
- `initialize()` writes progress after each step, writes the marker, then removes progress, `site-adopted` and `first-install`.
- `runStep()` writes `site-adopted` after the install step, and the modules step reads it.
- `recordedListener()`, `recordedFilesDirectory()` and `writeListener()` own the listener record's JSON.
- `recordedOptions()` requires `settings.php` to read the recorded connection.

A new state file means edits in three functions, and nothing checks that
`initialize()` removes every file `remainingSteps()` reads. The backlog entry
"An owner for the Site data layout" counts the same spread.

## Proposed interface

A final class in `application/support/`, beside the other `Drupack\Support`
classes. Its read side serves every start. Its write side serves only a first
start and a resume.

```php
final class SiteData
{
    public function __construct(public readonly string $directory) {}

    // Read side: paths, records and the recorded connection.
    public function settings(): string;
    public function database(): string;
    public function secret(): string;
    public function application(): string;
    public function files(): string;          // the default, before a --files-dir
    public function logs(): string;
    public function runtime(): string;
    public function lease(): string;
    public function prepare(): void;          // creates runtime, private, tmp, config and logs
    public function listener(): ?array;       // {listen, host, files-dir}, whole or a refusal
    public function recordListener(string $listen, string $host, ?string $filesDirectory): void;
    public function connection(): array;      // the database block of the recorded settings.php

    // Write side: the steps a start owes, and the loop that runs them.
    public function installed(): bool;       // an initialization finished here
    public function steps(string $backend): array;

    /**
     * Runs $run once per step, in order, and records the remaining steps after
     * each call returns. $run gets the step, whether this database was new to
     * Drupack when first seen, and whether an earlier step found a site already
     * installed there. It returns true when this step found one. After the last
     * step, writes the finished marker and clears every other state file.
     * Returns whether the start adopted an existing site.
     *
     * @param callable(string, bool, bool): bool $run
     */
    public function initialize(array $steps, callable $run): bool;
}
```

A caller in the start body:

```php
$site = new SiteData($data);
$site->prepare();
$steps = $site->steps($options['database']);
// ... the lease, the listener record, a second steps() read under the lease ...
$number = 0;
$adopted = $site->initialize($steps, function (string $step, bool $firstEver, bool $adopted) use (&$number, $steps, $site, $options, $binary): bool {
    fwrite(STDOUT, sprintf("[%d/%d] %s\n", ++$number, count($steps), STEP_REPORTS[$step]));
    return runStep($step, $site, $options, $binary, $firstEver, $adopted);
});
```

`runStep()` keeps Drush, the seed copy and the database checks. The install
step returns what `installSite()` returns today. The modules step reads
`$adopted` instead of the `site-adopted` file.

## What it hides

- The twelve file names and which of them are directories.
- The progress file's JSON and its refusal text when unreadable.
- The four state files, and the order `initialize()` writes and removes them in.
- The listener record's JSON, including records written before `files-dir`, and its refusal when a field is missing.
- The `settings.php` read, with the two variables Drupal's loader expects.

The module owns the loop, so no caller can run a step without recording
progress after it. Option precedence stays in `launch.php`: an explicit
`--listen`, `--host` or `--files-dir` beats the record `listener()` returns.

## Dependency strategy

Local-substitutable. The class touches the filesystem and nothing else. Its
tests use a temporary directory, as `launch_test.php` does today. `connection()`
needs the Composer class loader, which the test file already loads.

## Testing strategy

New tests go in `application/tests/site_data_test.php`, run by `qa.sh`. Each
one builds a directory on disk, calls the class, and checks a return value, a
file, or the calls a recording `$run` received. The conformance suite keeps its
own spellings, since it tests the executable from outside.

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
| an unreadable listener record refuses | same name, on `listener()` |
| a listener record missing its host refuses | same name, on `listener()` |
| a refusal names the executable the launcher exported | same name, on `listener()` |
| site data file names hang off the directory | deleted: the state file names become private, and the `steps()` and `initialize()` cases read them |

New cases with no predecessor:

- `initialize()` leaves no progress, adoption or first-install file behind.
- `initialize()` passes `$adopted` true to every step after one that returned true.
- a `$run` that throws on the second step leaves progress naming the second step onward, and `steps()` returns it.
- `initialize()` passes `$firstEver` true only when `steps()` recorded a new database.
- `connection()` returns the database block of a recorded `settings.php`.

These cases stay in `launch_test.php` on `recordedListener()` and
`recordedFilesDirectory()`, which now take the array `listener()` returns:

- site data with no listener record changes nothing
- a listener record fills an absent address and host
- a listener record fills an absent files directory
- an explicit files directory beats the listener record
- a record without a files directory leaves the default to Site data
- an explicit option beats the listener record
- both `servedListener()` cases
- a resumed start reads its connection from recorded settings, which checks `requireDatabaseOptions()`

Each replacement lands in the commit that deletes the case it replaces.

## Debug story

When a start repeats or skips a step six months from now, the engineer copies
the Site data directory and calls `steps()` on the copy. Then they run
`initialize()` with a `$run` that records each call. The files left on disk
and the recorded calls show which rule fired.

## Considered options

Four designs competed, each under one constraint:

- Minimal interface: `open()`, `steps()` and `initialize()` alone. `open()`
  returned every path and record as one array and also wrote the listener. The
  array keys carried no types, and one method both read and wrote.
- Maximum flexibility: a read-only interface with one implementation, and
  generic `mark()` and `record()` by name. An interface with one implementer is
  a layer with no second use, and generic names let any caller touch the state files.
- Common caller first: an installed-site object plus a separate `Installation`
  iterator with `done()` after each step. Nothing enforced one `done()` per step.
- State machine: a `Step` enum and a `Plan` whose `advance()` rejects an
  out-of-order step. Every caller converted between the enum and strings, and a
  loop the module owns makes that check unnecessary.

The helpers-only option from the backlog fixes the spelling. It leaves the state
transitions split over three functions.

Recommendation: the class above, taking its read side from the common-caller
design and its loop from the minimal one.

## Consequences

- `launch.php` loses the six `…Path()` helpers, `remainingSteps()`,
  `writeProgress()`, `initialize()`, `listenerRecord()` and `writeListener()`.
  `recordedOptions()` stays: it maps the recorded connection onto option names.
- `runStep()` returns a bool and takes `$firstEver` and `$adopted`.
- The start body passes one `SiteData` where it passes `$data` today.
- The backlog entry "An owner for the Site data layout" closes.
