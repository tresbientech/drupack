# Plan: One function builds a child's environment

**Spec:** docs/prd/child-environment.md

## Architectural decisions

Durable decisions that apply across all phases:

- **D1**: `Kind` has five values: `Reader`, `Server`, `Operate`, `Drush`, `Export`.
- **D2**: `Facts` holds what a start knows, and `Facts.Env(Kind)` is pure.
- **D3**: a start fills `Facts` in order, and each launch calls `Env` on the value it holds then.
- **D4**: file work stays in the callers, which pass results in.
- **D5**: `Env` emits what the code emits today, with rows written from the current output first.
- **D6**: `Facts` takes the host and URL of the site address PRD's `Address`. This plan starts after that branch merges.
- **D7**: `runtime/environment.txt` declares every name the `main` package sets, with `kinds=` and `modes=`.
- **D8**: `TestEnvDeclared` holds the code to that file.
- **D9**: `test_environment.py` covers every declared name and the new columns.
- **D10**: the replaced functions, parameters and calls are deleted; `environ` stays private to `childenv.go`.
- **D11**: `TestEnvServer`, `TestEnvReader` and `TestEnvKinds`; the drush and export assertions stay.
- **D12**: ADR 0053, and an amendment line in ADR 0050.
- **Tests**: `cd launcher && go test ./...` runs the Go tests. `bash build/unit-tests.sh` runs the Python unit files. `cd launcher && GOOS=windows go build ./... && go vet ./...` checks the Windows build. `BASE` is the commit the branch starts from.

---

## Phase 1: The reader's own lines

**User stories**: 1, 3
**Decisions**: D1, D2, D5, D11

### What to build

`Kind`, `Facts` and `Env` exist. `Env(Reader)` returns what `environment()` returns, and `Plan` and every caller that seeds an environment use it. `TestPlanEnvironment` becomes `TestEnvReader` with its cases unchanged.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes.
- [ ] `grep -c 'func environment(' launcher/plan.go` prints 0.
- [ ] `go test . -run TestEnvReader -v` in `launcher/` passes the cases `TestPlanEnvironment` held at `BASE`.
- [ ] `cd launcher && GOOS=windows go build ./... && go vet ./...` succeeds.

---

## Phase 2: A packaged site's PHP and Drush children

**User stories**: 1, 3
**Decisions**: D3, D4, D5, D6, D10, D11

### What to build

`TestEnvKinds` first gains rows for `Operate`, `Drush` and `Export` in site mode, written from what the code emits. Then the check, the prepare, the login mint, site drush and site export take `Env` of a `Facts`. The hub's secrets come from a function that returns them.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes.
- [ ] The first commit of the phase adds the site-mode `TestEnvKinds` rows and changes no file outside `launcher/*_test.go` and `launcher/childenv.go`.
- [ ] `git diff BASE..HEAD -- launcher/drush_test.go launcher/export_test.go | grep '^[-+].*\(DRUPACK_\|DRUSH_\|PATH=\)'` prints nothing.
- [ ] `grep -n 'withInputs\|putSiteEnvironment' launcher/*.go` prints nothing.

---

## Phase 3: The engine's PHP and Drush children

**User stories**: 1, 3
**Decisions**: D3, D4, D5, D10, D11

### What to build

`TestEnvKinds` first gains the engine-mode rows. Then the engine's check, drush, core call and export take `Env`. No function takes an `environ` parameter.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes.
- [ ] `grep -n 'e environ\|e \*environ' launcher/*.go | grep -v childenv.go` prints nothing.
- [ ] `grep -n 'putMercureEnvironment' launcher/*.go` prints nothing.
- [ ] `git diff BASE..HEAD -- launcher/drush_test.go launcher/export_test.go | grep '^[-+].*\(DRUPACK_\|DRUSH_\|PATH=\)'` prints nothing.

---

## Phase 4: The server

**User stories**: 1, 3
**Decisions**: D3, D4, D5, D10, D11

### What to build

Both starts hand the server `Env(Server)`. `storeBundle` returns the bundle's path. `TestServerEnvironment` becomes `TestEnvServer` with its three rows.

### Acceptance criteria

- [ ] `cd launcher && go test ./...` passes, with `TestEnvServer` holding the plain HTTP, mkcert and own-root rows.
- [ ] `grep -n '\.put(\|\.drop(' launcher/*.go | grep -v _test.go | grep -v childenv.go` prints nothing.
- [ ] `grep -n 'func serverEnvironment' launcher/*.go` prints nothing.
- [ ] `cd launcher && GOOS=windows go build ./... && go vet ./...` succeeds.

---

## Phase 5: Every name declared

**User stories**: 2
**Decisions**: D7, D8, D9, D12

### What to build

`runtime/environment.txt` gains a line for each undeclared name and the two columns on every line. `TestEnvDeclared` and the widened Python check hold the code to it. ADR 0053 records the decision, and this plan file is deleted.

### Acceptance criteria

- [ ] `cd launcher && go test . -run TestEnvDeclared -v` passes.
- [ ] Removing one line of `runtime/environment.txt` makes `TestEnvDeclared` fail, shown once in the phase report.
- [ ] `bash build/unit-tests.sh` passes.
- [ ] `grep -c 'kinds=' runtime/environment.txt` equals the count of its declaration lines.
- [ ] `ls docs/adr/0053-*.md` names one file and `grep -c 0053 docs/adr/0050-the-launcher-owns-a-start.md` prints at least 1.
