# One function builds a child's environment

Accepted on 2026-11-09. Amends [ADR 0050](0050-the-launcher-owns-a-start.md).

## Context

A start wrote its children's environment in four files. Each start held a
mutable list and passed it down, and nine functions took it as a parameter.
What a child received depended on which `put` and `drop` calls had run before
its launch. `runtime/environment.txt` declared the `DRUPACK_RUNTIME_*` names
alone, and no test tied a name to the children that receive it.

## Decision

- `Facts` holds what a start knows: the launch, the typed inputs, the Site
  data paths, the served address, the CA bundle, the hub, the application and
  the server's own values. A start fills it in the order it learns things.
- `Facts.Env(Kind)` is pure and is the launcher's one writer. Its five kinds
  are `Reader`, `Server`, `Operate`, `Drush` and `Export`. An unset field
  leaves the inherited variable as it is.
- Each launch calls `Env` on the value it holds then. File work stays in the
  callers: `storeBundle` returns the bundle's path, and `servedHub` returns the
  hub's secrets.
- `runtime/environment.txt` declares every `DRUPACK_*` and `DRUSH_*` name `Env`
  sets, with the kinds and modes that receive it. The typed start options stay
  listed in `startInputs`.
- `TestEnvDeclared` holds `Env` to that file. `TestEnvKinds` gives a `Facts`
  and reads the environment of each kind in both modes.

## Consequences

- A new variable is one line in `Env` and one line in the file, and a test
  fails when either is missing.
- A child's environment is read from one function, with no call order to
  follow.
- An export's second Drush call keeps the reader's `PATH`. The two calls
  shared one list before, and the second lost the inherited value.
- The login mint still appends `COLUMNS=1000` to the environment it is handed.
- An inherited `DRUPACK_ADMIN_PASSWORD` still reaches Drush and export.
