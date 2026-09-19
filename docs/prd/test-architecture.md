# One conformance suite for every platform

From [docs/rfc/test-architecture.md](../rfc/test-architecture.md).

## Problem Statement

Nine suites in three languages test Drupack: seven bash scripts, one Python file
and two PowerShell files. Between 0.1.3 and 0.1.5, most debugging time went into
these suites instead of the product.

Every failure traced to four properties of the test layer:

- Six suites unpack into the developer's own `~/.cache/Drupack`.
- Every server case in `browser.py` and the first case of `launcher.sh` bind
  the default port 7225. A leaked server then answers for a later suite.
- Readiness has three definitions, and none names the process that answered.
- Harness waits restate product deadlines with no stated relation. A wait equal
  to the runtime's 120s readiness deadline failed macOS arm64 twice.

A Windows case runs only in CI, and its Linux twin lives in another language.
Two Windows cases asserted behaviour the product never had, and no reviewer
could see it. `launcher.sh` and `initialization.sh` run on no CI platform.

## Solution

A maintainer runs one command on any platform, given a built executable and a
results directory. The suite:

- unpacks the executable once, into a temporary cache it deletes at exit
- gives each case a port the operating system picked, except one case that
  owns 7225
- waits for each start by polling that case's own port
- bounds every wait above the product deadline it covers, from one table

On Linux, the suite runs the site cases inside a container with no network and
skips them on the host. On macOS and Windows they run on the host.

Each case names the platforms it runs on. A Windows case sits beside its Linux
twin, often as one case. CI runs the same command on all five targets. A
`workflow_dispatch` input runs one target alone, so a Windows test fix costs one
short CI round.

## User Stories

1. As a maintainer, I want one command to run every case for my platform, so
   that I never assemble a chain of scripts.
2. As a maintainer, I want a suite run to leave `~/.cache/Drupack` untouched, so
   that my own sites keep their runtime.
3. As a maintainer, I want a suite run to leave no server on 7225, so that my
   next run and my own site start cleanly.
4. As a maintainer, I want each case on its own port, so that a leaked server
   fails its own case and no other.
5. As a maintainer, I want readiness to poll the port the case started on, so
   that no other process can answer for it.
6. As a maintainer, I want exactly one case to prove a plain start serves on
   7225, so that the product's first promise stays tested.
7. As a maintainer, I want that case to name 7225 when the port is taken, so
   that my running site reads as a port conflict.
8. As a maintainer, I want the readiness lines asserted in the case about
   terminal output, so that readiness stays a product behaviour.
9. As a maintainer, I want every harness wait to exceed the product deadline it
   covers, so that a slow start never loses by one second.
10. As a maintainer, I want the waits and deadlines in one table, so that a
    changed deadline has one place to update.
11. As a maintainer, I want a refusal case whose start serves to fail within a
    bound, so that it never spends a CI job's budget.
12. As a maintainer, I want the suite in one language, so that a behaviour is
    written once.
13. As a reviewer, I want a Windows case beside its Linux twin, so that I can
    compare them without running Windows.
14. As a reviewer, I want each case to name its platforms, so that I see where
    it runs without reading the workflow.
15. As a maintainer, I want a case needing Go or Docker to fail without the
    tool, so that lost coverage never passes as a skip.
16. As a maintainer, I want a case that empties or poisons a cache to get its
    own, so that the run's cache stays intact.
17. As a maintainer, I want one unpack per run, so that the slow macOS arm64
    runner pays it once.
18. As a maintainer, I want containers to use the run's cache, so that each
    container start skips the unpack.
19. As a maintainer, I want the offline proof to keep `--network none`, so that
    the site still proves it needs no network.
20. As a maintainer, I want the offline proof in a small Python image, so that
    it drops the 2 GB shared memory the watchdog kills.
21. As a maintainer, I want the site cases to run once per Linux run, so that
    the chain stops repeating seven cases on the host.
22. As a maintainer, I want to select cases by name, so that I rerun one failure
    in seconds.
23. As a maintainer, I want each case's logs in its own results directory, so
    that a failure's output is found without searching.
24. As a maintainer, I want CI to upload the results directory on failure, so
    that I read the logs of a run I cannot reproduce.
25. As a maintainer, I want a dispatch input that runs one target's build and
    tests, so that a Windows fix costs minutes.
26. As a maintainer, I want a dispatched Windows or macOS run to build its own
    application archive, so that it tests the dispatched commit.
27. As a release manager, I want a version tag to run all five targets, so that
    the matrix stays the gate before a release.
28. As a maintainer, I want the harness tested apart from the executable, so
    that a harness defect shows in seconds.
29. As a maintainer, I want each old suite deleted once its cases land, so that
    no behaviour lives in two places.
30. As a maintainer, I want the old suites to keep working until their cases
    move, so that every phase ends on a green suite.
31. As a maintainer, I want CONTRIBUTING to name the one command, so that a new
    contributor runs the right thing.
32. As a maintainer, I want the `launcher.sh` and `initialization.sh` cases in
    Linux CI, so that a regression there stops a release.

## Implementation Decisions

### Shape

- Standard library `unittest`, Python 3.10 or later, no third-party package.
  The development machine has 3.10.
- The suite is a directory `python3` runs through its main module. It takes the
  executable and a results directory. Remaining arguments go to `unittest`, so
  `-k` selects cases.
