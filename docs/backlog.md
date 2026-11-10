# Backlog

Open questions, one paragraph each: the question, what makes it worth answering,
and the current lean. A decided one moves to `docs/adr/` as a numbered record and
leaves here. Work already scheduled lives in `docs/plans/`.

## Site data compatibility across releases

An installation marker makes a start skip initialization without checking which
release wrote the database. Newer code opens an older schema, and an older
executable opens a database a newer release updated. Nothing applies pending
database updates. Lean: persist the release that last updated Site data, gate
serving on it, and add an explicit update operation with exclusive access.
Release cadence and the daily watch job belong to the same answer: a security
release in core, PHP or a bundled module ships within days, and one refresh a
month picks up the rest.

## The options `drush` accepts and ignores

`drush` parses all 13 launch options before the Drush command and acts on three:
`--data-dir`, `--listen` and `--host`. It consumes the other ten and drops
them, so `drush --admin-password x user:login` spends a secret for nothing and
`drush --database bogus status` exits 1 over a value it never reads. Lean: give
the launcher's parser (`siteOptions` in `launcher/parse.go`) the accepted set per
word, so an option `drush` cannot use raises the unknown-argument error it
already has for an unknown word. `docs/cli.md`
records the current behaviour until then.

## Container lifecycle in the conformance suite

`harness.DatabaseServer` now owns the database lifecycle both database modules
shared. The suite still drives Docker through 14 raw `subprocess.run` calls in
three modules: the network cases, the offline case and one launcher case. A
maintained package would replace them, at the cost of the first Python
dependency in a repo that has none. Lean: judge the dependency against those 14
calls.

## A start without a terminal

A double-click on the Windows executable opens a console window that closes
before its message can be read. On macOS a reader asked for an app in
`/Applications` to keep in the Dock, installed from a DMG, with a preferences
pane that picks the Project folder. Both need a second GUI executable built from
the same source: a tray app on Windows, an app bundle on macOS. It starts and
stops a site and shows whether it runs, which needs `status`. The
`dockapp-experiment` branch of frees-au/ce builds such a bundle in
`scripts/build-local-app.sh`. It is an ad-hoc signed AppKit and WebKit wrapper
that stops its server when the app quits. A DMG downloaded in a browser carries
the quarantine mark, so it waits on Signing. Parked in favour of a clearer
command line first. The console detection and the browser open have since
removed the worst of it.

## Signing

A downloaded executable carries no signature, so Gatekeeper and SmartScreen
stop its first run. The Drupal CMS Launcher's budget pays for Apple and Azure
signing, and phenaproxima proposed moving it to Drupack.

## The musl build's cost on authenticated pages

A load study of 2026-09-22 measured two HEAD builds differing only in libc,
against one MariaDB, at 1 to 64 concurrent users. Anonymous throughput matched
within 8 percent. Authenticated throughput did not: 141 requests per second for
musl against 488 for glibc at 16 users, 166 against 568 at 64, with p95 at 526ms
against 150ms. Reversing the run order reproduced the musl figure. An anonymous
request comes from the page cache and an authenticated one renders the page, so
the split follows allocation volume. A release ships a glibc and a musl file
per Linux target, and the install script picks glibc where the host has it.
Lean: profile allocation in a rendered request before treating the number as a
property of musl, since a mallocng tuning knob or a thread count may carry it.

## SQLite as the default backend under concurrency

The same study measured SQLite at 377 requests per second with one user and 139
with 64, p95 rising from 3.3ms to 544ms. Concurrency subtracts throughput. The
same binary on MariaDB reached 5,050. A first start chooses SQLite.
Lean: keep the default, which suits the one reader a portable site serves, and
record the ceiling in the docs so a reader who needs more knows to pass
`--database`.

## A first request that hangs after the port is bound

