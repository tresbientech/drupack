# One conformance suite for every platform

Proposed on 2026-09-19.

## The question

Why do the test suites break more often than the product they test?

Between 0.1.3 and 0.1.5, six releases-worth of debugging went into the suites
rather than into Drupack. Every failure traced to the same four properties of
the test layer, so this proposes changing those properties.

## What the suites do today

- Nine suites in three languages: bash (7 files, 1,136 lines), Python (1),
  PowerShell (2).
- Six of the nine unpack into `~/.cache/Drupack`, the developer's own cache.
  Only `launcher.sh`, `launcher.Tests.ps1` and `site.Tests.ps1` set a cache of
  their own.
- Two bind port 7225, the machine-global default. The rest pick a private port.
- Readiness has three definitions: grep a shared `server.log`, `curl
  /user/login`, `Invoke-WebRequest`. None names the process that answered.
- `offline.sh` runs `browser.py` in a container, then the chain runs the same
  seven cases again on the host.
- Timeouts are restated per layer with no stated relationship: runtime poll
  120s, harness wait 120s, cache lock 60s, Windows wait 240s, Windows job 90m.

## What that cost

| date | failure | cause in the test layer |
|---|---|---|
| 0.1.3, 0.1.4 | macOS arm64 timed out twice per run | harness wait equalled the runtime's own 120s deadline, so a slow start lost by one second |
| 0.1.4 | Windows spent its whole 90 minute budget in one step | the check expected a refusal that 0.1.3 removed, so the start served forever |
| QA chain | later suites answered from the wrong site | `launcher.sh` case 0 captured a subshell's pid, leaking a server on 7225 every run |
| convergence | two Windows rounds | one case asserted a directory rename Windows never allows; another demanded empty stderr where the design writes a notice |

A failure in this table is invisible on the platform a developer can run. The
two Windows cases had never executed against the product they asserted.

## Decision

1. One conformance suite, in Python, defines every product behaviour and runs
   on Linux, macOS and Windows. Platform-specific cases are marked cases inside
   it. The bash and PowerShell suites go once their cases are ported.
2. One launcher cache per suite run, in a temporary directory. A run never
   reads or writes `~/.cache/Drupack`. A case that needs an empty or poisoned
   cache asks for its own.
3. One port per case, taken from the operating system. Exactly one case asserts
   the default port, owns 7225 and runs alone.
4. Readiness polls the port the case was given. The readiness line stays a
   product behaviour, asserted in the case about terminal output.
5. A `workflow_dispatch` input names one platform and runs build plus tests for
   that job alone.
6. The offline proof keeps `--network none` and moves to a small Python image.
   `browser.py` contains no selenium call, so the chromium image supplies only
   an interpreter, and its `--shm-size 2g` is what the memory watchdog kills.
7. Every wait exceeds the deadline below it, and the table of both lives in the
   suite. A wait equal to a deadline is the defect that failed two releases.

## Consequences

- A Windows case sits beside its Linux equivalent, so a reviewer sees both.
  Execution stays in CI, and review stops depending on memory of the platform.
- A Windows test fix costs about five minutes: 4m3s to build with a warm cache,
  35s to run the suite. The five-platform matrix stays the gate before a merge
  and a release.
- A local run stops touching the developer's cache and stops leaving servers on
  the default port.
- The chain stops running the same seven cases twice, which returns about two
  minutes per run.
- Porting 1,136 lines of bash and two PowerShell files is the cost. The suites
  keep working until each case moves.

## Considered options

- Keep three languages and share a data file of expectations. Cheaper to start.
  Three implementations still drift in everything the file does not cover, which
  is where both Windows failures happened.
- Isolate the cache per case. Hermetic, and an unpack costs up to 120s on the
  macOS arm64 runner, so the slowest platform becomes slower still.
- Never bind 7225. Removes the collision and stops proving that a plain start
  serves, which is the product's first promise.
- Test only the platform that changed. Fast, and a shared file changes behaviour
  on platforms the dispatch skipped.

## Sequence

1. Isolation inside the existing Python suite: a cache per run, a port per case,
   readiness on that port. Fixes the leaks without moving a case.
2. The test-only dispatch and the slim offline image. Both stand alone and make
   every later step cheaper to verify.
3. Port the bash cases, suite by suite, deleting each file as its cases land.
4. Port the PowerShell cases last, since each one needs a CI round to confirm.
5. Remove the duplicate host run of `browser.py` once the container run covers
   it.

## Open question

Windows and macOS cases cannot execute on the development machine. This
proposal makes them reviewable and cheap to run in CI. It does not make them
runnable locally, and no option here changes that.
