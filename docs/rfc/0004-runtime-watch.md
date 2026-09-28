# RFC 0004: a tested watcher beside the server

Proposed on 2026-09-28. Not implemented.

## Goal

The work that runs beside the server gets Go tests: the readiness wait, the
browser, scheduled cron and the stop deadline. Timing faults then fail in
seconds instead of in a conformance run.

## Problem

`runtime/entrypoint.go` has no test. Four copy steps put it into FrankenPHP's
own `main` package as `drupack.go`: two in the Dockerfile, one in the macOS
build script, one in the Windows one. It imports `frankenphp`, so it compiles
only there. Four functions supervise the running server:

| Function | What it does |
|---|---|
| `openWhenReady` | polls the identity route until a 204, prints the ready line, opens the browser when `OPEN` is set |
| `openBrowser` | runs the platform opener |
| `runScheduledWork` | runs `drush cron` 2 minutes after ready, then every 3 hours |
| `forceExitOnStalledShutdown` | exits 10 seconds after the first signal |

Each one reads a clock, a network or a signal. Only the conformance suite
covers them, and it needs the packed executable:

- `BrowserOpenCases` checks the browser opens on the ready line, or not at all.
- `EngineExecutable.test_a_stop_ends_a_request_that_is_still_running` checks the stop deadline.
- No case checks cron, since its first run waits 2 minutes.

A regression in the poll's handling of a 500, or a cron run that survives the
stop, shows up in production first.

## Proposed interface

A second file, `runtime/watch/watch.go`, in `package main`, importing the
standard library alone. It has its own `go.mod`, so `go test` builds it without
FrankenPHP. Each of the four copy steps gains a sibling line that copies it
next to `drupack.go` as `drupack_watch.go`. The builds never read its `go.mod`.

```go
// plan holds what run watches: where readiness answers, what to open once ready,
// and how and when cron runs.
type plan struct {
	probe     string   // the identity route's URL, token included
	open      string   // the login link to open once ready, "" for none
	cron      []string // one cron run's argv
	dir       string   // the directory cron runs in
	poll      time.Duration
	readyWithin time.Duration
	firstCron time.Duration
	cronEvery time.Duration
}

// run polls probe until a 204, prints the ready line on out, calls opener with
// open when it is set, then runs cron on plan's schedule until stopping ends.
// It returns once stopping ends and any cron child in flight has exited.
func run(stopping context.Context, site string, p plan, out, errs io.Writer, opener func(string))

// guard returns a context that ends at the first signal on signals, and calls
// exit(1) when deadline passes after that signal.
func guard(signals <-chan os.Signal, deadline time.Duration, exit func(int)) context.Context
```

`init()` in `entrypoint.go` keeps the argv dispatch. For `php-server` it builds
the plan from the environment and the constants it holds today, then calls
`guard` and `run`. For `folder-server` it calls `guard` alone.

## What it hides

- The poll loop, its per-request timeout and its deadline.
- Which answers count as ready: a 204 alone.
- The order: ready line, then browser, then the cron clock.
- The cron loop, its first delay and interval, a failed run reported and the next one kept, and ending the child at the stop.
- The stop deadline's timer.

## Dependency strategy

Local-substitutable:

- The readiness server is an `httptest.Server` with a scripted sequence of answers.
- The browser opener is a function that records its argument.
- Cron runs the test binary itself as a child, through the standard Go helper
  process variable. The tests then exercise the real child-process machinery
  on Linux, macOS and Windows alike.
- The signal channel is a plain channel the test sends on, and `exit` records its code.
- The durations are plan fields, so the tests pass milliseconds.

## Testing strategy

New tests in `runtime/watch/watch_test.go`, run by `qa.sh` and by each of the
three release jobs beside the launcher's `go test`:

- a server that answers 404 then 204 gets one ready line and one browser open;
- a server that answers 500 until the deadline gets the error line naming the 500, and no browser;
- `open` empty gets the ready line and no browser;
- cron runs once after `firstCron`, again after `cronEvery`, and never before ready;
- a failed cron run is reported on `errs` and the next one still runs;
- ending `stopping` ends a cron child in flight, and `run` returns;
- `guard` calls exit with 1 after the deadline, and not before the first signal.

| Existing test | Replacement |
|---|---|
| `BrowserOpenCases` (six cases) | kept: they prove the packed executable wires the watcher |
| `EngineExecutable.test_a_stop_ends_a_request_that_is_still_running` | kept: it proves Caddy's stop meets the deadline |

The conformance cases stay because they cover the wiring, which the Go tests
cannot see. The Go tests cover the timing underneath.

## Debug story

When the ready line never prints, cron runs during a first page, or Ctrl+C
hangs six months from now, the engineer writes a `watch_test.go` case with the
server answer or child behaviour production saw, and watches it fail.

## Considered options

Four designs competed, each under one constraint:

- Flexibility: a `Supervisor` with a task list and a callback-built readiness,
  tested with `testing/synctest`. The task list serves jobs nobody has named,
  and cron as a callback leaves the real child-process wiring untested.
- The common caller: one `Spec` struct of thirteen fields with `start()`, built
  by two constructors in `entrypoint.go`. Each call site becomes one line, and
  the two constructors holding the real wiring stay untested.
- No split: a test module with a FrankenPHP stub that copies `entrypoint.go`
  and tests it in place. In the test binary the copied `init()` runs too, and
  it rewrites `os.Args` for any first argument starting with `-`, which every
  `go test` flag does.
- Minimal interface: the design above.

Recommendation: `run`, `guard` and `plan`.

## Consequences

- The four copy steps copy one more file.
- `qa.sh` and the three release jobs run one more `go test`.
- `entrypoint.go` keeps `init()`, the usage text, `release()`, `openBrowser` and `canonical()`.
- The first cron run gets a test for the first time.