11 of 46 warm starts and 3 of 9 cold starts served their first page request
never. Caddy bound the port at 0.29s and answered the identity route at 0.79s,
while a page request opened in that window did not return on its own
connection. A second connection answered in 39ms while the first had waited
past 100 seconds. The hung request also holds shutdown open, which is what the
10 second forced-exit deadline and the 30 second readiness client timeout
already work around. Lean: find what the first request blocks on before adding a
third timeout.

## What a start verifies before it announces readiness

A start whose database refuses the connection prints `Drupack is ready.` and
then serves 500 to every request, because Caddy answers the identity route
without reaching Drupal. The same study reached that state through a second
gap: `recordedOptions()` overwrites `db-host`, `db-port`, `db-name`, `db-user`
and `db-password` from the recorded settings, so a start given a corrected
`--db-port` keeps the stale one. Lean: decide what readiness should mean, and
whether a recorded connection detail can be corrected from the command line at
all.

## How long the registry keeps job images

Every `release.yml` run pushes the job image under its commit, so a caller of
`build.yml` finds the image of its own commit. A tag run or its candidate run
pushes `drupack-build:sha-<commit>`, and any other run `drupack-build:dev-<commit>`.
Each image is about 1.4 GB, and nothing deletes one. A tag also adds
`drupack-build:<version>`. Lean: keep every version tag, and delete `dev-` tags
older than a few weeks.

## HTTPS on a site's listener

A site serves plain HTTP. Agent Access asks for an HTTPS MCP address, and some
local agents enforce it. Hosted connectors, such as those in claude.ai, call
from their vendor's servers and cannot reach a loopback address at all. HTTPS
needs a TLS listener in the Caddyfile and certificate and key options with the
double-quote refusal. It also needs the scheme in the printed URL and a
readiness probe that speaks TLS. Three certificate sources exist:

- files the reader names, made once with `mkcert localhost 127.0.0.1 ::1`
- mkcert's root CA, which DDEV already installs, signing a leaf in Caddy
- Caddy's own CA, with a command that installs it in the trust stores

Lean: take files. Drupack then never reads a CA's private key, which can sign
for any domain the machine trusts. A Node client still needs
`NODE_EXTRA_CA_CERTS` pointing at the root.

## Whether automatic_updates still stalls cron

The `seed` operation of `application/operate.php` uninstalls `automatic_updates` because it stalled a cron request,
and `package_manager` because it cannot write into the read-only application.
Nobody has checked the stall against the current module release. Lean: keep the
uninstall, and retest the stall when the recipe's version of the module changes.

## When the demo can leave Twig 3.29

The demo's `composer.json` pins `twig/twig` to 3.29.0. Under Twig 3.30, cron
fails with a `TypeError` from `EscaperRuntime::escape()` in easy_email's compiled
body template, which Drupal CMS 2.2's site template base brings. Lean: retest
cron on each Drupal core or easy_email release, and drop the pin once it passes.

## Install scripts for sites on drupal.org's GitLab

`ci/drupack.gitlab-ci.yml` publishes a site's executables under unversioned
names in the generic package registry, and no install script. GitLab's latest
release URL takes the form `/-/releases/permalink/latest/downloads/`. Lean: run
`build/release-files.py` in the release job with the package registry URL, and
link each file into the release under its name.

## A winget package

winget installs a bare `.exe` as a `portable` package and puts it on PATH. Its
manifests live in `microsoft/winget-pkgs`, and a first package waits days for
review. Lean: submit `drupack` with `wingetcreate new` after a release with
versioned names, then update it from the release workflow.

## A Homebrew formula

A reader on macOS asked to install with Homebrew. A formula installs `drupack`
onto PATH from a release's versioned file, checked against its SHA-256. It
needs no GUI and no signing, as the install script needs none. A cask would
install the app that "A start without a terminal" covers. Lean: publish a
formula in a `homebrew-tap` repository beside `drupack`, and update it from the
release workflow, as the winget package plans.

## Whether the forge refuses a version tag pushed by a person

