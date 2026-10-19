# The launcher plans the runtime's command

Accepted on 2026-10-20. Amends [ADR 0030](0030-the-launcher-routes-the-readers-words.md):
`Plan` routes the reader's words.

## Context

The launcher built the runtime's environment in three places. `run()` set and
unset `DRUPACK_RUNTIME_*` variables with `os.Setenv`. `prepareNode` edited
`PATH`. `runtime.Environment` rebuilt the slice from `os.Environ()`. Two test
files covered the routing and the last step. No test covered an engine start,
an inherited application directory or a stale Node release.

## Decision

- `Plan(Launch) Command` in `launcher/plan.go` returns the runtime's path, argv
  and environment. It reads nothing outside its argument.
- `run()` unpacks the runtime and Node, fills `Launch`, and hands the command to
  the platform's `launch`.
- A variable the launcher owns replaces an inherited one. A site drops an
  inherited `DRUPACK_RUNTIME_CACHE_ROOT`, as the engine drops
  `DRUPACK_RUNTIME_APP_DIR`.
- Variable names match in any case on Windows, and exactly on Unix.

## Consequences

- Two table tests, one over the arguments and one over the environment, cover
  each kind of start.
- The launcher no longer changes its own environment.
