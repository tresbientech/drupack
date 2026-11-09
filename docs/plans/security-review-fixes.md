# Plan: Security review fixes

**Spec:** docs/prd/security-review-fixes.md

## Architectural decisions

Durable decisions that apply across all phases, in the PRD's words:

- D1: The refusals refuse, with 403, a request whose decoded path holds a backslash anywhere, on the site port and the static port.
- D2: No rule for trailing dots or spaces is added.
- D3: ADR 0047 names the backslash refusal as a Drupack rule beyond core's `.htaccess`.
- D4: With TLS source `none` and a loopback bind, `Config.Addresses` lists `http://` addresses for the alias, `localhost`, `127.0.0.1`, `[::1]`, the bind address and `TLS.Host`.
- D5: A non-loopback bind keeps the `http://:PORT` address.
- D6: `tls.Loopback` reports true only for an IP address in 127.0.0.0/8 or `::1`.
- D7: `runtime.Exposure(path string, readers bool) (reason string, err error)` replaces `privateRoot`: links resolved, leaf rule on the path, ancestor rule on each parent.
- D8: The leaf rule: Unix owner is the current uid with `022` clear, or `077` with `readers`; Windows owner trusted and no writer from `otherWriter`.
- D9: The ancestor rule: Unix owner is the uid or root, no group or other write unless sticky; Windows no untrusted `FILE_DELETE_CHILD` on the parent or `DELETE` on the child.
- D10: `runtime.Create(dir)` is `makeRoot` exported, owner-only on both platforms.
- D11: `Root` runs `Exposure(root, true)` on every candidate, and both fallbacks stay.
- D12: Site-mode start, `drush` and `export` create Site data with `runtime.Create` and warn, then continue, when `Exposure(data, false)` or a named `--files-dir` reports a reason.
- D13: `server.log`, the lease file and the Mercure secrets refuse a link.
- D14: `vendorRoot` accepts a candidate only when the folder, `vendor/` and `vendor/autoload.php` are owned by a trusted account.
- D15: `startEngine` restricts the umask before it runs PHP.
- D16: ADR 0051 records D7 to D14.
- D17: A first start from the Seed site sets new cron key, private key and UUID values. Existing sites keep theirs.
- D18: `stageSite` refuses `auth.json`, `.env`, `.env.*` other than `.env.example` and `.env.dist`, and `.key` or `.pem` files holding a PEM private key, outside the top-level `vendor/`.
- D19: `docs/build-your-site.md` states the refusal.
- D20: The code static-php-cli runs holds a token that cannot write packages, and a job without third-party build code pushes the builder image.
- D21: `anchore/sbom-action` is pinned by commit SHA, and in `release.yml` the SBOM runs in its own read-only job.
- D22: `docs/cli.md` documents the Site data warning, the `DRUPACK_CACHE_DIR` rule and the loopback HTTP names.

Commands the criteria use:

- Go tests: `cd launcher && go test ./...`, or one package with `-run`.
- Windows Go tests: `cd launcher && GOOS=windows go test -c -o "$WIN/<pkg>.test.exe" ./<pkg>`, run through WSL interop. `WIN` is a folder under `/mnt/c/Users/theno/AppData/Local/Temp`.
- Conformance: `python3 tests/conformance dist/drupacked-demo-linux-amd64 "$RESULTS" -k <Class>`, with `DRUPACK_TEST_ENGINE=dist/engine/drupack-linux-amd64` for engine cases. The executables come from the build steps of `build/qa.sh`.
- PHP application tests: `dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/<file>" a b`.

Phases 1, 2, 6, 7 and 8 touch disjoint files. Phases 3, 4 and 5 run in order.

---

## Phase 1: Backslash refusal

**User stories**: 1
**Decisions**: D1, D2, D3

### What to build

A request whose path holds a backslash answers 403 on the site port and the static port, on every platform. The conformance suite probes the backslash shapes of the review. ADR 0047 records the rule.

### Acceptance criteria

- [ ] `go test ./internal/caddyfile/` passes with the regenerated golden files.
- [ ] `-k EngineExecutable` and `-k StaticSite` pass with new rows answering 403 for `/modules%5Cx%5Cy.php`, `/sites%5Cdefault%5Cfiles%5Cevil.php` and `/sites%5Cdefault%5Csettings.php`.
- [ ] The Windows runtime probe from the review, re-run with the rendered Caddyfile, answers 403 for those three paths. `/sites/default/settings.php.` still answers 403.
- [ ] `git diff BASE -- docs/adr/0047-request-guards-match-htaccess.md` shows the backslash rule.

---

## Phase 2: Loopback HTTP names

**User stories**: 2, 3, 4, 5
**Decisions**: D4, D5, D6, D22

### What to build

A plain-HTTP start on a loopback bind answers only its alias, `localhost`, `127.0.0.1`, `[::1]`, its bind address and its `--host`. A non-loopback bind answers any name. `--listen` with a host name never counts as loopback. `docs/cli.md` names the answered names.

### Acceptance criteria

- [ ] `go test ./internal/caddyfile/ -run TestAddresses` passes with rows for a loopback bind, a `127.0.0.2` bind, a `--host` name, an empty alias and a `0.0.0.0` bind.
- [ ] `go test ./internal/tls/ -run TestLoopback` passes with `127.example.com`, `127.0.0.1`, `::1` and `10.0.0.1`.
- [ ] `-k EngineExecutable` passes a new case: `Host: evil.example` to a loopback HTTP engine start gets no answer from PHP.
- [ ] `-k NetworkListener` passes unchanged.
- [ ] `grep -n "answers" docs/cli.md` shows the loopback names and `--host`.

