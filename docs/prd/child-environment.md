# One function builds a child's environment

## Problem Statement

A maintainer who asks "what environment does Drush receive" reads four files.
About 35 `put` and `drop` calls in `plan.go`, `sitestart.go`, `start.go` and
`drush.go` build the environment of five kinds of child. The `PATH` and
`DRUPACK_DRUSH_SCRIPT` pair is written three times. `runtime/environment.txt`
declares the 15 `DRUPACK_RUNTIME_*` names, and the other names the launcher sets
are declared nowhere. `TestServerEnvironment` covers one helper, and the function
that adds the remaining variables has no test.

## Solution

A start collects what it knows in one value. One pure function turns that value
into the environment of a given kind of child. `runtime/environment.txt` declares
every name the launcher sets, with the kinds and modes that receive it, and a
test holds the code to that file. Each child receives what it receives today.

## Out of Scope

- Dropping an inherited `DRUPACK_ADMIN_PASSWORD` for Drush and export. It reaches them today and still does.
- Any other change to what a child receives.
- The names `launcher/internal/build` writes at build time. That package stays a writer.
- A table that generates the Go code, or reflection from a table to struct fields.

## User Stories

1. As a maintainer, I want one function to build a child's environment, so that one read answers what a child receives.
2. As a maintainer, I want every name declared with its kinds, so that a new variable is one line and one test failure away from complete.
3. As a site author, I want each child to receive the environment it receives today, so that the refactor changes nothing I see.

## Implementation Decisions

- D1: `Kind` has five values: `Reader`, `Server`, `Operate`, `Drush`, `Export`. `Reader` is the child `Plan()` runs for the reader's own `php`, `php-cli` and Node lines. Evidence: `Plan` and `environment` in `launcher/plan.go`.
- D2: `Facts` is one struct in package `main` holding what a start knows: the parent environment and its case rule, the launch's names and paths, the mode, the site paths, the address, the CA file, the hub, the typed inputs, the alias, the login link and the server's own values. `func (f Facts) Env(k Kind) []string` is pure. Evidence: the fields are the arguments of `putSiteEnvironment`, `serverEnvironment`, `putMercureEnvironment` and `environment`.
- D3: A start fills `Facts` in the order it learns things. Each launch calls `Env` on the value it holds then, and the value receiver gives that launch its own copy. Evidence: `startSite` runs `operate` before `tls.Choose`, and sets `DRUPACK_RUNTIME_OPEN` and `DRUPACK_RUNTIME_ALIAS` last.
- D4: File work stays in the callers. `storeBundle` writes the bundle and returns its path. The hub's secrets are read by a function that returns them. Evidence: `storeBundle` in `launcher/start.go` and `putMercure` in `launcher/drush.go` take an `*environ` today.
- D5: `Env` emits, for every kind and mode, the names and values the code emits today. The test rows are written from the current output before any caller moves. Evidence: `TestServerEnvironment`, `TestPlanEnvironment` and the `environmentOf` assertions fix part of that output.
- D6: `Facts` takes the host and URL of the `Address` that [the site address PRD](site-address-resolution.md) defines. This PRD's work starts after that one merges.
- D7: `runtime/environment.txt` declares every `DRUPACK_*` and `DRUSH_*` name the launcher's `main` package sets. Each line gains `kinds=` and `modes=`. `launcher/childenv.go` is that package's only writer. Evidence: the file's header names `tests/conformance/test_environment.py` as its check, and its lines list `launcher/plan.go`, `launcher/sitestart.go`, `launcher/start.go` and `launcher/drush.go` as writers.
- D8: `TestEnvDeclared` reads `runtime/environment.txt`, fills every field of `Facts`, and runs each kind in both modes. It asserts the emitted `DRUPACK_*` and `DRUSH_*` names equal the declared ones.
- D9: `tests/conformance/test_environment.py` covers every declared name and reads the two new columns.
- D10: The change deletes `environment()`, `putSiteEnvironment`, `putMercureEnvironment`, `serverEnvironment`, `withInputs`, the `e environ` parameter of every function that takes one, `openedSite.e`, `packagedStart.e`, and each `put` and `drop` call in `start.go` and `sitestart.go`. The `environ` type stays, private to `childenv.go`.
- D11: `TestServerEnvironment` becomes `TestEnvServer` and `TestPlanEnvironment` becomes `TestEnvReader`, each with its rows unchanged. `TestEnvKinds` is a table of `Facts` in and environment out per kind and mode. The drush and export tests keep their assertions.
- D12: ADR 0053 records the decision and ADR 0050 gains an amendment line.

### Considered options

- A Go table of rows with value closures, parsed by the Python test. It lost because a Python test that parses Go source breaks on a formatting change.
- An embedded TSV mapped to struct fields by reflection, built for flexibility. It lost because a typo in a field name fails at test time with no compiler help, and it adds a file format.

## Testing Decisions

- A good test gives `Env` a `Facts` and a kind and reads the list. None reaches into the `environ` helper.
- Tested: `Env` per kind and mode, and the agreement between the code and `runtime/environment.txt`.
- Prior art: `TestServerEnvironment` in `launcher/start_test.go`, `TestPlanEnvironment` in `launcher/plan_test.go`, and `tests/conformance/test_environment.py`.

## Further Notes

`Facts` has about 25 fields, and a field left unset compiles. `TestEnvDeclared`
with every field set is the guard. A wrong variable is diagnosed from the `Facts`
of that launch and the emitted list, read against `runtime/environment.txt`.
