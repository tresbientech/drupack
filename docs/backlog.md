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

## The options `dr` accepts and ignores

`dr` parses all 13 launch options before the Drush command and acts on three:
`--data-dir`, `--listen` and `--host`. It consumes the other ten and drops
them, so `dr --admin-password x user:login` spends a secret for nothing and
`dr --database bogus status` exits 1 over a value it never reads. Lean: give
`options()` the accepted set per mode, so an option `dr` cannot use raises the
unknown-argument error it already has for an unknown word. `docs/cli.md`
records the current behaviour until then.

## The variables a start passes between its own processes

A start is four processes: launcher, runtime, `launch.php`, then the server.
Thirteen `DRUPACK_RUNTIME_*` variables carry state across those hops, written
and read in five languages, declared nowhere. Lean: one table in `CONTEXT.md`
naming each variable, its writer and its readers, checked by a test that greps
both sides.

## An owner for the Site data layout

Ten file names describe a Site data directory. `launch.php` spells them inline
at 18 places and the conformance suite spells them again 33 times. Four have a
helper, six are interpolated. Lean: give the remaining six helpers and keep the
list in one place, without building a Site data class around it.

## A case class that runs nowhere

`PLATFORMS` defaults to empty, so a case class that declares none, or misspells
a constant, skips on every platform while the suite stays green. A class that
forgets to call the base `setUpClass` skips its platform and tool gate as well.
Lean: make both shapes fail the run rather than skip it.

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
the split follows allocation volume. musl is the only libc a release ships.
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

Every `release.yml` run pushes the job image as `drupack-build:sha-<commit>`, so
a branch or `main` caller of `build.yml` finds the image of its own commit. Each
image is about 1.4 GB, and nothing deletes one. A tag also adds
`drupack-build:<version>`. Lean: keep every version tag, and delete `sha-` tags
older than a few weeks that no version tag points at.

## Whether Mercury still needs its MCP packages

The engine no longer enables `mcp_tools`, but Mercury's `composer.json` still
requires `drupal/mcp_tools` and `drupal/mcp_server`. They ship in every Mercury
executable. Lean: drop both from the example unless its recipe enables them,
which is Mercury's decision rather than the engine's.

## Whether automatic_updates still stalls cron

`build/seed.sh` uninstalls `automatic_updates` because it stalled a cron request,
and `package_manager` because it cannot write into the read-only application.
Nobody has checked the stall against the current module release. Lean: keep the
uninstall, and retest the stall when the recipe's version of the module changes.
