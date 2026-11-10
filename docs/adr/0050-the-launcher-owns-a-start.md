# The launcher owns a start

Accepted on 2026-11-08. Supersedes [ADR 0030](0030-the-launcher-routes-the-readers-words.md),
[ADR 0039](0039-the-launcher-plans-the-runtimes-command.md) and
[ADR 0040](0040-a-start-plans-its-listener.md). Amends
[ADR 0014](0014-the-parser-owns-the-command-line.md),
[ADR 0028](0028-detached-start-and-stop.md),
[ADR 0044](0044-one-reader-takes-a-projects-composer-settings.md),
[ADR 0047](0047-request-guards-match-htaccess.md) and
[ADR 0048](0048-a-project-adds-its-own-caddy-rules.md). Amended by
[ADR 0052](0052-one-reader-for-the-served-address.md) and
[ADR 0053](0053-one-function-builds-a-childs-environment.md).

## Context

`launch.php` and `serve.php` ran a start in PHP and called back into Go through
seven words: `composer-settings`, `lay-app`, `lay-app --check`, `detach`,
`tls-source`, `tls-trust` and `browser-open`. About 50 `DRUPACK_*` variables and
JSON on stdout carried the answers. Four routers read the reader's words: the
launcher's `arguments()`, the runtime's `init()`, `launch.php`'s `command()`
and `serve.php`'s final `match`.

The Caddyfile was a template. PHP picked snippet files by name through
`*_SNIPPET` variables, and Caddy spliced variable values into the text before
it parsed. A value holding a double quote broke the file, so every start
checked for one.

A detached start ran the whole start twice. The parent claimed the lease,
released it, and reran itself as a background child, which claimed it again.
Another start could take the lease in between. A listener, TLS or static-port
change meant editing PHP, Go and Caddy snippets together.

## Decision

### The owner

- The launcher plans and runs every start, `drush`, `export`, `stop` and
  `trust`, on both executables. PHP runs only through `runtime php-cli`, called
  by the launcher.
- One parser (`launcher/parse.go`) reads every word and option for both
  executables. Option names, defaults, `DRUPACK_*` input variables, help text
  and exit codes are unchanged.
- The help and usage texts live in `launcher/help.go`. A Go test compares them
  and `docs/cli.md` against the parser's tables.
- A pure function turns the parsed words, the site settings, the TLS answer and
  the recorded listener into the start plan. No later step chooses a listener,
  TLS or static value again.
- The TLS code runs in the launcher. The `tls-source` word, `tls-trust` and
  `TlsChoice` are gone.
- The engine reads `composer.json` in-process through
  `siteconfig.ReadComposer`. The `composer-settings` word is gone.
- Environment variables carry only values that `settings.php`, Drush, Tome's
  children, Caddy or the runtime's watch read. The launcher passes its own
  choices to its child and to PHP as arguments.

### The state store

- `launcher/internal/state` owns a state directory: Site data or a folder's
  cache entry. It holds the Serving lease `serving.lock`, `stop.json` and the
  Listener record.
- The lease is an exclusive lock that conflicts with PHP's `flock`. A server
  from the previous release and a new start exclude each other.
- Site data keeps every file name and format it had.
- A folder's cache entry holds one Listener record in Site data's format. It
  replaces the `static_url` and `mercure_url` files. An entry holding only the
  old files reads as never started.

### The detached start

- A detached start detaches first. The launcher parses the words and refuses a
  syntax error in the terminal. It then starts a background child with
  `--foreground`.
- The child claims the lease once and runs the whole start: plan, first-start
  steps or handover, then the server. The parent relays the child's output and
  exits with its code. `--foreground` and a console the start owns skip the
  detach.
- On Unix the child execs `runtime php-server`. On Windows it waits for the
  runtime, so its handle holds the lease until the server exits.
- The runtime alone decides readiness. The parent relays until the line the
  watch prints, or until the child exits.
- A handover runs in the child. It prints the URL and login link, opens the
  browser when the parent had a person present, and exits 0. The parent passes
  that fact as an argument, and `DRUPACK_RUNTIME_PERSON` is gone.

### The server config