`upstream.yml` pushes every version tag with `gitea.token`, after a green
candidate run. The forge accepts the same push from a person, and that push
skips the candidate. A protected-tag rule on the version patterns would refuse
it. Whether `gitea.token` passes such a rule's allowlist is untested. A bot
account in its place also changes how the mirror run starts. Lean: test the
allowlist on a scratch repository before any change here.

## A cold `--help` unpacks the release

A cold `drupack --help` unpacks the whole release before it prints usage. The
usage text lives in the launcher (`launcher/help.go`), but conformance reads
`--help` as the call that unpacks a release, so the launcher prints it after the
unpack. Lean: leave it as it is, and print the help before the unpack only with
a conformance trigger that does not depend on it.

## When `drupack php` takes PHP's own options

`drupack php` refuses every PHP option but `-r`, since FrankenPHP 1.12.7's
`php-cli` reads none. PHP 8.6 lets an embedder run PHP's own command line, and
FrankenPHP's main branch calls it from `php-cli` on PHP 8.6 and later. Lean:
once the pinned FrankenPHP does, drop the refusal in the runtime's entry point
and pass every argument to `php-cli`.

## Checks only a tag build runs

A main push builds and tests Linux amd64 alone. Two shipped changes wait for the
macOS and Windows jobs of the next tag build: Node packed and run on both
platforms, and the Windows `php.cmd` alias passing spaces, quotes and `%`
unchanged. Lean: read those jobs' results before announcing the release, and
fix on main with the next patch version if one fails.

## `status`, `logs` and `restart`

A detached site answers `start` and `stop` alone. `status` would read the
Serving lease and the Listener record, `logs` would print or follow
`server.log`, and `restart` would stop and start with the same options. Lean:
add one when a reader asks for it.

## An owner-only stop record on Windows

`stop.json` is mode 0600 on Linux and macOS. On Windows it takes the access of
Site data, or of the folder's cache entry, so an account that reads Site data
reads the token and can stop the site. Drupacked Demo's OAuth key pair in
Site data `oauth-keys` and the two Mercure hub secrets take the same access. That account already reads the database
and `settings.php`. Lean: give the record a protected DACL for the current user,
as the cache root has, when a reader shares a Windows machine.

## Windows stop and a cron run in flight

On Windows a valid stop calls `caddy.Stop()` and exits, where a Unix stop runs
the Ctrl+C path. An exit mid-cron cuts that `drush cron` short. Lean: wait for
the runner to return before the exit.

## The Windows build's SourceForge downloads

vcpkg fetches pthreads from SourceForge, which on 2026-10-01 served an HTML page
in place of the zip. A run with no Windows build cache then fails. A candidate run
reads only its own cache and main's, so a release during such an outage fails on
Windows. Lean: point `X_VCPKG_ASSET_SOURCES` at our own copy of each download,
keyed by its SHA-512.

## Code size and test time

Measured on 2026-10-21 at `171ab00`. Tests hold 37% of the tracked lines,
production code 24% and docs 18%. Every CI job spends most of its time in the
conformance suite, because each case starts a real site.

The 1.0.0-beta15 candidate took 87 minutes on 2026-11-09. ADR 0018's amendment
of that day runs the macOS and Windows suites and the QA chain in parallel
jobs. An all-platform run took 42 minutes on warm caches on 2026-11-10. Still
open, keeping every feature and platform:

- a site start that dies on its static port. It failed one job in three of
  nine runs. On Linux a closed outgoing connection holds its source port for a
  minute, and the suite picked site ports whose successor was such a port.
  `harness.pick_port()` checks the successor since 2026-11-10. On Windows the
  start of run 42796056253 then failed on that successor with WSAEACCES, a
  port Hyper-V reserves from the ephemeral range. Since the same day the picker
  takes ports from 20000 to 32766, below every ephemeral range.
- the launcher's `portTaken` probe, a connect, passes on a port held that way.
  The reader then gets Caddy's bind error in place of the launcher's sentence.
