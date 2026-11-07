# One owner for a start

## Problem Statement

A start has no single owner. `launch.php` and `serve.php` run it in PHP. They call back into Go through seven words:

- `composer-settings`, `lay-app`, `lay-app --check` and `detach`, answered by the launcher
- `tls-source`, `tls-trust` and `browser-open`, answered by the runtime

About 50 `DRUPACK_*` variables and JSON on stdout carry the answers between the parts. Four routers read the reader's words: the launcher's `arguments()`, the runtime's `init()`, `launch.php`'s `command()` and `serve.php`'s final `match`.

The Caddyfile is also a template. PHP picks snippet files by name through `*_SNIPPET` variables. Caddy then splices variable values into the text before it parses. A value holding a double quote breaks the file, so every start checks for one.

A detached start runs the whole start twice. The parent claims the lease, releases it, and reruns itself as a background child, which claims it again. Another start can take the lease in between.

A maintainer who changes the listener, the TLS source or the static port edits PHP, Go and Caddy snippets together.

## Solution

The launcher plans and runs every start, for the Packaged site and the engine. It parses the words, plans the listener and TLS, holds the lease, detaches and writes the server config. PHP keeps the work that runs inside Drupal. The runtime keeps the work that runs inside FrankenPHP.

A reader sees the same commands, options, defaults and messages. A detached start runs once, in a background child whose log the terminal relays. The server config is one Caddyfile in the state directory, rendered from Caddy fragment files the launcher carries. A project's `drupack.caddy` keeps its format and its place in the route.

## Out of Scope

- the QA chain wiring and the Gitea/GitHub split
- the release and update flow: `self-update` and the upstream bumper
- the launcher cache store (ADR 0043)
- Windows path handling and console ownership
- the `tome_static` check before an export, which stays as committed in `601053b`
- a new command, option or `composer.json` setting
- Caddy's JSON config format

## User Stories

1. As a reader, I want every command, option, default and environment variable to behave as before, so that an update changes nothing I rely on.
2. As a reader, I want a detached start to show its refusals, first-start steps and URLs in my terminal, so that I see why a start failed.
3. As a reader, I want two starts on one Site data to end with one server, even when both begin at once, so that nothing corrupts the database.
4. As a reader who upgrades, I want Site data from the previous release to start, so that an upgrade needs no migration.
5. As a reader who upgrades while a site runs, I want the new `stop` to stop the old server, so that I can replace the file at any time.
6. As a reader, I want a path holding a brace refused at start, so that Caddy never reads it as a placeholder.
7. As a site author, I want my `drupack.caddy` imported after the guards as today, so that my rules keep working.
8. As a site author, I want an error in my `drupack.caddy` to name the file, so that I know which rule to fix.
9. As a site author, I want `extra.drupack` and `drupack.yml` to keep their rules, so that my project builds unchanged.
10. As a maintainer, I want one module to plan a start, so that a listener, TLS or static port change touches one place.
11. As a maintainer, I want to read the server config as one file in the state directory, so that I see what Caddy runs.
12. As a maintainer, I want the parser, start plan, state store and config writer tested by `go test`, so that a wrong combination fails without Docker.
13. As a maintainer, I want the seed built by the code a first start runs, so that each rule lives once.
14. As a maintainer, I want the build to compress the application payload once, so that each packed file reuses it.

## Implementation Decisions

### The owner