- The launcher carries the HTTP rules as 11 Caddy fragment files in
  `launcher/internal/caddyfile/fragments`: the main template, two mode
  fragments (`mode-site`, `mode-engine`), `guards`, `static`, `static-site`,
  `static-dynamic`, `mercure`, and one per TLS source (`tls-drupack`,
  `tls-mkcert`, `tls-none`).
- Each start renders the fragments from the start plan into one owner-only
  Caddyfile in the state directory. Go owns the values and the Caddy text owns
  the rules. A feature that is off leaves its fragment out.
- One function quotes every value. The rendered file holds no `{$...}`
  reference, and the `*_SNIPPET` variables are gone.
- A start refuses a `--data-dir` or `--files-dir` holding `{` or `}`, which
  Caddy reads as placeholders. The double-quote refusal is gone.
- The Caddyfile imports `drupack.caddy` by absolute path, after the guards and
  before the Mercure hub and `php_server`. The runtime adapts the file once and
  runs the adapted JSON. `DRUPACK_RUNTIME_PROJECT_SNIPPET` is gone.
- `extra.drupack.static.dynamic` keeps its pattern rule.

### PHP and the runtime

- PHP owns Drupal preparation and its progress records. `application/operate.php`
  runs one named operation per call, through `runtime php-cli`.
- An operation prints its step lines to stderr and one result record to
  stdout. Credentials reach it through the environment, never argv.
- One `drush` entry requires the script `DRUPACK_DRUSH_SCRIPT` names. `bin/php`,
  `bin/php.cmd` and `drush.cmd` stay, since Tome's children rerun `argv[0]`.
- `settings.php` keeps reading the environment under the same variable names.
- The runtime answers `php-cli`, `php-server`, `php` and the version flags. The
  launcher runs Node from its own release directory.
- On Unix the launcher replaces itself with Node, so the shell sees Node's exit
  status. On Windows it waits for Node, which shares its console.

### Two modes

- The Packaged site and the engine are two modes of one start plan.

  | Concern | Packaged site | Engine |
  |---|---|---|
  | State directory | Site data | the folder's cache entry |
  | Settings | `site.json` | the folder's `composer.json` |
  | First checks | owed steps | Drupal docroot, platform |
  | Files route | outside the docroot | under the docroot |
  | Server log | rolled file in Site data | stderr |

- The PHP files both payloads carry live in `application/`. The engine payload
  copies its own list of six files from it. The `engine/` directory is gone.

## Deviations from the PRD

Each row records a decision as built, with its reason.

- `operate.php` runs `prepare`, `check` and `seed`. The `login` operation moved
  into Go's mint, with a served-URL prefix check. The owner took this cut from
  the reduction review on 2026-11-08.
- On Unix the lease opens with `syscall.Open`, with no close-on-exec flag. The
  PRD had the child clear the flag before the exec. Now prepare, Drush, the
  engine's minting Drush and the server all hold the lease, so no process that
  writes Site data outlives it. A step's long-lived grandchild would keep the
  lease after the start dies.
- The server marks its inherited descriptors close-on-exec, so a browser it
  opens holds no lease. Its cron Drush holds none either, and the server waits
  for that Drush before it exits.
- The brace refusal covers the engine's project folder and docroot, and the
  site's `--data-dir` and `--files-dir`, as typed and once links resolve. The
  resolved check also covers a files directory an older release recorded.
- A value whose backslash precedes a double quote or ends it goes into the
  Caddyfile between backticks, since no double-quote escape holds it. A value
  that also holds a backtick is refused.
- The runtime feeds the adapted JSON to `run --config -` through a pipe set as
  `os.Stdin`, so no file holds the secrets twice. A SIGUSR1 reload rereads an
  empty pipe, so a reload
  needs a restart.
- A project's `drupack.caddy` path travels as a fourth argument of `php-server`.
  [ADR 0056](0056-a-project-tunes-its-mercure-hub.md) adds the hub file's path
  after it.
  The runtime prints it after an adapt error, since Caddy names the rendered
  file and not the imported one.
- The site and engine starts share one start plan. The Caddyfile lives in Site
  data for a site, and in the folder's cache entry for the engine.
