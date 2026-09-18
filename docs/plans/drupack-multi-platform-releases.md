# Plan: Drupack multi-platform releases

> Sources: [PRD](../prd/drupack-multi-platform-releases.md), [ADR 0001](../adr/0001-forge-canonical-github-packaging-mirror.md), [ADR 0002](../adr/0002-shared-application-extraction.md) and the [project glossary](../../CONTEXT.md)

## Architectural decisions

- The Forge repository `https://git.tresbien.tech/tresbientech/drupack` is canonical.
- GitHub `tresbientech/drupack` is a public mirror with full history. It builds and publishes releases.
- When a version tag reaches the Forge, or on a manual dispatch, a Forge Actions job pushes branches and tags to GitHub. It uses an SSH deploy key stored as a Forge secret.
- The same job pushes branches and tags to the drupal.org project `drupack` with a GitLab project access token, without force or prune.
- Only `main` and version tags are pushed to the Forge.
- Version tags have no `v` prefix, for example `0.1.0`.
- One GitHub workflow, `release.yml`, runs on a version tag or a manual dispatch.
- The product is named Drupack everywhere. Public environment variables use the `DRUPACK_` prefix.
- Variables that the launcher sets for its own runtime use the `DRUPACK_RUNTIME_` prefix.
- The first release ships Linux `amd64` and `arm64` musl executables, macOS `arm64` and `amd64` executables and a Windows `amd64` executable.
- Every target keeps the same CLI options, environment variables, default `./data` directory, seeded SQLite site, database drivers and `dr` command.
- A GitHub Release holds the executables, `checksums.txt`, `release.json` and one CycloneDX SBOM. The Linux and macOS executables ship gzipped, because UPX miscompresses a binary with our segment layout ([UPX issue 836](https://github.com/upx/upx/issues/836)).
- Each GitHub Release carries provenance attestations for its files.
- The packaged site template is Byte 1.0.3.
- The Seed site uninstalls `automatic_updates` and `package_manager`. A packaged executable cannot update its own Drupal core in place; Drupack ships a new executable instead.
- The Docker build downloads fr, zh-hans, es, hi and ar translations for each Drupal project in `drupal/composer.lock` from ftp.drupal.org. Two builds of one commit can embed different translations.
- The license is GPL-2.0-or-later.

## Status

Verified locally on Linux `amd64`:

- The musl build, `DRUPACK_DATA_DIR` and `DRUPACK_ADMIN_*`, and a SQLite first start.
- The five test scripts, with the Byte site template, at `f066463`. MySQL 8.4 and PostgreSQL 17 each install Byte.
- The interactive first start under a pseudo-terminal, with no password echo, at `f066463`.
- The `release.yml` asset naming, `checksums.txt` and `release.json` step, on stand-in files.

Known issue: `runtime/php.ini` never loads. `php_ini_loaded_file()` returns `false`, and `memory_limit` stays at PHP's `128M` default.

Verified locally at `88c9d58`, the release candidate for 0.1.0:

- The seven Linux suites: `database-init` 8s, `initialization` 136s, `network` 32s, `replacement` 26s, `offline` 120s, `server-database` 123s, and 6 `browser.py` tests in 44s.
- The Windows launcher built on Windows 11 at 135 MB, printed its version, and passed `launcher.Tests.ps1` and `site.Tests.ps1`.

Verified on GitHub Actions, run 35228130580 at `fc08bcf`. Every build and test job passed, and `publish` was skipped for the manual dispatch.

| Job | Minutes |
|---|---|
| Linux `amd64`, with the application archive export | 12 |
| Linux `arm64` | 10 |
| Windows `amd64` | 5 |
| macOS `arm64` | 11 |
| macOS `amd64` | 23 |

The Windows and macOS jobs restored their build caches. With cold caches, Windows took 14 minutes in run 35212670832. In run 35221792674, the macOS jobs took 27 minutes on `arm64` and 35 minutes on `amd64` before a test failed. Standard runners cost nothing on the public repository.

## Phase 1: Forge hosting and mirrors

### What to build

Set `origin` to `https://git.tresbien.tech/tresbientech/drupack.git` and push `main`. Add a GitHub deploy key with write access. Store its private key as the `MIRROR_DEPLOY_KEY` Forge repository secret. `.gitea/workflows/mirror.yml` pushes branches and tags to GitHub when a version tag reaches the Forge, or on a manual dispatch. On GitHub, turn off issues and projects.

Create a GitLab project access token on `git.drupalcode.org/project/drupack`, with the Maintainer role and the `write_repository` scope. Store it as the `DRUPAL_ORG_MIRROR_TOKEN` Forge repository secret. The mirror job pushes to drupal.org after GitHub.

### Acceptance criteria

- [x] `main` is on the Forge.
- [x] GitHub issues, projects and wiki are off.
- [x] `main` on GitHub has the same SHA as `main` on the Forge.
- [x] Neither repository has an `implement/*` branch.
- [ ] A version tag pushed to the Forge reaches both mirrors without a manual sync.
- [x] `main` on drupal.org has the same SHA as `main` on the Forge.

## Phase 2: Drupack rename and license

### What to build

Rename the executable, Composer package, environment variables, seed literals, tests and README to Drupack. Add `LICENSE` with the GPL-2.0 text.

### Acceptance criteria

- [x] Outside `docs/`, `git grep -i portable` matches only the `_Avoid_` line in `CONTEXT.md`.
- [x] `DRUPACK_DATA_DIR` selects Site data when `--data-dir` is absent.
- [x] The three Linux test scripts pass on the renamed musl `amd64` executable.
- [x] `LICENSE` holds the GPL-2.0 text.

## Phase 3: Dependency advisories before the first tag

### What to build

`mcp/sdk` 0.6.0 carries GHSA-7m52-jw36-44r3 in its client HTTP transport. `drupal/mcp_tools` 1.0.0-beta18 accepts `mcp/sdk` up to `^0.6` only. Neither MCP module imports `Mcp\Client`. `drupal/composer.json` records the advisory under `config.audit.ignore` with that reason.

`drupal/core` 11.4.7 fixes SA-CORE-2026-013. On 2026-09-17 its French, Chinese, Arabic and Hindi translation exports on ftp.drupal.org were partial. The Docker build downloads the current exports, so builds ship those partial files until drupal.org completes them.

### Acceptance criteria

- [x] `composer audit --locked --working-dir=drupal` lists the `mcp/sdk` advisory as ignored, with its reason.
- [x] `drupal/core` is at 11.4.7 or later.
- [ ] Each core translation file in a release build has a size comparable to the previous core release.
- [ ] Remove the `mcp/sdk` ignore entry once `drupal/mcp_tools` accepts `mcp/sdk` 0.7.1 or later.

## Phase 4: Linux release workflow

User stories: 1, 2, 7-9, 12-14, 16-19.

### What to build

`release.yml` builds the `artifact` target on `ubuntu-24.04` and `ubuntu-24.04-arm`. Each job runs the five test scripts and starts a SQLite site on Alpine. `tests/server-database.sh` installs a site on MySQL 8.4 and PostgreSQL 17 containers, then restarts it without credentials. `tests/replacement.sh` changes a SQLite site, replaces the executable and its extracted application, and checks that Site data is unchanged. On a tag, the `publish` job names the assets and writes `checksums.txt` and `release.json`. It adds a CycloneDX SBOM and creates the GitHub Release.

### Acceptance criteria

- [x] A manual dispatch passes on both architectures and publishes nothing.
- [x] The `arm64` executable passes the same tests as `amd64` without emulation.
- [x] Both executables start a SQLite site on Alpine.
- [x] A manual dispatch passes `tests/server-database.sh` on both architectures.
- [x] A manual dispatch passes `tests/replacement.sh` on both architectures.

## Phase 5: Windows release

User stories: 5-9, 12-19.

### What to build

`packaging/windows/build.ps1` builds the self-extracting launcher on a Windows host. The host needs these tools:

- Visual Studio Build Tools 2022 with its Clang component
- Go
- Git
- PowerShell 7.3 or later

Its inputs are `app.tar` and `app_checksum.txt` from `/go/src/app` in the Dockerfile's `build` stage. It pins FrankenPHP 1.12.7 to commit `a765b086f5cc56f6b7753117367d56e1b0da948d`. It pins PHP 8.5.10 and its devel pack, Watcher 0.14.5 and vcpkg 2026.07.29 by SHA-256 value.

The launcher extracts the runtime into `%LOCALAPPDATA%\Drupack\runtime\<version>` and passes every argument to it unchanged. It hashes each file when it installs the runtime. A later start compares the stored manifest and file sizes. On 2026-09-17 this cut a warm `--help` on Windows 11 from 1.15 s to 0.59 s.

On 2026-09-17, `tests/windows/launcher.Tests.ps1` and `tests/windows/site.Tests.ps1` passed on Windows 11 at `f066463`. A first start in its own console asked there for credentials and wrote no Site data. The Linux test scripts passed locally on the same launcher.

`release.yml` builds and tests it on the `windows-2025` runner and names the asset `drupack-<version>-windows-amd64.exe`.

Remaining:

- Old runtime versions, staging directories and `.invalid-<pid>` directories are never removed.
- Stopping a site started without a console needs the whole process tree stopped.
- No Windows test covers listener access, MySQL, PostgreSQL, help output or executable replacement.
- No test runs on Windows 10 22H2. The runtime bundles no Visual C++ runtime DLLs.
- A WinGet submission needs version, installer and locale manifest files.
- The Windows runtime loads the extensions Drupal, Drush, Local MCP Tools and MCP Server need. The Linux executable loads a larger set.
- `runtime/php.ini` never loads on Windows either.

### Acceptance criteria

- [x] `tests/windows/launcher.Tests.ps1` and `tests/windows/site.Tests.ps1` pass on Windows 11.
- [x] A manual dispatch passes the Windows job on GitHub Actions.
- [x] A failed start without credentials writes no Site data on Windows.

## Phase 6: macOS release

User stories: 3, 4, 7-19.

### What to build

`packaging/macos/build.sh` builds on the target architecture. It pins static-php-cli 2.8.5 by SHA-256 value and FrankenPHP 1.12.7 by commit. static-php-cli compiles PHP 8.5.10 with FrankenPHP's default extension set, which the Linux builder image also uses. `go build` then compiles `caddy/frankenphp` with the Drupack entrypoint and the Linux job's application archive.

`release.yml` runs it on `macos-15` for `arm64` and `macos-15-intel` for `amd64`. Each job runs `tests/database-init.sh`, `tests/browser.py` and `tests/replacement.sh` with GNU coreutils from Homebrew, then checks the documented quarantine removal.

The draft in commit `aa8004a` used `build-static.sh`, whose xcaddy `main` left out the Drupack entrypoint. This phase replaces it.

Remaining:

- `tests/network.sh` needs Docker, which macOS runners lack.
- No test runs on macOS 13.
- The Homebrew formula is not written or published.
- The executables are not signed or notarized.

### Acceptance criteria

- [x] A manual dispatch passes both macOS jobs.
- [x] Both executables start a seeded SQLite site and pass `dr` checks in `tests/browser.py`.
- [ ] The executables load the same PHP extensions as the Linux executable.

## Phase 7: Command-line experience

User stories: 20, 21.

### What to build

A double-click on the Windows executable opens a console window, fails with "Missing Drupal administrator credentials" and closes. `runtime/launch.php` gains an interactive first start, and `packaging/entrypoint.go` gains `--version` and a longer `--help`.

A first start without administrator credentials asks for a name and a password when it can read input. A start with no console parent opens the browser and waits for Enter after an error. `--no-browser` suppresses the browser.

[RFC windows-tray-app](../rfc/windows-tray-app.md) holds the parked tray app design.

### Acceptance criteria

- [ ] A double-click on the Windows executable reaches a served site and opens a browser, with no options.
- [x] A start in its own console without credentials keeps the window open and writes no Site data.
- [x] A non-interactive first start without credentials still fails with the current message and writes no Site data.
- [x] `--version` prints the version, and `--help` lists every option.
- [x] The five test scripts and both Windows test scripts pass.

## Phase 8: Release 0.1.0

### What to build

Tag `0.1.0` on `main` at the Forge after phases 3 to 7.

### Acceptance criteria

- [ ] GitHub Release `0.1.0` holds the five executables, `checksums.txt`, `release.json` and `drupack.cdx.json`.
- [ ] `release.json` lists each asset's target, URL, SHA-256 value and size.
- [ ] `sha256sum -c checksums.txt` passes on the downloaded files.

## Later stages

### Release publication

- Homebrew and WinGet manifests are not published.
- The provenance attestation step has not run, because no version tag exists yet.

### Shared application extraction

- [ADR 0002](../adr/0002-shared-application-extraction.md) proposes one application extraction per release, shared by all Site data directories. On Windows, a new site spends 18.7 s extracting 24,514 files, and each `frankenphp.exe` start takes 0.6 s.

### Executable compression

UPX packed the Linux executable to 123 MB from 417 MB, until a tagged build produced a file that failed UPX's own test. Every method fails, on any machine, for a build that trips it, and a later build of the same source can pass: [UPX issue 836](https://github.com/upx/upx/issues/836) miscalculates a checksum when a segment carries a large `p_align` and a tiny `p_filesz`. PHP's `.remap_stub` segment is 490 bytes aligned to 2 MiB, so every Drupack build has that shape. Releases now ship gzipped executables instead. A packer that works on this layout, or a smaller application, would bring the single-file download back.

### Aggregated asset caching

Drupal writes aggregated CSS and JS under public storage, with a content hash in the file name and a query of `delta`, `language`, `theme` and `include`. The Caddy rules give every file under public storage a revalidating policy, so each aggregate costs one conditional request. A matcher on the aggregate path would let those files claim the immutable policy their names already earn.

### Site owner credentials on the command line

`installDrupal()` passes `--account-pass` to Drush, so the administrator password reaches the install process's argument list, which `ps` shows to every account on the machine. `configureSeedAdministrator()` already passes its values through the environment. Move the install to the same shape.

### drupal.org project

- A drupal.org release needs a release branch such as `0.1.x`. The Forge has only `main`.
- No version tag exists yet, so no push has tested drupal.org's tag rules.

## Out of scope

- Tests on the Forge's Build host.
- Tome, static export, static-host deployment, macOS signing and notarization.
