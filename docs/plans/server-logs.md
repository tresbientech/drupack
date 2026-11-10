# Plan: A start writes an access log and an error log

**Spec:** docs/adr/0058-a-start-writes-its-logs-on-request.md, written in Phase 1.
No PRD exists. The decisions below come from the design interview of 2026-11-10.

## Architectural decisions

- **D1**: `--access-log[=FILE]` writes one JSON file for both ports. The named
  loggers `http.log.access.site` and `http.log.access.static` key its lines.
  The static port logs whenever the site port does.
- **D2**: `--error-log[=FILE]` writes PHP and Caddy messages at `WARN` and up.
  PHP notices, deprecations and `error_log()` calls stay out.
- **D3**: Both flags are off by default. The bare flag uses the default path
  and `--flag=FILE` names another. `--flag FILE` is refused with a message
  that names the `=` form.
- **D4**: A packaged site's defaults are `<data>/logs/access.log` and
  `<data>/logs/error.log`. An engine's defaults are `access.log` and
  `error.log` beside `server.log` in the folder's cache entry. A packaged
  site's Caddy log is `logs/caddy.log`, and `logs/server.log` holds a detached
  start's output.
- **D5**: The engine start and the packaged site start take both flags.
- **D6**: The engine's stderr log at `ERROR` and the site's `logs/caddy.log`
  and `logs/server.log` do not change.
- **D7**: Both files share one shape and one set of path rules, listed below.

File shape (D7):

- JSON, `roll_size 100MiB`, `roll_keep 10`, Caddy's default mode 0600
- `--foreground` keeps stderr and still writes the files
- the start's output names each log path whose flag is set
- the launcher's readiness polls of `/.drupack-id` stay out of the access log

Path rules (D7):

- a relative FILE resolves against the caller's working directory, before the
  start detaches
- the start refuses a FILE that holds a brace
- the start refuses a FILE inside the docroot or the static directory
- the start refuses the same FILE for both flags
- each refusal has a Go unit test and no live case (ADR 0057)

Facts from the code:

- `launcher/parse.go` has two option kinds: a flag, and a value read inline or
  from the next argument. D3 adds a third kind to both parsers.
- `siteOptions` is also the list a packaged site's drush accepts. Drush accepts
  both flags and ignores them, as it does `--no-browser`.
- Caddy refuses `log` inside `route`, so `drupack.caddy` cannot configure
  either log.
- PHP messages reach Caddy under the logger `http.handlers.php`. Measured on
  FrankenPHP 1.13.5: fatals at `ERROR`, warnings at `WARN`, the rest at `INFO`.

---

## Phase 1: Access log on both starts

**Decisions**: D1, D3, D4 (access paths), D5, D7

### What to build

Both starts take `--access-log[=FILE]`. With the flag, the site port and the
static port each write their requests to one JSON file under their own logger
name. A start without the flag renders the Caddyfile it renders today.

The phase adds the optional-path option kind, the path rules and the default
paths. It writes ADR 0058 and documents the flag in the help text and both
sections of `docs/cli.md`.

### Acceptance criteria

- [ ] `drupack --access-log` on a folder with a static port writes
      `access.log` beside `server.log`. One request per port yields one line
      under each logger name.
- [ ] A packaged site started with `--access-log` writes
      `<data>/logs/access.log`.
- [ ] `--access-log=logs/a.log` from a detached start writes under the
      caller's working directory.
- [ ] `--access-log FILE` refuses and names the `=` form. On the engine the
      same refusal meets a folder written after the bare flag.
- [ ] A FILE with a brace, in the docroot or in the static directory refuses,
      each with a Go unit test.
- [ ] The start's output names the access log path.
- [ ] A start without the flag renders an unchanged Caddyfile.
- [ ] One live case per start reads a keyed line from the file.
- [ ] `parse_test.go` passes against the help text and `docs/cli.md`.
- [ ] ADR 0058 records D1 to D7.

---

## Phase 2: Error log on both starts

**Decisions**: D2, D4 (error paths), D6, D7 (same-file refusal)

### What to build

Both starts take `--error-log[=FILE]`, built on Phase 1's option kind and path
rules. The file receives every logger at `WARN` and up except the access
loggers. Caddy logs the access entry of a 5xx response at `ERROR`, so the
phase starts by checking that exclusion with `frankenphp adapt` and one 500
response.

The phase removes the backlog entry "Access logs on an engine server". It
adds one entry for the parked engine `server.log` gaps: no rotation, and the
`ERROR` level that drops PHP warnings.

### Acceptance criteria

- [ ] With `--error-log`, a page that raises a PHP warning and a fatal writes
      both as JSON lines, on each start, at the default path of D4.
- [ ] A PHP notice and a deprecation write no line.
- [ ] A 500 response writes no access line to the error log.
- [ ] The engine's stderr output and the site's `server.log` hold what they
      held before the flag.
- [ ] The same FILE for both flags refuses, with a Go unit test.
- [ ] One live case per start reads a PHP warning from the file.
- [ ] `parse_test.go` passes against the help text and `docs/cli.md`.
- [ ] `docs/backlog.md` loses the access-log entry and gains the `server.log`
      entry.