- The child prints to the parent's terminal through two pipes until it holds the
  lease. It then writes the server log, truncating it only under the lease, and
  tells the parent with a marker line. A losing start leaves the winner's log
  alone. The child catches SIGPIPE, so a closed parent fails a write and ends
  nothing.
- Two hidden leading arguments are stripped before the parser reads the line.
  `--console-owned` marks a start from a Windows file manager, and
  `--detached-child LOG PERSON` marks the child of a detached start. A reader
  who types either gets that mode, not an unknown-argument error. A leading `-`
  keeps each apart from an engine folder name.
- A typed `--database`, `--db-*`, `--admin-*` and `--site-name` reach `prepare`
  and `check` under their `DRUPACK_*` input names, and never the server's
  environment.
- A `drush` call no longer exports `DRUPACK_DATABASE`, `DB_*`, `ADMIN_*` or
  `SITE_NAME`. It no longer refuses a double quote in `--data-dir`,
  `--files-dir` or a bad `--database`, since it writes no Caddyfile.
- A held lease is answered from the check made before the claim. A rival that
  finishes installing in that window makes this start refuse "preparing", and a
  rerun hands over.
- The engine's install probe and platform check run in one `check` call before
  the lease claim, so the probe sees the reader's `DRUPACK_CA_FILE`.
- A Listener record with an empty `listen` or `host` reads as missing that key.
  The JSON literal `null` reads as no listen address.
- The static-listen, host and brace refusals precede the TLS and start-plan
  refusals. The static refusal names the planned address.
- The engine passes the canonical Caddyfile path to `php-server`, as the site
  does, so a Windows engine start shows forward slashes.
- A Windows engine start runs the server in the project folder. A Unix one keeps
  the reader's directory.
- The runtime keeps its own `openBrowser` for the watch. The launcher holds a
  copy for the handover.
- `pcntl` stays in `runtime/php-extensions.txt`. Symfony Console and Drush
  register their SIGINT handlers through it.

## Outside this record

Decisions for the build were made after the PRD, and stay out of this ADR:

- `build/app-payload.sh` stays. The Go build compresses its tar once and every
  pack step carries that file. The PRD removed the script.
- The test that the checksum changes with a file the payload leaves out lives in
  `tests/conformance/test_app_payload.py`, beside the script that owns the
  exclusions. The plan had a Go test.
- Engine and site plans share one `Request` type, and the engine passes no
  docroot to the script. The PRD asked for separate input types. A split would
  touch `resolveRuntimes` and `packSteps` for no change in behaviour.
- The seed build is the `seed` operation of `operate.php`, and `build/seed.sh` is
  gone.

## Considered options

- The current split, with cuts inside it. One Caddyfile, collapsed snippets and
  direct routes for `trust` and Node. Two languages still planned one start, and
  four routers stayed.
- Caddy JSON built in the runtime. The builder would sit in cgo code, which
  tests only in the builder image.
- Caddy JSON built in the launcher. The launcher cannot adapt `php_server` or a
  project snippet without FrankenPHP's modules.
- Every Caddy rule written from Go code. The HTTP rules would become string
  building, off Caddy's own text.
- Shared Caddyfiles that keep `{$...}` splicing. Values still pass through
  Caddyfile text, so the quote check and the snippet choice by name stay.
- Prepare in the parent, then hand the lease to the server. A Windows
  `LockFileEx` lock ends with the handle that took it, so a race window stayed.
- Go owns installation progress and calls PHP once per step. Recovery would need
  Drupal's step semantics in Go.

## Consequences

- A listener, TLS or static-port change touches the start plan and one fragment.
- A wrong combination fails `go test` without Docker.
- A maintainer reads the server config as one file in the state directory.
- The Windows paths this ADR changed compiled under `GOOS=windows` and run only
  on a tag run.
- `docs/cli.md`, `CONTEXT.md` and `CONTRIBUTING.md` follow the launcher as
  built. [Issue 3636037](https://git.drupalcode.org/project/drupack/-/work_items/3636037)
  lists the Windows checks that stay open.
