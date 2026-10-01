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
`options()` the accepted set per mode, so an option `drush` cannot use raises the
unknown-argument error it already has for an unknown word. `docs/cli.md`
records the current behaviour until then.

## Container lifecycle in the conformance suite

`harness.DatabaseServer` now owns the database lifecycle both database modules
shared. The suite still drives Docker through 14 raw `subprocess.run` calls in
three modules: the network cases, the offline case and one launcher case. A
maintained package would replace them, at the cost of the first Python
dependency in a repo that has none. Lean: judge the dependency against those 14
calls.

## A Windows start without a terminal

A double-click on the Windows executable opens a console window that closes
before its message can be read. A tray app would run a site in the background
and show a notification icon, which needs a second GUI executable built from the
same source. Parked in favour of a clearer command line first. The console
detection and the browser open have since removed the worst of it.

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
`build.yml` finds the image of its own commit. A tag run pushes
`drupack-build:sha-<commit>`, and any other run `drupack-build:dev-<commit>`.
Each image is about 1.4 GB, and nothing deletes one. A tag also adds
`drupack-build:<version>`. Lean: keep every version tag, and delete `dev-` tags
older than a few weeks.

## Whether Mercury still needs its MCP packages

The engine no longer enables `mcp_tools`, but Mercury's `composer.json` still
requires `drupal/mcp_tools` and `drupal/mcp_server`. They ship in every Mercury
executable. Lean: drop both from the example unless its recipe enables them,
which is Mercury's decision rather than the engine's.

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

`build/seed.sh` uninstalls `automatic_updates` because it stalled a cron request,
and `package_manager` because it cannot write into the read-only application.
Nobody has checked the stall against the current module release. Lean: keep the
uninstall, and retest the stall when the recipe's version of the module changes.

## When Mercury can leave Twig 3.29

Mercury's `composer.json` pins `twig/twig` to 3.29.0. Under Twig 3.30, cron
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

## Site builds for macOS and Windows

The site workflow builds Linux targets only. Drupack's own release already
ships Mercury Demo for all five targets, so only a third-party site misses
macOS and Windows. `docs/prd/native-site-builds.md` holds the design: engine
releases publish their runtimes, and native jobs pack them with the site's
payload. It adds code in both workflows, the release step, `drupack-build` and
a new pack script, and removes none. Lean: wait for a site owner who needs
those targets.

## A cold `--help` unpacks the release

A cold `drupack --help` unpacks the whole release before it prints usage.
argv reaches the entry point only after preparation, and moving the decision
into the launcher puts the option contract in a fourth place, against
[ADR 0014](adr/0014-the-parser-owns-the-command-line.md). Lean: leave it as it
is.

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
reads the token and can stop the site. That account already reads the database
and `settings.php`. Lean: give the record a protected DACL for the current user,
as the cache root has, when a reader shares a Windows machine.

## Windows stop and a cron run in flight

On Windows a valid stop calls `caddy.Stop()` and exits, where a Unix stop runs
the Ctrl+C path. An exit mid-cron cuts that `drush cron` short. Lean: wait for
the runner to return before the exit.

## The Windows build's SourceForge downloads

vcpkg fetches pthreads from SourceForge, which on 2026-10-01 served an HTML page
in place of the zip. A run with no Windows build cache then fails. A tag run reads
only its own cache and main's, so a release during such an outage fails on
Windows. Lean: point `X_VCPKG_ASSET_SOURCES` at our own copy of each download,
keyed by its SHA-512.
