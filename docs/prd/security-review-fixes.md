# Security review fixes

## Problem Statement

A security review of commit `93f4669` found ways for a web page, a downloader, or another account on the same machine to run code as the reader.

- On Windows, a backslash in a request path passes every request guard. PHP below the docroot runs, and so does PHP in public files.
- A plain-HTTP start answers any Host header. A web page can rebind its own name to 127.0.0.1 and drive a fresh engine project's installer.
- Site data is never checked for who else can write it. On Windows a folder under a drive root inherits Modify for every signed-in account. Another account can plant PHP the next start runs.
- Every SQLite site of one release shares the seed's cron key, private key and site UUID. Anyone holding the executable knows them.
- A site build packs `auth.json` or `.env` when git tracks it or no ignore rule covers it.
- The cache check reads the last folder alone. Another account that owns a parent folder swaps the runtime after the check.
- The engine's `vendor/` search climbs to the drive root. A `C:\vendor` another account planted runs on `drupack drush`.
- The release workflow gives static-php-cli, which a daily job bumps without review, a token that can push builder images. A third-party action pinned by tag runs in the job that publishes releases.

## Solution

Requests holding a backslash are refused on every port. A plain-HTTP start on a loopback address answers only its own names.

A start warns when another account can write its Site data. The cache refuses a root another account can change, parents included. The engine's `drush` refuses a `vendor/` another account owns.

A new SQLite site gets its own cron key, private key and UUID. A site build stops when it would pack a credential file. Code from static-php-cli runs with no write rights, and third-party actions are pinned by commit.

## Out of Scope

- release signing (review M5); a later PRD
- rotation of the keys and UUID in existing sites (owner ruling)
- refusing unsafe Site data; it warns (owner ruling)
- trust checks on an engine Project folder
- a plaintext warning on a non-loopback engine start
- the review's other Low items:
  - `PHPRC` naming its file, and `PHP_INI_SCAN_DIR`
  - `trust` checking `root.pem`, and uninstall by fingerprint
  - self-update timeout and size cap
  - credentials on the command line, and the login link in the server environment and log
  - glob characters in a project path
  - `clean` deleting folders it did not create
  - stop-server timeouts, and the `klauspost/compress` bump
- adding `drupack.site` to the Public Suffix List
- pinning GitHub-owned actions by commit
- the findings the review rated as matching core's `.htaccess`: backup names, symlinks in public files, recursive `mkdir`

## User Stories

1. As a reader on Windows, I want a request holding a backslash refused, so that no PHP outside the front controllers runs.
2. As a reader, I want a plain-HTTP start on loopback to answer only its own names, so that a web page cannot reach my site through DNS rebinding.
3. As a reader who serves a custom loopback name, I want `--host` to add it to the answered names, so that my `/etc/hosts` entry keeps working.
4. As a reader who serves the LAN, I want a non-loopback listener to answer any name, so that other computers reach it by IP or host name.
5. As a reader, I want `--listen 127.example.com:80` treated as a host name, so that no start plans loopback HTTPS for a public bind.
6. As a reader whose Site data another account can write, I want a warning that names the folder, the account and the fix command, so that I can lock it down.
7. As a reader, I want new Site data created owner-only on every platform, so that no start leaves it open.
8. As a reader, I want the files a start opens in Site data to refuse a link, so that a planted link cannot redirect a write or a read.
9. As a reader, I want a cache root refused when another account can change it or a parent above it, so that no start runs a swapped runtime.
10. As a reader, I want `drupack drush` to refuse a `vendor/` another account owns, so that a planted autoloader never runs.
11. As a reader, I want `drupack drush` to work from any folder of my own project, so that the fix costs me nothing.
12. As a reader, I want files the engine writes into a project created owner-only, so that other accounts cannot read its database.
13. As a reader, I want a new SQLite site to hold its own cron key, private key and UUID, so that the public executable reveals none of them.
14. As a site builder, I want the build to stop when it would pack a credential file, so that no secret ships in an executable.
15. As a site builder, I want `.env.example` and `.env.dist` to pass, so that a template file does not stop my build.
16. As a maintainer, I want static-php-cli to run with a token that cannot push images, so that an upstream compromise cannot poison the builder image.
17. As a maintainer, I want third-party actions pinned by commit and kept out of the publish job, so that a moved tag cannot change a release.

## Implementation Decisions

### Request guards

- D1: The refusals refuse, with 403, a request whose decoded path holds a backslash anywhere. They apply on the site port and the static port. Evidence: `@hostLikePath` at `launcher/internal/caddyfile/fragments/refusals.caddy:14` refuses one only after the first slash. The 2026-11-08 Windows run of runtime `0.1.8` answered 200 for `/modules%5Cx%5Cy.php`.
- D2: No rule for trailing dots or spaces is added. Evidence: the same run answered 403 for `/sites/default/settings.php.`, and `/index.php.` reached the front controller.
- D3: ADR 0047 names the backslash refusal as a Drupack rule beyond core's `.htaccess`. Evidence: `docs/adr/0047-request-guards-match-htaccess.md`.

