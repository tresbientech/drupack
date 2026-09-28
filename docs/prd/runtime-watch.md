# PRD: a tested watcher beside the server

Source: [RFC 0004](../rfc/0004-runtime-watch.md), design settled on 2026-09-28.

## Problem Statement

A maintainer who changes how the runtime waits for readiness, runs cron or
stops has no fast test. The code compiles only inside FrankenPHP's module, so
the only check is a conformance run against a packed executable. Cron has no
check at all, since its first run waits two minutes.

A reader meets the failures directly: a start that never prints its ready line,
a first page slowed by a cron run, or a Ctrl+C that hangs.

## Solution

The readiness wait, the browser, scheduled cron and the stop deadline move into
a file of their own that imports only the standard library, with its own Go
tests. The builds copy it beside the entry point. The entry point keeps its
command dispatch and hands the watcher a plan built from the environment.

A reader sees no change.

## User Stories

1. As a maintainer, I want the watcher to compile without FrankenPHP, so that its tests run with a plain `go test`.
2. As a maintainer, I want one call that runs readiness, the browser and cron from a plan, so that the entry point only builds the plan.
3. As a maintainer, I want one call that owns the stop deadline, so that both server commands share it.
4. As a maintainer, I want the durations carried by the plan, so that tests run in milliseconds.
5. As a maintainer, I want a test where the server answers 404 then 204, so that the ready line and the browser are checked to happen once.
6. As a maintainer, I want a test where the server answers 500 until the deadline, so that the error line names the 500 and no browser opens.
7. As a maintainer, I want a test with no link to open, so that the ready line prints and no browser opens.
8. As a maintainer, I want a test that cron runs after its first delay and again after its interval, never before ready, so that a first page never shares the database with it.
9. As a maintainer, I want a test that a failed cron run is reported and the next still runs, so that search and queue work recover.
10. As a maintainer, I want a test that the stop ends a cron child in flight, so that Ctrl+C never waits on cron.
11. As a maintainer, I want a test that the deadline forces an exit only after the first signal and the deadline, so that a slow stop ends and a healthy one is untouched.
12. As a maintainer, I want the cron tests to run a real child process, so that the process machinery production uses is what the tests exercise.
13. As a maintainer, I want the tests to run on Linux, macOS and Windows in the release jobs, so that platform differences in signals and processes show up.
14. As a maintainer, I want the tests in the QA chain, so that a local run catches a regression before a push.
15. As a maintainer, I want the conformance cases for the browser and the stop kept, so that the wiring inside the packed executable stays proven.
16. As a reader, I want every start to print its ready line, open my browser and stop as before, so that nothing I rely on changes.

## Implementation Decisions

- A new Go file in its own directory, in the `main` package, imports only the standard library, and has its own module file for tests alone.
- Each of the four copy steps that place the entry point into FrankenPHP's module gains a sibling line for the new file.
- The plan holds the probe URL, the link to open, cron's command line and four durations: poll interval, readiness deadline, first cron delay, cron interval.
- `run` takes the stopping context, the site name, the plan, the two output writers and the browser opener. It returns once stopping ends and any cron child has exited.
- `guard` takes the signal channel, the deadline and the exit function, and returns the stopping context.
- The entry point builds the signal channel, the plan and the real opener, and passes the constants it holds today.
- The folder server calls `guard` alone.
- Readiness keeps its rules: a 204 alone counts, each request has a 30-second timeout, and the ready line text stays the same.

## Testing Decisions

- A good test hands the watcher a server, a plan, a signal channel, an opener and an exit function, and checks what it printed, opened, ran and exited with.
- The readiness server is an `httptest` server with a scripted answer sequence.
- The cron child is the test binary itself, run through the standard helper-process variable, recording each run to a file the test reads.
- The durations are milliseconds.
- The QA chain and the three release jobs run the new module's tests beside the launcher's.
- The conformance suite runs unchanged and must pass whole.
- Prior art: the launcher's Go tests, which use `httptest` and temporary directories.

## Out of Scope

- The command dispatch in `init()`, which the conformance suite covers per command.
- The platform browser opener itself, which the browser cases cover.
- New scheduled work.
- The environment contract, already settled by RFC 0003.

## Further Notes

The two conformance cases that cover the browser and the stop stay as they are.
They prove the packed executable wires the watcher; the new tests prove its timing.