- D1: The launcher plans and runs every start, `drush`, `export`, `stop` and `trust`, on both executables. PHP runs only through `runtime php-cli`, called by the launcher. Invariant: no PHP script starts the launcher or calls a runtime word other than `php-cli` and `php`. Evidence: the `process()` and `replaceProcess()` calls in `application/launch.php` and `engine/serve.php`.
- D2: One parser in the launcher reads every word and option for both executables. It replaces `arguments()` in `launcher/plan.go`, `command()`, `options()` and `stopDataDirectory()` in `launch.php`, the loop in `serve()` and the final `match` in `serve.php`. Option names, defaults, `DRUPACK_*` input variables, `HELP`, `USAGE` and exit codes stay. A Go test asserts the help text and `docs/cli.md` against the parser's options. Evidence: the test `docs/cli.md names every option the parser accepts` in `application/tests/launch_test.php`.
- D3: A pure function turns the parsed words, the site settings, the TLS answer and the recorded listener into the start plan. The plan holds the bind, port, host, scheme, URL and probe URL, plus the static listener's bind, port and URL. It replaces `listenerPlan()`, `staticListener()`, `staticPlan()` and `serverEnvironment()`. Invariant: no step after the plan chooses a listener, TLS or static value again. Evidence: `application/process.php`, `application/static.php`.
- D4: The TLS code moves into the launcher. The source choice and `trust` become in-process calls. The `tls-source` JSON and `TlsChoice` go. Invariant: the TLS code needs no cgo. Evidence: `runtime/tls/go.mod` requires `smallstep/truststore` alone.
- D5: The engine reads `composer.json` in-process through `siteconfig.ReadComposer` and `CheckStatic`. The `composer-settings` word goes. Evidence: `composerSettings()` in `launcher/main.go`.
- D26: Environment variables carry only values that `settings.php`, Drush, Tome's children, Caddy or the runtime's watch read. The launcher passes its own choices to its child and to PHP as arguments. Evidence: the `putenv()` calls in `application/process.php`.

### The state store

- D6: One Go type owns a state directory: Site data or a folder's cache entry. It holds the lease `serving.lock`, `stop.json`, the Listener record, the TLS bundle and the Mercure secrets. Invariant: the lease is an exclusive lock on `serving.lock` that conflicts with PHP's `flock`, so a server from the previous release and a new start exclude each other. Evidence: `leaseHeld()` in `launcher/internal/runtime/lock_unix.go` and `lock_windows.go`.
- D7: Site data keeps every file name and format: the Listener record, installation progress, version record, secrets and `settings.php`. Evidence: `application/support/SiteData.php`.
- D8: A folder's cache entry holds one Listener record in Site data's format. It replaces the `static_url` and `mercure_url` files. An entry holding only the old files reads as never started, so `export` asks for a start or `--uri`, as it does today. Evidence: `STATIC_URL_FILE` and `MERCURE_URL_FILE` in `engine/serve.php`.

### The detached start

- D9: A detached start detaches first. The launcher parses the words and refuses a syntax error in the terminal. It then starts the background child with `--foreground`. The child claims the lease once and runs the whole start: plan, first-start steps or handover, then the server. The parent relays the child's log and exits with its code. `--foreground` and a console the start owns skip the detach. Invariant: one process holds the lease from claim to server exit. Evidence: `relay()` in `launcher/detach.go` prints the log until the ready line or the exit; `Serving::detach()` in `application/process.php`.
  - On Unix the child execs `runtime php-server`. Go opens files close-on-exec, so the child clears that flag on the lease handle before the exec. Evidence: `syscall.Exec` in `launcher/launch_unix.go`. Today PHP's `fopen` in `Serving::lock()` keeps the handle across `pcntl_exec`.
  - On Windows the child waits for the runtime, so its handle holds the lease until the server exits. Evidence: `launch()` runs the runtime with `command.Run()` in `launcher/launch_windows.go`.
  - The runtime alone decides readiness. The parent relays until the line the watch prints, or until the child exits. Evidence: `runtime/watch/watch.go`, `readyMarker` in `launcher/detach.go`.
- D10: A handover runs in the child. The child prints the URL and login link, opens the browser when the parent had a person present, and exits 0. The parent passes that fact to the child as an argument, and `DRUPACK_RUNTIME_PERSON` goes. Evidence: `handOver()` in `launch.php`, `putenv('DRUPACK_RUNTIME_PERSON=1')` in `Serving::detach()`.

### The server config

- D11: The launcher carries the HTTP rules as Caddy fragment files. Each start, it renders them from the start plan into one owner-only Caddyfile in the state directory. Go owns the values and the Caddy text owns the rules. The fragments:
  - one main template: global options, the site block, and the address list for the site port and the static port
  - two mode fragments holding the files route and the log, picked from the D19 table
  - `guards`, `static`, `static-site`, `static-dynamic` and `mercure`, kept as Caddy text
  - one fragment per TLS source, holding its global options and its `tls` directive

  One quoting function writes every value, and each static dynamic pattern becomes its own quoted token. The rendered file inlines every fragment and holds no `{$...}` reference. A feature that is off leaves its fragment out, so `none.caddy` goes. The 15 snippets and the Caddyfile in `application/`, with `engine/Caddyfile`, become these 11 fragments. These go: the 15 `.caddy` links in `engine/`, the `*_SNIPPET` and `DRUPACK_RUNTIME_TLS` variables, and `exportServerEnvironment()`'s checks. Invariant: the state directory is owner-only and already holds the secrets the file names. Evidence: `umask(0077)` in `launch.php`, `directory()` in `process.php`; the four repeated address lines in `tls-*-site.caddy` and `static-site-*.caddy`.
