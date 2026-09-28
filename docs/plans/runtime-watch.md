# Plan: a tested watcher beside the server

> Source PRD: [runtime-watch](../prd/runtime-watch.md), from
> [RFC 0004](../rfc/0004-runtime-watch.md).

## Architectural decisions

- The watcher lives in its own directory as a `main`-package file that imports
  only the standard library. Its module file serves tests alone.
- Every build that copies the entry point into FrankenPHP's module copies the
  watcher beside it.
- The entry point keeps the command dispatch. It builds the plan and the signal
  channel, and passes the real opener and exit.
- The QA chain and each release job run the watcher's tests beside the launcher's.

---

## Phase 1: the stop deadline

**User stories**: 1, 3, 11, 13, 14, 15, 16

### What to build

The watcher file with `guard`, its tests, both server commands calling it, the
four copy steps, and the test step in the QA chain and the three release jobs.

### Acceptance criteria

- [ ] `(cd runtime/watch && go test ./...)` passes, with cases for exit after the deadline and none before the first signal.
- [ ] `grep -c 'runtime/watch/watch.go' Dockerfile build/macos/build.sh build/windows/build.ps1` prints 2, 1 and 1.
- [ ] `grep -c 'runtime/watch' build/qa.sh` prints 1, and `grep -c 'working-directory: runtime/watch' .github/workflows/release.yml` prints 3.
- [ ] `grep -n forceExitOnStalledShutdown runtime/entrypoint.go` prints nothing.
- [ ] `bash build/qa.sh` exits 0.

---

## Phase 2: readiness, browser and cron

**User stories**: 2, 4, 5, 6, 7, 8, 9, 10, 12

### What to build

`run` and `plan`, their tests with a scripted server and a real helper child,
and the site server command building the plan from the environment.

### Acceptance criteria

- [ ] `(cd runtime/watch && go test ./...)` passes, with the readiness, browser and cron cases the PRD lists.
- [ ] `grep -nE 'openWhenReady|runScheduledWork' runtime/entrypoint.go` prints nothing.
- [ ] `bash build/qa.sh` exits 0.
