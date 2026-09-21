# Declare the command line once

Proposed on 2026-09-21. Not implemented.

## The question

Drupack accepts 13 options. Three places in the product describe them, and one has already drifted. Which one is the contract?

## What ships today

`options()` (`runtime/launch.php:101`) holds the accepted set as array keys, with each default beside it. It accepts 13 names.

The `--help` line at `runtime/launch.php:127` prints 6 of them:

```
Usage: drupack php-cli launch.php [--data-dir PATH] [--listen IP:PORT] [--host HOST] [--database sqlite|mysql|pgsql] [--site-name NAME] [--no-browser]
```

Missing from that line: `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password`, `--admin-user`, `--admin-password`.

`packaging/entrypoint.go:21-45` holds a second usage text, 25 lines, naming all 13 plus `--help` and `--version`. It intercepts `--help` at `:139`, so the PHP line above never reaches a reader through the shipped executable.

`README.md:73-76` describes four options in prose, and the database and administrator options elsewhere.

Five conformance cases in `argument_cases.py` cover the refusals. Each one starts the built executable.

## Why this decision is worth making

The drift is already here. A reader who runs the PHP entry point directly, which `packaging/dev-entry.sh:32` does, gets a usage line that hides every database flag.

Nothing checks the three lists against each other. Adding an option means editing two files and remembering a third. Removing one means the same.

The parser is a pure function. It takes an argv array and returns an options record or throws. It has six branches worth testing: the `--name=value` form, the `--name value` form, a value that starts with `--`, an unknown name, a missing value, and the Drush passthrough split. None of them has a test that runs without a built executable.

## Decision

Add `runtime/options.php`. It holds one table, one parser and one usage builder.

```php
// One row per option. 'value' names the placeholder a usage line prints, or null
// for a flag. 'environment' names the variable that sets the default, 'default'
// the value with no option and no variable. A 'deferred' row is not defaulted by
// the parser: the recorded listener is read first, then the main block applies it.
const OPTIONS = [
    'data-dir' => ['value' => 'PATH', 'environment' => 'DRUPACK_DATA_DIR',
        'default' => './data', 'usage' => 'Site data directory'],
    'listen' => ['value' => 'IP:PORT', 'environment' => null, 'deferred' => true,
        'default' => '127.0.0.1:7225', 'usage' => 'Listener address'],
    // ... eleven more rows
];

// Splits argv into the options this product accepts and, under Drush, the
// command that follows them. Throws InvalidArgumentException on user error.
function parseArguments(array $arguments, bool $drush): array;

// The usage text, built from OPTIONS. Never edited by hand.
function usageText(): string;
```

`packaging/entrypoint.go` loses its `usage` constant and its `--help` branch. `--help` and `-h` reach `launch.php` through the fall-through at `entrypoint.go:147`, which already forwards any argument starting with a dash. `--version` stays in Go, because `version` arrives from `-ldflags`.

### How a caller uses it

```php
[$options, $command] = parseArguments(array_slice($argv, 1), $drush);
// ... the recorded listener merges here, for a dr command
foreach (OPTIONS as $name => $option) {
    if (($option['deferred'] ?? false) && $options[$name] === null) {
        $options[$name] = $option['default'];
    }
}
```

### What the table hides

- The accepted set, which today exists as array keys read by `array_key_exists`.
- The environment variable behind each default, spelled 11 times at `:104-116`.
- The two default values that the main block applies at `:744`, moved into the rows that own them.
- The usage text, which stops being a string anyone edits.

### What stays outside

`requireDatabaseOptions()` (`:309`) needs the step list to know whether a first start is running. It stays a free function. The `--listen` and `--host` validation moves to [the address RFC](address-ownership.md), which owns those two values.

## Dependency strategy

In-process. `parseArguments()` reads its arguments and `getenv`. A test sets the environment with `putenv` and calls it. `usageText()` touches nothing.

## Tests

| Case today | After |
|---|---|
| `ArgumentCases.test_unsupported_database_backend_refuses` | Moves. `parseArguments(['--database', 'oracle'], false)` throws, as a unit test. |
| `ArgumentCases.test_quoted_data_dir_refuses` | Moves. The quote check belongs beside the parser, since the Caddy log path is built from the value. |
| `ArgumentCases.test_mysql_start_without_connection_details_refuses` | Shrinks. `requireDatabaseOptions()` gains a direct test. One e2e case keeps the exit code and the message. |
| `ArgumentCases.test_pgsql_start_without_connection_details_refuses` | Deleted, with justification. It repeats the MySQL case with one string changed. The unit test above covers both backends in two lines. |
| `ArgumentCases.test_dr_without_a_site_refuses` | Stays. It asserts a Site data check, not an argument. |

New tests with no counterpart today:

- `--data-dir=/x` and `--data-dir /x` produce the same record.
- `--data-dir --listen` throws, because a value may not start with a dash.
- `--data-dir` as the last argument throws.
- `--unknown` throws and names the argument.
- `--no-browser` sets a flag and consumes no value.
- Under Drush, the first argument outside `OPTIONS` starts the command and every later argument joins it.
- Under Drush, an option after the command stays in the command.
- `DRUPACK_SITE_NAME` sets the default, and `--site-name` beats it.
- `usageText()` names every key in `OPTIONS`, and `OPTIONS` holds every name the text prints.

That last test is the one that would have caught the current drift.

## Consequences

- Adding an option means adding one row. The usage text follows.
- `drupack --help` runs PHP, where today Go answers it. The cost is one interpreter start, which every real start already pays. Nothing is created on disk: `options()` exits inside its own loop, before the main block creates a directory.
- `drupack -h` starts working through PHP, which today only Go handles.
- `packaging/entrypoint.go` loses 25 lines of text and 4 lines of branch.
- The five `argument_cases.py` cases become one, and the parser gains nine tests that run in milliseconds.
- `README.md` stays prose. It explains options to a reader, and a generated list would be worse for that.

## Considered options

- Generate the Go usage text from the PHP table at build time. It keeps `--help` instant and adds a code generator plus a check that the generated file is current. That is a build step for 25 lines of text.
- Keep both texts and add a conformance case comparing them. No new module, and the case has to parse two formats to compare them. The parser branches stay untested.
- Move the parser to Go, beside the flags Go already owns. Go then needs the environment defaults, and `dr` passthrough splitting, which exist to serve Drush.
- Leave it and fix the PHP help line. One commit, and the third copy is still a copy.

## Order of work

1. `runtime/options.php` with the table, the parser, the builder and their tests. No caller changes.
2. `launch.php` calls `parseArguments()` and `usageText()`. Delete `options()`.
3. `packaging/entrypoint.go` loses `usage` and its `--help` branch. The conformance case for `drupack --help` moves to asserting the PHP text.
4. Trim `argument_cases.py` to the two cases the table above keeps.
5. `packaging/dev-entry.sh:20` copies the new file.

## Out of scope

- The option set itself. This RFC moves the declaration, not the options.
- `--listen` and `--host` validation, in [address-ownership.md](address-ownership.md).
- The `dr` global-option rule, which `CONTEXT.md` documents and this RFC preserves.
