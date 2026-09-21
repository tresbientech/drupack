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

## One place for the command line

Drupack accepts 13 options. The parser in `runtime/launch.php` holds them with
their defaults, the usage text in `packaging/entrypoint.go` describes six, and
the README describes its own set. The three have already drifted. Lean: keep the
parser as the contract and assert the usage text against it in a test, rather
than generating one from the other.

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

The suite drives Docker through 28 raw `subprocess.run` calls across five
modules, and two modules carry the same database lifecycle line for line. A
maintained package would replace it, at the cost of the first Python dependency
in a repo that has none. Lean: consolidate the lifecycle into one harness helper
first, then judge the dependency against what is left.

## Runtime footprint and the extension allowlist

The build bundles PHP extensions with no recorded consumer list. Earlier
experiments measured roughly 5 to 6 percent compressed size for extension
reduction, which does not establish savings for the current release. Lean:
record what each extension serves before removing any.

## A Windows start without a terminal

A double-click on the Windows executable opens a console window that closes
before its message can be read. A tray app would run a site in the background
and show a notification icon, which needs a second GUI executable built from the
same source. Parked in favour of a clearer command line first. The console
detection and the browser open have since removed the worst of it.