- Windows arm64 shares, 16 to 22.5 minutes against 18 planned from one run's
  times
- the Windows suite under Defender, where the 4 cases of `AsciiCacheRootCases`
  take 10.6 minutes
- the FrankenPHP link, 3 minutes per run in each runtime job and macOS build
- `build.yml`'s demo job, whose "Stop containers" step takes 5.5 minutes
- two macOS test jobs of run 42782226030, which waited 4 minutes for a runner
  after both builds had ended. Seen once, no cause.
- `build/windows/build.ps1` supports an Arm64 build host. No CI job builds on
  one since the Windows arm64 jobs test the amd64 host's files.
- the `windows-arm-build-*` cache on GitHub, which no job reads any more
- `build/install-translations.php` stops the build when core's translation
  gets no answer in four attempts. A module's file is left out and counted.
  Open: whether core's file follows the same rule.

Kept as they are: the browser cases' 10-second waits for an absence, which
match the budget a real browser gets, and the QA and Linux amd64 suite runs,
which test `drupack-build`'s chain and the shipped files.

## A Composer project served in production

The question: what does a Composer project need to run in production on the
engine, installed through `composer require` and `composer install`? The engine
already serves a project folder as it is (ADR 0022), with none of a packaged
site's single-file limits. Gaps found in the tresbien.tech review on 2026-10-21:

- The README requires the package with `--dev`, so `composer install --no-dev`
  on a server leaves it out.
- `composer install` fetches no executable. `vendor/bin/drupack-install` is a
  second step, run again after each version change.
- The engine's PHP has a fixed extension set. Site extensions (ADR 0020) reach
  packaged Linux sites only, so a project that needs `ext-tidy` cannot start.
- A start detaches with no service unit, and `status`, `logs` and `restart`
  are still open.
- Off loopback, a start serves plain HTTP for any Host header. HTTPS on a public
  name needs a proxy in front, since ADR 0036 rules out ACME on demand.
- Behind a proxy, the project's own `settings.php` sets `reverse_proxy` and the
  trusted hosts.
- A project's `drupack.caddy` (ADR 0048) is read at start. Open: a reload on
  change, which Caddy's `--watch` cannot give (its watcher stops on an adapter
  error and misses a file created after the start), a hook before the guards,
  a copy in Site data an install edits without a rebuild, and a `validate`
  word that checks the snippet without a start.
- The guards refuse `drupack.caddy` by name and not `drupack.yml`, which a
  project whose docroot is its root serves as text.

## Project commands around a start

A reader asked Drupack to run a project's own commands, so serving a site needs
no terminal of its own. Three moments come from frees-au/ce and a front-end
build:

- before serving: build the site from code when its SQLite database is missing
- for the server's whole life: `vite watch`
- at stop, while the site still answers: export content to code

Drupack runs no project command today, and the watch runner supervises cron
alone. Lean: `composer.json` `extra.drupack` names one command per moment. The
watch runner starts the long-running one and stops it with the server. A command
finds the host's PATH, with Bundled Node first on a Packaged site. ADR 0027
stands: the engine carries no Node, and a developer running vite has one.

## An export that follows content changes

`SITE export` runs when a reader asks. The post ADR 0049 makes real says the
export updates whenever content changes. A timer in the watch runner was
rejected: it boots Drupal on a period with nothing to do most of the time. Lean:
a cache-tag invalidation subscriber in Drupack's `support/` layer, which the
generated settings already load, touches a marker in Site data. The watch
runner runs `export` when the marker moves, debounced. The engine never touches
a project's settings, so an engine project would add the subscriber itself.

## The Mercure hub on the static port

