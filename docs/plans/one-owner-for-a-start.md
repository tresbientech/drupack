# Plan: One owner for a start

**Spec:** docs/prd/one-owner-for-a-start.md

## Architectural decisions

The PRD's decisions, one line each:

- **D1**: The launcher plans and runs every start, `drush`, `export`, `stop` and `trust`. PHP runs only through `runtime php-cli`.
- **D2**: One parser in the launcher reads every word and option for both executables. Names, defaults, inputs, help and exit codes stay.
- **D3**: A pure function turns words, settings, the TLS answer and the recorded listener into the start plan.
- **D4**: The TLS code moves into the launcher. Source choice and `trust` are in-process calls.
- **D5**: The engine reads `composer.json` in-process. The `composer-settings` word goes.
- **D6**: One Go type owns a state directory: lease, stop record, Listener record, TLS bundle, Mercure secrets.
- **D7**: Site data keeps every file name and format.
- **D8**: A folder's cache entry holds one Listener record. `static_url` and `mercure_url` go.
- **D9**: A detached start detaches first. The child claims the lease once and runs the whole start.
- **D10**: A handover runs in the child. The person-present fact travels as an argument.
- **D11**: The launcher renders Caddy fragment files into one owner-only Caddyfile per start. No `{$...}` reference.
- **D12**: A start refuses `{` or `}` in `--data-dir` or `--files-dir`. The double-quote refusal goes.
- **D13**: The rendered file imports `drupack.caddy` by absolute path after the guards. The runtime adapts once.
- **D14**: `extra.drupack.static.dynamic` keeps its pattern rule.
- **D15**: PHP owns Drupal preparation and its progress records, through `prepare`, `check` and `login`.
- **D16**: One `drush` entry requires the script `DRUPACK_DRUSH_SCRIPT` names.
- **D17**: `settings.php` keeps reading the environment, under the same names.
- **D18**: The runtime answers `php-cli`, `php-server`, `php` and the version flags alone. The launcher runs Node and passes a termination on.
- **D19**: The Packaged site and the engine are two modes of one start.
- **D20**: The PHP files both payloads carry live in one directory. `engine/` goes.
- **D21**: The seed is built through the PHP preparation code. `build/seed.sh` goes.
- **D22**: The payload is compressed once. The checksum keeps the whole application tree as input.
- **D23**: The PHP start code, the replaced Caddy files, `engine/`, `seed.sh` and the runtime words go with their tests.
- **D24**: A new ADR records the change. It supersedes 0030, 0039 and 0040, and amends 0014, 0028, 0044, 0047 and 0048.
- **D25**: Engine and site builds take separate input types.
- **D26**: Environment variables carry only values Drupal, Drush, Tome, Caddy or the watch read.

Durable models every phase uses:

- **Start plan**: bind, port, host, scheme, URL, probe URL, the static listener, the TLS source, the mode.
- **State store**: one value per state directory, for Site data and for a folder's cache entry.
- **PHP operation call**: `runtime php-cli SCRIPT OPERATION`. Step lines go to stderr, one result record to stdout.
- **Rendered Caddyfile**: one file in the state directory, rewritten on every start.

## How a phase is checked

- Build: the build lines of `build/qa.sh` up to the PHP unit files. They give `dist/drupacked-demo-linux-ARCH`, the variant and `dist/engine/drupack-linux-ARCH`.
- `conformance -k NAME` below means `DRUPACK_TEST_ENGINE=dist/engine/drupack-linux-ARCH python3 tests/conformance dist/drupacked-demo-linux-ARCH RESULTS --site-tests DEMO/tests -k NAME`.
- `go tests` means `bash build/go-tests.sh`. `unit tests` means `bash build/unit-tests.sh`.
- `php tests` means the `application/tests/*_test.php` loop of `build/qa.sh`.
- Each phase passes `go tests`, `unit tests`, `php tests` and the conformance classes it names. The full suite, `bash build/qa.sh` plus the unfiltered conformance run, passes once at the end.

---

## Phase 1: Direct `trust` and Node

**User stories**: 1, 10
**Decisions**: D4, D18

### What to build