- D12: The start refuses a `--data-dir` or `--files-dir` holding `{` or `}`, since Caddy reads them as placeholders. The double-quote refusal on both options goes. Evidence: the `str_contains` checks in `launch.php`.
- D13: The Caddyfile imports `drupack.caddy` by absolute path, after the guards and before the Mercure hub and `php_server`. The runtime's server word adapts the file once, names the project file on an error as today, and runs the adapted config. The second adapt inside `run --adapter caddyfile` goes. `DRUPACK_RUNTIME_PROJECT_SNIPPET` goes. Evidence: `serveCaddyfile()` in `runtime/entrypoint.go`, the `Adapt` call and the `run` arguments.
- D14: `extra.drupack.static.dynamic` keeps its pattern rule. Evidence: `pathPattern()` in `launcher/internal/siteconfig/siteconfig.go`.

### PHP and the runtime

- D15: PHP owns Drupal preparation and its progress records. One PHP script runs one named operation per call, through `runtime php-cli`. The operations:
  - `prepare`, for a Packaged site: runs the owed first-start steps, recovers an interrupted one, and writes installation progress
  - `check`, for an engine folder: the platform check and the installation probe
  - `login`: the login link a handover prints

  Each operation prints its step lines to stderr and one result record to stdout, which the launcher reads. Credentials reach it through the environment, never argv. Invariant: only PHP reads or writes Site data's progress files, since preparation and recovery must agree on which Drupal steps finished. Evidence: `runStep()` in `launch.php`, `application/support/Step.php`, `Need.php`, `engine/installed.php`, `checkPlatform()` in `serve.php`.
- D16: One `drush` entry requires the script `DRUPACK_DRUSH_SCRIPT` names. The launcher sets it to the application's Drush or the project's. `bin/php`, `bin/php.cmd` and `drush.cmd` stay, since Tome's children rerun `argv[0]`. Evidence: `engine/drush` requires that variable today, and `application/drush` requires the application's Drush by path.
- D17: `settings.php` keeps reading the environment. The variables it reads keep their names. Evidence: the `getenv()` calls in `application/settings.php`.
- D18: The runtime answers `php-cli`, `php-server`, `php` and the version flags alone. The launcher runs Node from its own release directory. On Windows it waits for Node and passes a termination on, as `runNode` does today. `runNode`, `tls-trust`, `tls-source` and `browser-open` leave the runtime. Invariant: a SIGTERM to the launcher reaches Node on every platform. Evidence: `init()` and `runNode()` in `runtime/entrypoint.go`, `launch()` in `launcher/launch_windows.go`, ADR 0030's considered options.

### Two modes

- D19: The Packaged site and the engine are two modes of one start.

  | Concern | Packaged site | Engine |
  |---|---|---|
  | State directory | Site data | the folder's cache entry |
  | Settings | `site.json` | the folder's `composer.json` |
  | First checks | owed steps | Drupal docroot, platform |
  | Files route | outside the docroot | under the docroot |
  | Server log | rolled file in Site data | stderr |

  Evidence: `application/Caddyfile`, `engine/Caddyfile`, `serve()` in `serve.php`.
- D20: The PHP files both payloads carry live in one directory, and each payload copies its own list. The `engine/` directory and its 21 links go. Evidence: `layEngine()` in `launcher/internal/build/build.go`, `stageEngine()` in `engine.go`.

### The build