A site with `mercure` and `static` on serves the hub on the site port alone.
Caddy's Mercure module allows one unnamed hub per config and pools transports by
hub name and transport settings. A second `mercure` directive under the same
`name`, with an identical `bolt` block, shares the first one's transport: an
engine test on 2026-11-10 delivered a `mercure_publish()` update through such a
pair. Untested on a second listener.
Lean: the static block forwards `/.well-known/mercure*` to the site listener
with `reverse_proxy`. `resource_identifier` keeps tokens valid on both ports,
and browsers send the host-keyed cookie to both. About half a day and no Go
change. No use case today. A page exported for the static port names the hub by
a root-relative path. A dynamic endpoint on that port publishes over HTTP,
since `mercure_publish()` finds no hub on its route.

## The dev loop's first start with writable directories

`build/dev/dev-server.sh` lays `application/` over the unpacked release before a
start. A site whose contract names writable directories runs the copy the
launcher lays in Site data, so its first start runs the built release and the
second applies `application/`. Lean: leave it, as a developer restarts anyway.

## One `openBrowser` for the launcher and the runtime

`launcher/browser.go` and `runtime/entrypoint.go` each hold a 12-line
`openBrowser`. The build copies the runtime's files into FrankenPHP's package by
name, and neither module imports the other, so the two copies can drift. Lean: a
shared Go module both import holds it once, if a third caller appears.

## Windows checks that only a tag run makes

The one-owner start changed six Windows paths that no Linux job runs. Each
compiled under `GOOS=windows go vet`, and none ran.

- The lease probe in `launcher/internal/state/claim_windows.go` locks the whole
  range, as `Claim` does. A probe that misreads a PHP `flock` holder from the
  previous release shows as a `stop` that reports no server.
- The detached child's two pipes to the parent's terminal. A console that
  rejects them shows as a start that prints nothing before the ready line.
- The launcher's `openBrowser`, called by the handover.
- The hidden leading `--console-owned` argument, which marks a start from a
  file manager as console-owned.
- Ctrl+C in a console start. The launcher ignores it and waits for the runtime,
  so a start made within the server's 10-second drain finds the lease held.
- A record read in `launcher/internal/state/read_windows.go`, which shares
  deletion, so a start can replace `listener` while a `drush` call reads it.

Lean: dispatch a windows-amd64 run and read these six before tagging the
release.

## A folder's export on Windows

`drupack export` on Windows fails once a page renders a component that carries
an asset. Core's `ComponentMetadata` strips the Drupal root followed by a
backslash, while the component's path continues with `/`. The path stays
absolute, and Tome copies the asset to `../html/C:/...`. A packed site's
`settings.php` loads `WindowsPathServiceProvider`, which makes the path
root-relative. A folder's own `settings.php` loads nothing of Drupack's, and the
engine payload carries no `support/`. The conformance case
`test_a_start_serves_the_static_port_and_export_fills_it` skips its export on
Windows. Lean: the engine registers the provider for the folder it serves.

## PHP log levels on Windows

On Windows `--error-log` holds a PHP fatal error and no PHP warning, as the
engine's log case showed on 2026-11-10. Likely cause, not verified: PHP's
Windows `syslog.h` numbers `LOG_ERR` 4 and `LOG_WARNING` 5, and FrankenPHP maps
the Unix numbers, where 4 is a warning and 5 a notice. A fatal error would then
log at `WARN` and a warning at `INFO`.

If so, an engine on Windows also drops PHP's fatal errors from standard error,
whose level is `ERROR`. Lean: read the level of the fatal line in a Windows
error log, then report the mapping to FrankenPHP.

## The engine's `server.log`

A detached engine start sends the server's standard error to `server.log` in
the folder's cache entry. Two gaps remain after
[ADR 0058](adr/0058-a-start-writes-its-logs-on-request.md):

- The file never rolls, so a long-lived server grows it without bound.
- Its level is `ERROR`, so it holds PHP's fatal errors and none of its
  warnings. `--error-log` writes them to a file of its own.

Lean: leave both until a reader reports a full disk or a missed warning, since
`--error-log` covers a server that needs either.