- Case modules follow product areas: argument checks, the site, the launcher,
  initialization, replacement, server databases, the network listener and the
  offline run.

### Harness

The harness is the one deep module. It owns every platform difference in
process control.

- Run cache: a private temporary directory, exported as `DRUPACK_CACHE_DIR` to
  every subprocess and mounted into every container. Removed at exit.
- Private cache: a fresh directory, for a case that poisons or empties one.
- Port: bind `127.0.0.1:0`, read the port, close the socket, pass `--listen`.
- Site: start the executable on a data directory and a port. Wait for HTTP 200
  on `/user/login` at that port while the process lives. Its log lands in the
  case's results directory.
- Stop: on Linux and macOS, SIGTERM to the process group. On Windows, kill the
  process tree. A process outliving its stop wait is killed and fails the case.
- Fixture launcher: the packer wraps a Go stub runtime under the
  platform's entry name. The stub prints its arguments, one environment value
  and `PHPRC`. A corrupted variant flips one hex digit of the embedded checksum.
- Marks: a case names its platforms and the tools it needs. A missing tool on a
  named platform fails the case.

### Wait table

The table lists each harness wait beside the product deadline it covers. A wait
is that deadline plus a margin, so each deadline is written once. A wait with no
product deadline below it is a stated budget.

Product deadlines today:

- readiness poll: 2 minutes, each request bounded at 30s
- shutdown after a signal: 10s
- Windows cache lock: 60s

Budgets with no product deadline: a server-database first start, which runs
`site:install`, and a start expected to refuse.

### Default port

The case for a first start without credentials owns 7225. It fails naming the
port when 7225 already accepts connections. The `launcher.sh` case 0 and the
`browser.py` working-directory case assert one behaviour and merge into one
case on a picked port.

### Offline run

- On the Linux host the site cases are skipped. One Linux case runs them in a
  pinned slim Python image under `--network none`, as the host user.
- That container mounts the suite read-only, the executable, the results
  directory and the run cache.
- The inner run sees an environment value the offline case sets. It runs the
  site cases and skips the offline case.
- The chromium image, its `--shm-size 2g` and the wrapper script go.

### Platform coverage

A ported case runs where its source suite runs today:

- `browser.py`, `database-init.sh` and `replacement.sh` cases: Linux and macOS
- `site.Tests.ps1` cases: Windows, merged into their site twins
- `launcher.Tests.ps1` cases: Windows, merged into their `launcher.sh` twins
- other `launcher.sh`, `initialization.sh`, `network.sh` and
  `server-database.sh` cases: Linux

A Linux case and its Windows twin merge into one case when both assert the same
behaviour. That case runs on both platforms.

### Release workflow

- Each job's test steps become one suite command. The Linux job gains Go. The
  musl start and the quarantine check stay as they are.
- A new job exports the application archive from the `build` stage. Windows and
  macOS depend on it instead of the Linux job, which stops exporting it.
- `workflow_dispatch` takes a `platform` input: `all`, or one of the five
  targets. A job runs for `all`, for a tag push, or for its own target. The
  archive job runs whenever Windows or macOS runs.
- `publish` still runs on tags only.

### Removed

Each port deletes the bash file, its CONTRIBUTING lines, its workflow step and
its part of the QA command. The macOS `coreutils` install goes with
`database-init.sh`, since only its `timeout` call needed it. The last phases
delete `browser.py`, `offline.sh`, both PowerShell suites and every selenium
image reference.

## Testing Decisions

A good case drives the executable the way a user does. It asserts what a user
observes: exit codes, terminal output, HTTP responses and files in Site data.
Launcher cases also read the cache layout the launcher documents: `active`,
`manifest.json` and staging directories.

The harness has unit tests that need no executable and run in seconds:

- every wait in the table exceeds its product deadline
- the port picker returns a port a listener can bind
- a process that outlives its stop wait is killed, and the stop reports it

A port keeps every assertion of its source file. Each port phase's audit lists
the assertions of the deleted file and the case that now holds each one.

A dispatched Windows run verifies Windows cases. A dispatched macOS run verifies
macOS cases.

Prior art:

- `browser.py`: `unittest` cases driving the executable through `subprocess`
- `launcher.sh` and `launcher.Tests.ps1`: fixture launchers from the packer
- the launcher's Go tests: the cache states a case can build

## Out of Scope

- Widening a case to a platform its source suite does not cover today
- Running Windows or macOS cases on the development machine
- The musl start and the macOS quarantine check in the release workflow
- The launcher's Go unit tests, which stay in Go
- Reading product deadlines from source to catch drift
- Running cases in parallel
- Product changes: a port that exposes a product defect records it as a
  follow-up

## Further Notes

Corrections to the RFC's inventory, checked on 2026-09-19:

- The seven bash files hold 1,155 lines. The RFC's 1,136 leaves out
  `offline.sh`.
- All six server cases in `browser.py` bind 7225, as does `launcher.sh` case 0.
- CI runs `launcher.sh` and `initialization.sh` on no platform. Only the local
  QA command runs them.
- The Unix cache lock waits without a bound. Only Windows bounds it, at 60s.

Moving the Linux job onto the whole suite adds the `launcher.sh` and
`initialization.sh` cases to each Linux run, including a PostgreSQL container.