- D21: The build installs the seed through the PHP preparation code a MySQL first start runs, against the seed's SQLite database. The settings template fill, the recipe module removal and the seed password then live once. `build/seed.sh` goes. Evidence: `build/seed.sh`, `seedPassword()` in `launch.php`.
- D22: The build compresses the application payload once and passes the compressed file to every pack step. The application checksum keeps its input: the whole application tree, including files the payload leaves out. Invariant: Drupal's cached absolute paths use that identity. Evidence: `writeAppPayload()` in `launcher/cmd/pack/main.go`; the two `tar` passes and their comment in `build/app-payload.sh`.
- D25: Engine and site builds take separate input types and share archive creation, checksum and packing. The Drupal module exclusions apply to the site payload alone, and the engine build passes no docroot. Evidence: `NewEnginePlan()` passes `web` to `app-payload.sh` in `launcher/internal/build/engine.go`.

### Removal and docs

- D23: These go with their tests and callers: `launch.php`, `engine/serve.php`, `process.php`, `static.php`, `mercure.php` and `SiteContract.php` as start code, the Caddy files D11 names, `engine/`, `build/seed.sh`, `plan.go`'s `arguments()`, and the runtime words in D18. `build/app-payload.sh` goes once the Go build holds its tar passes (D22, D25). PHP functions the preparation script still needs move into it. Evidence: `rg -l` over each name before deletion.
- D24: A new ADR records D1 to D20 and D26. It supersedes ADR 0030, 0039 and 0040. It amends ADR 0014 (the parser moves), 0028 (detach first), 0044 (no `composer-settings` word), 0047 (the guards ship as a launcher fragment) and 0048 (import by path). `drupack-architecture.md`, `CONTEXT.md`, `CONTRIBUTING.md` and `docs/cli.md` follow. Evidence: each ADR's Decision section.

### Considered options

- The current split, with cuts inside it. One Caddyfile, collapsed snippets and direct routes for `trust` and Node. It kept two languages planning one start and four routers.
- Caddy JSON built in the runtime. The launcher links no Caddy, and `drupack.caddy` may use any FrankenPHP directive. The builder would sit in cgo code, which tests only in the builder image.
- Caddy JSON built in the launcher. The launcher cannot adapt `php_server` or a project snippet without FrankenPHP's modules.
- The launcher writes every Caddy rule from Go code. The HTTP rules would become string building in Go, off Caddy's own text.
- Shared Caddyfiles that keep `{$...}` splicing, from the Codex recommendations. Values still pass through Caddyfile text, so the quote check and the snippet choice by name stay.
- Prepare in the parent, then hand the lease to the server. A Windows `LockFileEx` lock ends with the handle that took it, so Windows kept a race window.
- Go owns installation progress and calls PHP once per step. Recovery would need Drupal's step semantics in Go.

## Testing Decisions

A good test drives a module through its interface and checks its output: a plan, a file, an exit code or a log line. It never asserts a private call.

- The conformance suite runs unchanged on both executables and both libcs. It is the parity check for user stories 1 to 9.
- Go table tests cover the parser, the start plan, the state store and the Caddyfile writer. The writer's tests compare generated files for each row of the D19 table, each TLS source and each static setting. Prior art: `launcher/plan_test.go`, `launcher/internal/siteconfig/siteconfig_test.go`.
- A Go test runs the runtime's adapter over generated files in the builder image, beside `runtime/tls` and `runtime/watch` tests.
- The preparation script keeps PHP tests, as `application/tests/launch_test.php` and `site_data_test.php` do. They get their own Composer install, apart from the demo's vendor directory and the executable build. Evidence: `build/qa.sh`.
- Unit checks run before the executable builds in `build/qa.sh`.
- A conformance case starts the previous release's server and stops it with the new `stop`, for user story 5. Prior art: `DRUPACK_TEST_PREVIOUS` in `tests/conformance/writable_cases.py`.
- A conformance case starts twice on one Site data at once, for user story 3. Prior art: `tests/conformance/handover_cases.py`, `concurrent_sites_cases.py`.

## Further Notes

- The design comes from the architecture review in `C:\Users\theno\Downloads\drupack-architecture-review.md`, ranked by clarity alone. D9, D10, D13, D15, D22, D25 and D26 take findings from `C:\Users\theno\Downloads\drupack-architecture-recommendations.md`.
- Caddy's quoted tokens take `\"` escapes. The writer's tests confirm it against Caddy 2.11's adapter before the `{$...}` references go.
- D13's single adapt needs `run` with adapted JSON to start as `run --adapter caddyfile` does. The runtime adapter test confirms it.
- The generated Caddyfile names the Mercure secrets in place of `{$...}` references. The secrets already live in the same owner-only directory.
