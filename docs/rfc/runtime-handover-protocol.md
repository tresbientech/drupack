# Declare the variables Drupack passes between its own processes

Proposed on 2026-09-21. Not implemented.

## The question

A start is four processes. The launcher execs the runtime. The runtime runs `launch.php`. That script execs the runtime again as a server. Caddy reads its configuration from the environment it inherits. Thirteen `DRUPACK_RUNTIME_*` variables carry the state across those hops, written and read in five languages. Where is that contract written down?

## What ships today

Nowhere. The variables are:

| Variable | Written by | Read by |
|---|---|---|
| `DRUPACK_RUNTIME_BINARY` | `entrypoint.go:128`, `dev-entry.sh:26` | `launch.php:721` |
| `DRUPACK_RUNTIME_DRUSH` | `entrypoint.go:133`, `dev-entry.sh:32` | `launch.php:718` |
| `DRUPACK_RUNTIME_CONSOLE_OWNED` | `launch_windows.go:25` | `launch.php:229` |
| `DRUPACK_RUNTIME_DATA_DIR` | `launch.php:772`, `dev-entry.sh:35`, `Dockerfile` | `settings.php:3`, `launch.php:55` |
| `DRUPACK_RUNTIME_HOST` | `launch.php:776`, `dev-entry.sh:38`, `Dockerfile` | `settings.php:15` |
| `DRUPACK_RUNTIME_BIND` | `launch.php:773`, `dev-entry.sh:36` | `Caddyfile:17` |
| `DRUPACK_RUNTIME_PORT` | `launch.php:774`, `dev-entry.sh:37` | `Caddyfile:16` |
| `DRUPACK_RUNTIME_ID` | `launch.php:775` | `Caddyfile:26` |
| `DRUPACK_RUNTIME_LOG_PATH` | `launch.php:783`, `dev-entry.sh:39` | `Caddyfile:8` |
| `DRUPACK_RUNTIME_URL` | `launch.php:169` | `entrypoint.go:114` |
| `DRUPACK_RUNTIME_OPEN` | `launch.php:171`, `:521` | `entrypoint.go:114`, `:121` |
| `DRUPACK_RUNTIME_BROWSER` | `launch.php:173` | `entrypoint.go:115` |
| `DRUPACK_RUNTIME_RESTARTED` | `launch.php:811` | `launch.php:808` |

Two argv verbs travel the same way. `launch.php:889` execs `php-server`, and `launch.php:522` execs `browser-open`. `entrypoint.go:110` and `:120` answer them.

`packaging/dev-entry.sh:34-39` sets six of the variables by hand, because the dev loop skips the Go entry point that would otherwise set them.

## Why this decision is worth making

One of the thirteen carries a working credential. `DRUPACK_RUNTIME_OPEN` holds a one-time login link for uid 1. `launch.php:162-166` records the rule. The link enters the serving process's environment only when a browser will spend it. Any PHP inside that process can read `getenv`, `$_ENV` or `phpinfo`.

`browser_cases.py:130-138` proves the rule by opening `/proc/<pid>/environ` and asserting the name is absent. That test names a variable no declaration mentions. A rename on the PHP side leaves the test asserting the absence of a string nothing sets, and it passes.

The same holds for the other twelve. A rename in `launch.php` leaves a stale reader in `Caddyfile`. That placeholder resolves empty, so Caddy starts on port 0 or logs to an empty path. Nothing fails until a start runs.

## Decision

Add `runtime/handover.php`. It declares the protocol and exports it.

```php
// One row per variable this product passes between its own processes. 'writers'
// and 'readers' name the files, so a conformance case can check both spellings.
// 'credential' marks a value that must reach a process only when that process
// will spend it.
const HANDOVER = [
    'DRUPACK_RUNTIME_DATA_DIR' => [
        'writers' => ['runtime/launch.php', 'packaging/dev-entry.sh', 'Dockerfile'],
        'readers' => ['runtime/settings.php', 'runtime/launch.php'],
        'credential' => false,
    ],
    'DRUPACK_RUNTIME_OPEN' => [
        'writers' => ['runtime/launch.php'],
        'readers' => ['packaging/entrypoint.go'],
        'credential' => true,
    ],
    // ... eleven more rows
];

// The commands launch.php hands to the Go entry point.
const VERBS = ['browser-open', 'php-server'];

// Exports each value under its declared name. Throws on a name outside HANDOVER.
function exportHandover(array $values): void;
```

### How a caller uses it