The launcher runs `trust` in-process with the TLS code, for both executables. It runs `node`, `npm` and `npx` from its Node release directory. On Windows it waits for Node and passes a termination on. The runtime drops `tls-trust` and the Node words. PHP stops routing `trust`.

### Acceptance criteria

- [ ] `conformance -k TrustCommand -k SystemTrust -k RuntimeTrustOnline` passes.
- [ ] `conformance -k NodeCommands -k NodeClean -k NodeOnPath -k NodeFetchesPackages` passes, and the variant's musl run of `-k NodeRefusals` passes.
- [ ] A launcher Go test sends SIGTERM to a Node child and asserts the child received it.
- [ ] `rg -n 'tls-trust|runNode' runtime application engine` prints nothing.

---

## Phase 2: The launcher owns `stop`

**User stories**: 1, 5, 12
**Decisions**: D2 (stop), D6

### What to build

The launcher parser reads `stop` and its options for both executables. The state store finds the state directory, tests the lease, reads `stop.json` and posts the stop. Its lease conflicts with PHP's `flock`, so it stops a server the previous release started. PHP no longer handles `stop`.

### Acceptance criteria

- [ ] `conformance -k StopCases -k StopRecordModeCases` passes.
- [ ] A new `PreviousRelease` test starts the previous release's server and stops it with the new `stop`. It passes with `DRUPACK_TEST_PREVIOUS` set.
- [ ] Go table tests cover the parser's `stop` rows and the state store's lease test against a PHP `flock` holder.
- [ ] A Go test asserts `stop`'s options against the help text and `docs/cli.md`.

---

## Phase 3: The launcher owns `drush`

**User stories**: 1, 9
**Decisions**: D2 (drush), D5 (drush), D16, D17, D26 (drush)

### What to build

The launcher parser reads `drush` and its options. It reads the Listener record and a project's `composer.json` in-process, and builds the environment Drush and `settings.php` read. One `drush` entry serves both payloads. `bin/php` and the `.cmd` shims stay.

### Acceptance criteria

- [ ] `conformance -k SeededSite -k EngineExecutable -k StaticSiteExport -k WritableDirectories` passes.
- [ ] A Go table test asserts the environment a `drush` call gets, per mode.
- [ ] `rg -n composer-settings launcher engine application` prints nothing.

---

## Phase 4: Engine foreground start in the launcher

**User stories**: 6, 7, 8, 9, 10, 11, 12
**Decisions**: D2 (start), D3, D4 (source), D5, D8, D11, D12, D13, D14, D15 (check), D19 (engine), D26 (start)

### What to build

An engine start with `--foreground` runs from the launcher. It parses, reads the folder's settings, chooses the TLS source and builds the start plan. It claims the lease and runs PHP's `check`. It renders the Caddyfile from the fragments and execs `runtime php-server`. The runtime adapts the file once. The folder entry writes one Listener record. The brace refusal applies.

### Acceptance criteria

- [ ] `conformance -k EngineExecutable -k KeepRedirects` passes. It covers TLS, Mercure, the static port and both project snippet cases.
- [ ] The help and `docs/cli.md` assertion moves to a Go test over every option and passes.
- [ ] Go table tests compare rendered Caddyfiles for each TLS source, each static setting and Mercure on and off.
- [ ] A Go test in the builder image adapts each rendered file with the pinned Caddy and runs the adapted config. It also checks a quoted value holding `\"`.
- [ ] A Go test refuses `{` and `}` in both directory options.

---

## Phase 5: Packaged site foreground start in the launcher

**User stories**: 1, 4
**Decisions**: D7, D10 (foreground), D15 (prepare, login), D19 (site)

### What to build

A Packaged site start with `--foreground` runs from the launcher, on the start path of phase 4. PHP's `prepare` runs the owed first-start steps, recovers an interrupted one and writes progress. `login` prints the handover's login link. The site mode fragment carries the files route outside the docroot and the rolled log.

### Acceptance criteria