---

## Phase 3: Folder exposure and the cache root

**User stories**: 9
**Decisions**: D7, D8, D9, D10, D11, D22

### What to build

`Exposure` and `Create` replace `privateRoot` and `makeRoot`. Every cache candidate passes leaf and ancestor checks, or the start refuses with today's message. An open parent of the `/tmp` fallback is refused, and the fallbacks still serve a user's own folder. `docs/cli.md` states the `DRUPACK_CACHE_DIR` rule.

### Acceptance criteria

- [ ] `go test ./internal/runtime/` passes cases for a 0777 parent, a 1777 sticky parent, a 0775 leaf, a link into an open folder, and each `Root` candidate.
- [ ] A foreign-owner case passes when the test can `chown`, and is skipped elsewhere.
- [ ] `grep -rn privateRoot launcher/` prints nothing.
- [ ] The Windows `internal/runtime` test binary passes through interop, with the existing `root_windows_test.go` cases moved to `Exposure`. It adds cases for an untrusted `FILE_DELETE_CHILD` on a parent and for `C:\` as an ancestor.
- [ ] `-k ColdWarmStart`, `-k CacheRootFull` and `-k SiteNamedCaches` pass.
- [ ] `grep -n "DRUPACK_CACHE_DIR" docs/cli.md` shows the parents rule.

---

## Phase 4: Site data warning and link refusal

**User stories**: 6, 7, 8, 12
**Decisions**: D12, D13, D15, D22

### What to build

Start, `drush` and `export` warn about open Site data or an open `--files-dir`, then continue. New Site data is created owner-only on both platforms. `server.log`, the lease file and the Mercure secrets refuse a link. The engine start sets the umask. `docs/cli.md` shows the warning and the fix commands.

### Acceptance criteria

- [ ] `go test ./internal/state/` passes cases that plant a link at the lease file and at each Mercure secret and assert the refusal.
- [ ] `go test . -run Log` passes a case that plants a link at `server.log` and asserts the refusal.
- [ ] `-k FirstStartAndListener` passes a new case: a start on a pre-existing 0777 data folder prints the warning with the folder and `chmod`, and serves the site.
- [ ] `-k EngineExecutable` passes a case that checks the engine's SQLite file in a project has no group or other bits.
- [ ] The Windows `internal/state` and launcher test binaries pass through interop.
- [ ] `grep -n "icacls" docs/cli.md` shows the Windows fix command.

---

## Phase 5: Owned vendor/ search

**User stories**: 10, 11
**Decisions**: D14, D16

### What to build

`drupack drush` and `dr` refuse a `vendor/` whose folder, `vendor/` or autoloader another account owns, and name that owner. They still find the project's own `vendor/` from any of its subfolders. ADR 0051 records the folder rules of phases 3 to 5.

### Acceptance criteria

- [ ] `go test . -run VendorRoot` passes cases for a subfolder of the project, a candidate owned by another uid where `chown` works, and a root-owned candidate.
- [ ] The Windows launcher test binary passes through interop.
- [ ] `-k EngineExecutable` passes unchanged.
- [ ] `ls docs/adr/0051-*.md` prints one file naming the warn and refuse cases and both rules.

---

## Phase 6: Fresh seed keys

**User stories**: 13
**Decisions**: D17

### What to build

A first start from the Seed site writes new random values for the cron key, the private key and the site UUID before it serves. A start of an existing site leaves them unchanged.

### Acceptance criteria

- [ ] `-k FirstStartAndListener` passes a new case: after a first start, `drush state:get system.cron_key`, `state:get system.private_key` and `config:get system.site uuid` differ from the values in the seed's `site.sqlite`.
- [ ] The same case starts the site a second time and finds the values unchanged.
- [ ] `application/tests/prepare_test.php` passes on `php-cli`.

---

## Phase 7: Build refuses credential files

**User stories**: 14, 15
**Decisions**: D18, D19

### What to build

A site build whose file list holds `auth.json`, `.env`, a `.env.*` other than `.env.example` and `.env.dist`, or a private key stops before staging and names every file. `docs/build-your-site.md` states the rule.

### Acceptance criteria

- [ ] `go test ./internal/build/` passes one refusing case per name and a private-key `.pem`.
- [ ] The same run passes cases for `.env.example`, `.env.dist`, a public-certificate `.pem` and a `.env` under the top-level `vendor/`.
- [ ] `grep -n "auth.json" docs/build-your-site.md` shows the refusal.

---

## Phase 8: CI rights

**User stories**: 16, 17
**Decisions**: D20, D21

### What to build

static-php-cli runs in a job whose token reads packages only, and a job without third-party build code pushes the builder image. `anchore/sbom-action` is pinned by commit SHA. In `release.yml` the SBOM runs in its own job with `contents: read`, and `publish` attests what it uploads.

### Acceptance criteria

- [ ] `grep -n "sbom-action@" .github/workflows/*.yml` shows only 40-hex SHAs.
- [ ] `grep -n "packages: write" .github/workflows/release.yml` lists no job that runs `build-builder.sh`, `spc-craft.sh` or `embed.sh`.
- [ ] `actionlint .github/workflows/release.yml .github/workflows/build.yml` reports nothing.
- [ ] A release-candidate `workflow_dispatch` run for all platforms passes after the push of step 7 of the run.
