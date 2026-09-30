# Plan: A start that returns the prompt, and a stop

**Spec:** docs/prd/detached-start.md

## Architectural decisions

- **D1**: `start` and `stop` are command words; a bare `SITE` means `start`.
- **D2**: `--foreground` is a new option on every start.
- **D3**: A start detaches unless `--foreground` is set or a double-click owns the Windows console.
- **D4**: `stop` takes `--data-dir` alone; the engine form takes the folder.
- **D5**: The start takes the Serving lease first, so handover and refusals stay in the terminal.
- **D6**: With the lease free, the start releases it and runs itself with `--foreground` as a detached child.
- **D7**: The launcher owns detaching: a new session on Unix, a hidden console on Windows.
- **D8**: The child writes to `server.log`, truncated per start, with null standard input.
- **D9**: The start relays `server.log` until the ready line, then prints the log path and the stop command.
- **D10**: An early child exit gives its code; a readiness timeout exits 1.
- **D11**: The start passes a person-present flag to the child.
- **D12**: Once ready, the server binds `127.0.0.1:0` and writes `stop.json` (port, token, PID), then prints the ready line.
- **D13**: One route, `POST /stop`, bearer token compared in constant time; 404 elsewhere, 403 on a wrong token.
- **D14**: A valid stop sends SIGINT to the server itself; on Windows it arms the stop guard, calls `caddy.Stop()` and exits 0.
- **D15**: `stop` tests the lease first: free means not running, held without a record means preparing.
- **D16**: `stop` posts the token and waits at most 15 seconds for the lease to free.
- **D17**: A site keeps `stop.json` and `server.log` in Site data.
- **D18**: A folder's state lives in a cache entry keyed by a hash of its canonical path.
- **D19**: A folder start takes that entry's lease; a held lease says "already serves".
- **D20**: The folder server runs the site's runner without cron, and the engine Caddyfile gains the readiness route.
- **D21**: A new ADR records the feature; ADR 0022 gains an amendment.
- **Route**: `POST http://127.0.0.1:<port>/stop` with `Authorization: Bearer <token>`.
- **Record**: `stop.json`, owner-only: `{"port": int, "token": "<64 hex>", "pid": int}`.

---

## Phase 1: Stop a foreground site

**User stories**: 5, 6, 7, 13, 14, 18, 19
**Decisions**: D1 (stop), D2, D4, D12, D13, D14, D15, D16, D17

### What to build

A site started with or without `--foreground` serves in the foreground as
today. Once it answers, it opens the stop channel and writes `stop.json` in
Site data. `SITE stop` from another terminal ends it through Ctrl+C's path.

### Acceptance criteria

- [x] `(cd runtime/watch && go test ./...)` passes, with a case for the stop route, a wrong token and a missing token.
- [x] A conformance case: `SITE stop` ends a running site, frees the lease, closes the port, and exits 0.
- [x] A conformance case: `SITE stop` with no running site exits 0 and says it is not running, a stale `stop.json` present.
- [x] A conformance case: a wrong token leaves the site serving, and the channel answers only on 127.0.0.1 under `--listen 0.0.0.0`.
- [x] A conformance case: `stop.json` is mode 0600 on Linux and macOS.
- [x] `application/tests/launch_test.php` passes with `--foreground` in the parser, the usage and the help.

---

## Phase 2: Detached site start

**User stories**: 1, 2, 3, 4, 8, 9, 10, 12
**Decisions**: D1 (start), D3, D5, D6, D7, D8, D9, D10, D11

### What to build

`SITE` and `SITE start` take the lease, release it, and run the same command
detached with `--foreground`. The start relays `server.log` until the ready
line, prints the log path and `SITE stop`, and returns 0. The harness's start
helpers pass `--foreground`.

### Acceptance criteria

- [x] A conformance case: a first start returns 0 after the ready line, its output holds the login link and the log path, and `SITE drush status` then exits 0.
- [x] A conformance case: `SITE start` returns the same way as `SITE`.
- [x] A conformance case: a start on a taken port exits 1 with the port message, and no server remains.
- [x] A conformance case: a second start while detached hands over and exits 0.
- [x] A conformance case: `SITE stop` ends a detached site.
- [x] `(cd launcher && go test ./...)` passes.
- [x] `bash build/qa.sh` passes.

---

## Phase 3: Folder mode

**User stories**: 15, 16, 17
**Decisions**: D18, D19, D20

### What to build

`drupack [start] [DIR]` keeps its state in a cache entry keyed by the folder,
takes that entry's lease, and detaches the same way. `drupack stop [DIR]` stops
it. The folder server runs the site's runner without cron and answers the
readiness route.

### Acceptance criteria

- [x] A conformance case: `drupack` in a folder returns 0 once ready, and `drupack stop` ends it and exits 0.
- [x] A conformance case: a detached folder start and stop leave the project snapshot unchanged.
- [x] A conformance case: a second `drupack` on a served folder exits 1 and says it already serves.
- [x] `git grep -n folder-server -- runtime engine application` finds nothing.
- [x] `bash build/qa.sh` passes.

---

## Phase 4: Every platform, and the records

**User stories**: 11, 20
**Decisions**: D21

### What to build

A dispatched release run on the branch runs the detached and stop cases on
macOS and Windows. The new ADR records the detach default and the stop channel.
ADR 0022 gains its amendment. `docs/cli.md` documents `start`, `stop` and
`--foreground`, and names `--foreground` for containers and systemd.

### Acceptance criteria

- [ ] A `workflow_dispatch` release run on the branch passes on Windows amd64 and both macOS jobs.
- [ ] `ls docs/adr | grep -c detach` prints 1, and `grep -n 'Amendment' docs/adr/0022-engine-executable.md` finds the folder lease.
- [ ] `grep -n -- '--foreground' docs/cli.md` finds the option row and the container note.
- [ ] `grep -n '^SITE stop' docs/cli.md` finds the synopsis line.