- [ ] `conformance -k FirstStartAndListener -k RecordedBackendAndAdoption -k InterruptedStartAndRace -k EquivalentPathLock -k SiteName` passes.
- [ ] `conformance -k MysqlServerDatabase -k PostgresqlServerDatabase -k PostgresqlLifecycle -k ExistingSiteAdoption -k OfflineRun` passes.
- [ ] `conformance -k HandoverCases -k ServingLeaseCases -k TakenPortCases -k MercureHubHttps -k MercureHubOff` passes.
- [ ] `conformance -k TlsSources -k StaticSite -k StaticSiteTls -k NetworkListener` passes.
- [ ] The variant's musl run of `-k MercureHub -k ProjectRules` passes, including the broken-snippet message.
- [ ] PHP tests call `prepare`, `check` and `login` and assert their result records.
- [ ] `conformance -k PreviousRelease` passes with `DRUPACK_TEST_PREVIOUS` set.

---

## Phase 6: Detach first

**User stories**: 2, 3
**Decisions**: D9, D10, D26 (detach)

### What to build

A start without `--foreground` parses, then starts the background child with `--foreground` and relays its log. The child claims the lease once and runs the whole start. On Unix the lease handle stays open across the exec into the runtime. The parent passes the person-present fact and the log path as arguments.

### Acceptance criteria

- [ ] `conformance -k DetachedCases -k DetachedHandoverCases -k BrowserOpenCases` passes.
- [ ] A new conformance case starts twice on one Site data at once and ends with one server and one refusal.
- [ ] A Go test execs a child that holds the lease and asserts another process sees it held.
- [ ] `rg -n 'DRUPACK_RUNTIME_PERSON|DRUPACK_RUNTIME_DETACHED' launcher runtime application` prints nothing.

---

## Phase 7: Remove the PHP start code and `engine/`

**User stories**: 10
**Decisions**: D1, D18 (rest), D20, D23

### What to build

The PHP start scripts, the replaced Caddy files and the `engine/` directory go. Both payloads copy their PHP files from one directory. The runtime drops `tls-source` and `browser-open`. The console-owned signal moves to an argument.

### Acceptance criteria

- [ ] `rg -ln 'launch\.php|serve\.php|lay-app|tls-source|browser-open|_SNIPPET' -g '!*.md'` prints nothing.
- [ ] `test ! -e engine` succeeds.
- [ ] The unfiltered conformance run passes on the demo, the variant and the engine executable.

---

## Phase 8: Build

**User stories**: 13, 14
**Decisions**: D21, D22, D25

### What to build

The seed build runs PHP's preparation code against the seed's SQLite database. The pack steps share one compressed payload, and the checksum keeps the whole application tree as input. Engine and site builds take separate input types, and only the site payload applies the Drupal exclusions. The PHP tests get their own Composer install, and unit checks run before the executable builds.

### Acceptance criteria

- [ ] `test ! -e build/seed.sh` succeeds, and `conformance -k SeededSite` passes.
- [ ] A Go test packs two files from one payload and asserts one compression.
- [ ] A Go test asserts the checksum changes when a file the payload excludes changes.
- [ ] A Go test builds the engine plan and asserts it names no docroot.
- [ ] `php tests` pass with their own Composer install and no `application/vendor` link into `dist/work`.

---

## Phase 9: New ADR and docs

**User stories**: none
**Decisions**: D24

### What to build

A new ADR records D1 to D20 and D26. It supersedes ADR 0030, 0039 and 0040, and amends 0014, 0028, 0044, 0047 and 0048. `drupack-architecture.md`, `CONTEXT.md`, `CONTRIBUTING.md` and `docs/cli.md` follow.

### Acceptance criteria

- [ ] `rg -n 'Superseded|Amended' docs/adr/0014* docs/adr/0028* docs/adr/0030* docs/adr/0039* docs/adr/0040* docs/adr/0044* docs/adr/0047* docs/adr/0048*` names the new ADR in each file.
- [ ] The Go test over `docs/cli.md` from phase 4 passes.
- [ ] `rg -ln 'launch\.php|serve\.php|lay-app|tls-source|_SNIPPET' -g '*.md' -g '!docs/adr/**' -g '!docs/prd/**' -g '!docs/plans/**'` prints nothing.
- [ ] `bash build/qa.sh` and the unfiltered conformance run pass.
