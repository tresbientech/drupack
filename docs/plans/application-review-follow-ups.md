# Plan: act on the application review

> Source: [application review](../reviews/2026-09-21-application-review.md) and
> [architecture review](../reviews/2026-09-21-architecture-review.md), both at
> commit `192002e`. Phases name finding identifiers instead of user stories.

## Already shipped

Closed on branch `review-quick-wins`, 2026-09-21. Each is proven by the Linux
conformance run recorded with that branch.

- F01 and F13, the serving lease. A start locks `serving.lock` in Site data and
  holds it through the exec into the server. The HTTP ownership probe and the
  separate startup lock are gone. [ADR 0011](../adr/0011-serving-lease-owns-site-data.md).
- F09, readiness. The entry point polls `/.drupack-id` and accepts only 204.
- F06, mixed releases. Cross-release runtime fallback is deleted.
- F03, cleanup versus live files. A start marks the runtime entry and the
  application entry it runs from, and `clean`, the sweep and `removeOthers` all
  skip a marked entry.
- F15, lost diagnostics. A failed Drush step reports the reason it gave, with
  the connection password stripped.
- F05, live settings. Settings publish through a rename, and `dr` no longer
  rewrites them.
- Half of F02. `DRUPACK_ADMIN_PASSWORD` no longer reaches the server process.

## Suite

Every phase runs the Linux chain. The build comes first, since the conformance
suite runs against the built executable.

```sh
docker build --target artifact --output type=local,dest=dist .
cd packaging/launcher && go test ./...
./dist/drupack php-cli tests/unit/launch_test.php
python3 tests/conformance ./dist/drupack test-results/conformance
```

Clear `test-results/` before a gate run. A case that installs a site finds an
installed one otherwise, and its start prints no progress lines.

## Platform runs

Phase 2 changes behaviour Windows exercises differently, through its own process
listing. It runs the Windows suite before its report.

```sh
docker build --target build -t drupack-build .
container=$(docker create drupack-build)
docker cp "$container:/go/src/app/app-payload.tar" application/app-payload.tar
docker cp "$container:/go/src/app/app_checksum.txt" application/app_checksum.txt
docker rm "$container"
```

```powershell
./packaging/windows/build.ps1 -ApplicationDirectory application -Version dev `
  -WorkDirectory $env:TEMP\drupack -Output dist\drupack.exe
python tests\conformance dist\drupack.exe test-results\conformance
```

---

## Phase 1: One owner prepares a complete release

**Findings**: F06 remainder, narrowing A01

### What to build

Preparation gets one owner. It readies the runtime and the application, and
activates only after both succeed. `writeActive` and `removeOthers` run before
the application is prepared today, so a failed application extraction leaves a
machine with no previous runtime to run.

The failure names the requested runtime identity, the requested application
identity, the step that failed and the executable that was selected. Those four
values answer a diagnosis six months from now.

Pairing needs no new record, since the executable embeds both identities.
Previous-release fallback returns only when a need for it appears.

### Acceptance criteria

- [ ] `cd packaging/launcher && go test ./...` exits 0, including a table where
      both components prepare, where the application fails after a staged
      runtime, and where the runtime itself fails.
- [ ] A forced application failure leaves the previous release active, and the
      start still runs it whole.
- [ ] A forced runtime staging failure exits non-zero, activates nothing,
      unpacks no application, and prints the four diagnosis values.
- [ ] The release build still produces an executable that prints its version.

---

## Phase 2: Install secrets stay in the process that needs them

**Findings**: F02 remainder

### What to build

`installDrupal()` stops putting the database URL and the administrator password
in subprocess arguments, where every local account can read them.
`configureSeedAdministrator()` already reads its password from the environment
inside the child, which is the pattern to follow.

### Acceptance criteria

- [ ] A conformance case reads the command line of every Drush child during a
      first install and finds no password and no database URL. It runs on Linux
      through `/proc`, and on Windows through the process list.
- [ ] A first start still installs on sqlite, mysql and pgsql, and its printed
      login link still works.
- [ ] The Linux and Windows conformance runs pass whole.

---

## Phase 3: Addresses, derivatives and the network contract

**Findings**: F10, F12, F14

### What to build

URL construction brackets an IPv6 host, so `--host ::1` produces a usable
address.

Image style derivatives revalidate instead of claiming a year of immutability,
since an `itok` token names the style and the path, never the contents.

A start on a non-loopback listener prints its login link and opens no browser.
The README states the TLS boundary such a deployment needs.

### Acceptance criteria

- [ ] `--host ::1` starts, prints a URL a browser accepts, and answers there.
- [ ] A replaced source image at the same path serves a fresh derivative to a
      browser holding the previous response.
- [ ] A start on `0.0.0.0` prints the link and opens no browser.
- [ ] The README names the TLS requirement for non-loopback serving.
- [ ] The Linux conformance run passes whole.

---

## Out of scope

Named here so no phase absorbs them.

- F04, the release compatibility gate. Owned by the first entry in
  [the backlog](../backlog.md).
- F07, the packaged MCP flow. It needs a build to confirm which module provides
  `mcp-tools:serve` before any fix. `README.md` documents the command, so it
  gets its own change.
- F08, adoption versus interrupted installation. It needs a decision about what
  recovery should do before code changes.
- F11, Windows cache ownership and ACL validation. The exposure needs a hostile
  local account and a custom cache root.
- F16, the development entrypoint cannot install a fresh site.
- A cold `drupack --help` unpacks the whole release before printing usage, since
  argv reaches the entry point only after preparation. Moving that decision into
  the launcher would put the option contract in a third place, which
  [the backlog](../backlog.md) already covers.