### Host names

- D4: With TLS source `none` and a loopback bind, `Config.Addresses` lists `http://` addresses for these names: the alias, `localhost`, `127.0.0.1`, `[::1]`, the bind address and `TLS.Host`. Empty and repeated names are dropped. Evidence: `launcher/internal/caddyfile/caddyfile.go:63-65` returns `http://:PORT`.
- D5: A non-loopback bind keeps the `http://:PORT` address. Invariant: HTTPS is offered on loopback binds alone. Evidence: `tls.Choice.Eligible` and `caddyfile.go:66-73`.
- D6: `tls.Loopback` reports true only for an IP address in 127.0.0.0/8 or `::1`. Evidence: `launcher/internal/tls/tls.go:307` tests a `127.` prefix, and `engineListen` (`launcher/listen.go:46`) takes any host.

### Folders the launcher runs code from

- D7: `runtime.Exposure(path string, readers bool) (reason string, err error)` replaces `privateRoot`. It resolves links, applies the leaf rule to `path`, then applies the ancestor rule to each parent up to the volume root. A non-empty reason names the folder and the account. An error means the answer is unknown, and callers treat it as exposed. Evidence: `privateRoot` in `launcher/internal/runtime/root_unix.go:16` and `root_windows.go:30`.
- D8: The leaf rule on Unix requires the current uid as owner and clear `022` bits, or `077` bits when `readers` is set. On Windows it requires a trusted owner and no writer from `otherWriter`. Evidence: `trustedAccounts` and `otherWriter` in `root_windows.go`.
- D9: The ancestor rule on Unix requires the current uid or root as owner, and no group or other write bit unless the sticky bit is set. On Windows, no account outside `trustedAccounts` may hold `FILE_DELETE_CHILD` on the parent or `DELETE` on the child. Invariant: `C:\` passes as an ancestor, since its Authenticated Users entry creates folders and deletes none. Evidence: `icacls C:\` on 2026-11-09 shows `(AD)` on the root and inherit-only `(M)`.
- D10: `runtime.Create(dir)` is `makeRoot` exported. A folder it creates is owner-only: mode 0700 on Unix, a protected DACL on Windows. Evidence: `root_windows.go:151-175`.
- D11: `Root` runs `Exposure(root, true)` on every candidate: `DRUPACK_CACHE_DIR`, `cacheRoots`, and the Windows `%TEMP%` rung in `asciiRoot`. An exposed root refuses with today's messages and advice. Both fallbacks stay. Evidence: `launcher/internal/runtime/cache.go:44-67`, `root_windows.go:191-205`.
- D12: Every Site-mode command that runs PHP from Site data creates it with `runtime.Create`. Start, `drush` and `export` then run `Exposure(data, false)`, and the same on a named `--files-dir`. A reason prints one warning with the folder, the account and the fix command (`chmod -R go-w` or `icacls`), and the command continues. This is the owner's ruling of 2026-11-09. It departs from the fail-closed rule: a file planted before the fix still runs once. Evidence: `launcher/sitestart.go:133-137`, `openSite` in `launcher/drush.go:70-115`.
- D13: The files a start opens by name in Site data refuse a link. These are `server.log`, the lease file and the Mercure secrets. Unix opens them with `O_NOFOLLOW`. Windows refuses a reparse point found by `Lstat`. Evidence: `launcher/detach.go:140`, `launcher/internal/state/claim_unix.go:23`, `launcher/internal/state/record.go:171`.
- D14: `vendorRoot` accepts a candidate only when the folder, its `vendor/` and `vendor/autoload.php` are each owned by a trusted account. On Unix that is the current uid or root. On Windows it is the `trustedAccounts` set. A candidate that fails ends the search with a refusal naming its owner. The search still climbs past folders without `vendor/`. Evidence: `launcher/drush.go:250-266`.
- D15: `startEngine` restricts the umask before it runs PHP, as the site start does. Evidence: `launcher/start.go:123`, `restrictUmask` in `launcher/launch_unix.go:24`.
- D16: ADR 0051 records the rules in D7 to D14: which folders refuse and which warn, and the leaf and ancestor rules.

### Seed keys

- D17: A first start from the Seed site sets new random values for the `system.cron_key` and `system.private_key` states and the `system.site` UUID, before the site serves. Existing sites keep theirs. Evidence: `copySeed` and `Step::Seed` in `application/operate.php:357-411`. On 2026-11-08, `/tmp/my-site/data/site.sqlite` held the seed's values.

### Site build

- D18: `stageSite` refuses the build when the site's file list holds any of these:
  - `auth.json`
  - `.env`
  - a `.env.*` file other than `.env.example` and `.env.dist`
  - a `.key` or `.pem` file holding a PEM private key

  The error names every file found and says to add it to `.gitignore` or delete it. The top-level `vendor/` is skipped, since the build installs it from the lock. Evidence: `stageSite` and `siteFiles` in `launcher/internal/build/build.go:355-460`.
- D19: `docs/build-your-site.md` states the refusal in place of its advice to list credentials in `.gitignore`. Evidence: `docs/build-your-site.md:25-28`.

### CI

- D20: The code static-php-cli runs holds a token that cannot write packages. The builder image is pushed by a job that runs no third-party build code. Evidence: `GITHUB_TOKEN: ${{ github.token }}` at `.github/workflows/release.yml:226`, the token mount in `runtime/build-builder.sh:55-63`, and `runtime/spc-craft.sh:12`.
- D21: `anchore/sbom-action` is pinned by commit SHA in `release.yml` and `build.yml`. In `release.yml` the SBOM step runs in its own job with `contents: read`, and `publish` attests and releases what it uploads. Evidence: `release.yml:931,939`, `build.yml:377,400`.

### Docs

- D22: `docs/cli.md` documents three things:
  - the Site data warning and its fix commands
  - that `DRUPACK_CACHE_DIR` and its parents must be writable by the reader alone
  - that a plain-HTTP loopback start answers its own names, and `--host` adds one

  Evidence: `docs/cli.md:341`.

### Considered options

- A `pathguard` package with a five-field `Policy` and a per-level call was built to give each caller its own policy. It lost because most fields had no caller.
- An `exposure` package with `Check`, `CheckStrict` and `Ceiling` was built to make the common call one line. It lost because `internal/runtime` holds the Windows rules already and the launcher imports it.
- Removing the paths that need the check was built to challenge the premise:
  - drop the Unix `/tmp` and Windows `%TEMP%` rungs
  - stop the `vendor/` search at `$HOME`
  - check Site data at the leaf alone

  It lost because hosts without `HOME` and non-ASCII Windows profiles lose their fallback. `drush` outside the home folder would also run from the project root alone.
- Applying the leaf rule at each level of the `vendor/` search lost. A Windows project under `C:\` inherits Modify for Authenticated Users, so `drush` would refuse there while `drupack` serves it.
- Hashing the cache entries in `warm()` lost. The check and the exec each resolve the path, so a swap between them passes.
- A refusal for unsafe Site data lost to the owner's ruling (D12).

## Testing Decisions

A test asserts what a reader or a builder sees: a status code, a refusal, a warning, a file mode or ACL, a stored value. No test reads the helpers in between.

- Request guards (D1): conformance probes on the site and static ports, beside the existing `/%5C` case in `tests/conformance/engine_cases.py`. A Windows run executes them against the Windows runtime.
- Addresses (D4, D5): rows in `TestAddresses` (`launcher/internal/caddyfile/caddyfile_test.go:100`) and a regenerated `testdata/engine-none.caddyfile`. A conformance probe sends a foreign `Host` to a loopback HTTP start.
- Loopback (D6): a table test in `launcher/internal/tls`.
- Exposure (D7 to D11): Go tests in `internal/runtime` cover these cases:
  - a 0777 parent
  - a 1777 sticky parent
  - a 0775 leaf
  - a link into an open folder
  - each rung of `Root`

  A foreign-owner case runs where the test can `chown`, and skips elsewhere. The Windows cases extend `root_windows_test.go` and run on a Windows run.
- Site data warning (D12): a conformance case starts on a pre-existing 0777 data folder and asserts the warning and a served site. Prior art: `tests/conformance/initialization_cases.py`.
- Links (D13): Go tests plant a link at each name and assert the refusal. Prior art: `launcher/internal/state/record_test.go`.
- `vendor/` search (D14): Go tests in `launcher/drush_test.go` with a candidate owned by another uid where `chown` works, and a project reached from a subfolder.
- Seed keys (D17): a conformance case asserts that a first start's cron key, private key and UUID differ from the seed's.
- Build refusal (D18): rows in `launcher/internal/build/build_test.go`, one per name, plus `.env.example` passing and a public `.pem` passing.
- CI (D20, D21): a release-candidate `workflow_dispatch` run on all platforms decides them.

## Further Notes

The Gitea mirror pushes `main` to the public GitHub repository. A push of these commits shows the fixes before a release ships them. The push and the release tag go together, with the owner's approval.

Windows tests run on a tag or a dispatched `windows-amd64` run. D1 and the Windows cases of D7 to D14 need a dispatched run before the tag.

D12's warning leaves a file planted before the fix able to run once. The review rates that path High on shared Windows machines.
