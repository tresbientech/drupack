# RFC 0004: a tested watcher beside the server

Proposed on 2026-09-28. Not implemented.

## Goal

The work that runs beside the server gets Go tests: the readiness wait, the
browser, scheduled cron and the stop deadline. Timing faults then fail in
seconds instead of in a conformance run.

## Problem

`runtime/entrypoint.go` holds 295 lines and no test. Every build copies it into
FrankenPHP's own `main` package as `drupack.go`, so it imports `frankenphp` and
compiles only there. Four functions supervise the running server:

| Function | What it does |
|---|---|
| `openWhenReady` | polls the identity route until a 204, prints the ready line, opens the browser |
| `openBrowser` | runs the platform opener |
| `runScheduledWork` | runs `drush cron` after 2 minutes, then every 3 hours |
| `forceExitOnStalledShutdown` | exits 10 seconds after the first signal |

Each one reads a clock, a network or a signal. Only the conformance suite
covers them, and it needs the packed executable:

- `BrowserOpenCases` checks the browser opens on the ready line, or not at all.
- `EngineExecutable.test_a_stop_ends_a_request_that_is_still_running` checks the stop deadline.
- No case checks cron: its first run waits 2 minutes.

A regression in the poll's handling of a 500, or a cron run that survives the
stop, shows up in production first.

## Proposed interface

A second file, `runtime/watch/watch.go`, in `package main` with its own
`go.mod`. It imports no FrankenPHP code. The three builds copy it next to
`drupack.go`, as they copy `entrypoint.go` today.

```go
// watch runs beside the server for its whole life.
type watch struct {
	probe   string   // the identity route's URL, token included
	open    string   // the login link to open once ready, "" for none
	cron    []string // one cron run's argv, nil for the engine's folder server
	timing  timing
}

type timing struct {
	poll, readyWithin, firstCron, cronEvery time.Duration
}

// run announces readiness on out, opens the browser through opener, and runs
// cron until stopping ends. It returns once stopping ends.
func (w watch) run(stopping context.Context, out, errs io.Writer, opener func(string))

// stopDeadline ends the returned context at the first signal, and calls exit
// if deadline passes after it.
func stopDeadline(signals <-chan os.Signal, deadline time.Duration, exit func(int)) context.Context
```

`init()` in `entrypoint.go` builds a `watch` from the environment and the
constants it holds today, then calls `run` and `stopDeadline`. The argv
dispatch stays in `init()`.

## What it hides

- The poll loop, its per-request timeout and its deadline.
- Which answers count as ready: a 204 alone.
- The order: ready line, then browser, then the cron clock.
- The cron loop, its first delay and its interval, and ending the child at the stop.
- The stop deadline's timer.

## Dependency strategy

Local-substitutable:

- The readiness server is an `httptest.Server`.
- The browser opener is a function that records its argument.
- Cron runs the test binary itself as a child, with the standard Go helper
  process variable, so the tests run on Linux, macOS and Windows alike.
- `exit` is a function that records its code.

The tests pass short durations through `timing`, so none waits minutes.

## Testing strategy

New tests, `runtime/watch/watch_test.go`, run by `qa.sh` and by the release
workflow on each platform:

- a server that answers 404 then 204 gets one ready line and one browser open;
- a server that answers 500 until the deadline gets the error line naming the 500, and no browser;
- `open` empty gets the ready line and no browser;
- cron runs once after `firstCron`, again after `cronEvery`, and never before ready;
- a failed cron run is reported and the next one still runs;
- ending `stopping` kills a cron child in flight;
- `stopDeadline` calls exit after the deadline, and not before the first signal.

| Existing test | Replacement |
|---|---|
| `BrowserOpenCases` (six cases) | kept: they prove the packed executable wires the watcher |
| `EngineExecutable.test_a_stop_ends_a_request_that_is_still_running` | kept: it proves Caddy's stop meets the deadline |

The conformance cases stay because they cover the wiring, which the Go tests
cannot see. The Go tests cover the timing underneath.

## Debug story

When the ready line or a cron run misbehaves six months from now, the engineer
writes a `watch_test.go` case with the server answer or child exit that
production saw, and watches it fail.

## Considered options

- Tests against `entrypoint.go` as it stands. They cannot compile outside
  FrankenPHP's module, so each would need a FrankenPHP checkout.
- One `supervise()` that also dispatches argv. It adds the command routing to
  the four concerns above. The routing stays in `init()`, covered by the
  conformance cases for each command.

Recommendation: the `watch` type and `stopDeadline` above.

## Consequences

- The three build scripts copy one more file.
- `entrypoint.go` keeps `init()`, the usage text, `release()` and `canonical()`.
- The first cron run gets a test for the first time.