```php
exportHandover([
    'DRUPACK_RUNTIME_DATA_DIR' => $data,
    'DRUPACK_RUNTIME_BIND' => $listener->bind(),
    'DRUPACK_RUNTIME_PORT' => (string) $listener->port(),
    'DRUPACK_RUNTIME_ID' => siteToken($data),
    'DRUPACK_RUNTIME_HOST' => $listener->host(),
    'DRUPACK_RUNTIME_LOG_PATH' => $logPath,
]);
```

### What this hides

Less than the other four RFCs in this set. The declaration owns the names, the writer and reader lists, and the credential flag. `exportHandover()` hides one `putenv` loop and the check that rejects an undeclared name.

State this plainly. PHP writes; Go, Caddy and a shell script read. No single module spans that boundary. The deepening here delivers a declaration and a checker, which is shallower than the other four. It is the weakest of the five, and the only one that touches the credential rule.

## Dependency strategy

Ports and adapters, across a language boundary. The port is the declaration. The adapters are the five files that write or read a name.

The PHP side gets a direct test: `exportHandover()` sets what it is given and throws on a name outside the table.

The cross-language side gets a conformance case, because only a checker can reach four other languages. The case reads `packaging/entrypoint.go`, `runtime/Caddyfile`, `runtime/settings.php`, `packaging/dev-entry.sh` and `Dockerfile`, collects every `DRUPACK_RUNTIME_*` occurrence, and asserts two directions:

- Every name found appears in `HANDOVER`.
- Every `HANDOVER` row's declared writers and readers match the files the name was found in.

Both directions matter. The first catches a name nobody declared. The second catches a declared name whose reader was deleted.

## Tests

| Case today | After |
|---|---|
| `BrowserOpenCases.test_a_failed_mint_opens_no_browser_and_leaks_no_link` | Stays. It reads `/proc/<pid>/environ`, which is the only way to prove the credential never reached the server. It reads the name from `HANDOVER` instead of a literal. |
| `BrowserOpenCases.test_no_browser_start_opens_nothing` | Stays. |
| `BrowserOpenCases.test_later_start_opens_browser_on_dashboard` | Stays. |
| `BrowserOpenCases.test_interactive_adoption_start_opens_browser_on_dashboard` | Stays. |
| `TakenPortCases.test_a_second_start_with_no_terminal_stops` | Stays. It sets `DRUPACK_RUNTIME_CONSOLE_OWNED`, and reads the name from `HANDOVER`. |

Nothing is deleted. Every case here asserts a running process, which a declaration cannot replace.

New tests:

- `exportHandover()` sets each name it is given.
- `exportHandover()` throws on a name outside `HANDOVER`.
- Exactly one row carries `credential => true`, and it is `DRUPACK_RUNTIME_OPEN`.
- The cross-file case above, in both directions.

## Consequences

- A rename fails a test instead of a start.
- `packaging/dev-entry.sh` stops being a silent fourth writer. The case names it as a declared writer, so a variable added to `launch.php` and forgotten there fails.
- The credential rule gains a machine-readable form. Today it exists as a comment at `launch.php:162-166`.
- `runtime/` gains a fourth PHP file, and `packaging/dev-entry.sh:20` copies seven names.
- The conformance case greps source files, which the suite does not do today. It runs on every platform and needs no product binary.
- A future variable costs one row and the edit it was always going to need.

## Considered options

- Generate a Go constants file and a Caddyfile snippet from the PHP table. The two sides then cannot drift at all. It adds a code generator and a check that the generated files are current, for 13 names.
- A JSON file both languages read at run time. Go can read it, and Caddy cannot: its placeholders resolve from the environment.
- Keep the comment at `launch.php:162` as the contract and add nothing. Free, and the rename case stays silent.
- Pass the state as argv instead of the environment. Every local account can list a command line, and the login link is a credential. `launch.php:518-520` records that reasoning.

## Order of work

1. `runtime/handover.php` with the table and `exportHandover()`, plus their unit tests. No caller changes.
2. `launch.php` calls `exportHandover()`. Delete the `putenv` calls at `:772-776` and `:783`.
3. The cross-file conformance case.
4. `browser_cases.py` and `handover_cases.py` read their names from the table.
5. `packaging/dev-entry.sh` copies the new file.

Step 3 is the one that pays. It can land before step 2 if the table lands first.

## Out of scope

- The public `DRUPACK_*` options, in [one-cli-contract.md](one-cli-contract.md).
- `DRUPACK_CACHE_DIR`, which the Go launcher owns and tests.
- The identity route constants, in [address-ownership.md](address-ownership.md). That RFC rejected a fourteenth variable partly to avoid growing this table.
- The `TMPDIR`, `TEMP`, `XDG_*` exports at `launch.php:785-790`, which configure FrankenPHP rather than carry Drupack state.
