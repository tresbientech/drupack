# Plan: Drupack multi-platform releases

> Sources: [PRD](../prd/drupack-multi-platform-releases.md), [ADR 0001](../adr/0001-forge-canonical-github-packaging-mirror.md) and the [project glossary](../../CONTEXT.md)

## Architectural decisions

- The Forge repository `https://git.tresbien.tech/tresbientech/drupack` is canonical.
- GitHub `tresbientech/drupack` is a private mirror with full history. It builds and publishes releases.
- A Forge Actions job pushes branches and tags to GitHub with an SSH deploy key stored as a Forge secret.
- Only `main` and version tags are pushed to the Forge.
- Version tags have no `v` prefix, for example `0.1.0`.
- One GitHub workflow, `release.yml`, runs on a version tag or a manual dispatch.
- The product is named Drupack everywhere. Public environment variables use the `DRUPACK_` prefix.
- Variables that the launcher sets for its own runtime use the `DRUPACK_RUNTIME_` prefix.
- The first release ships Linux `amd64` and `arm64` musl executables. macOS and Windows are later stages.
- Every target keeps the same CLI options, environment variables, default `./data` directory, seeded SQLite site, database drivers and `dr` command.
- A GitHub Release holds the executables, `checksums.txt`, `release.json` and one CycloneDX SBOM.
- Provenance attestations start when the GitHub repository becomes public.
- The license is GPL-2.0-or-later.

## Status

Verified locally on Linux `amd64`:

- The musl build, `DRUPACK_DATA_DIR` and `DRUPACK_ADMIN_*`, and a SQLite first start.
- `tests/database-init.sh`, `tests/offline.sh` and `tests/network.sh`.
- The `release.yml` asset naming, `checksums.txt` and `release.json` step, on stand-in files.

Verified on GitHub Actions, run 35187565454 at `be0af43`:

- Both Linux jobs built, passed the three test scripts and started a SQLite site on Alpine.
- `amd64` took 10 minutes and `arm64` took 11 minutes. `publish` was skipped for the manual dispatch.

## Phase 1: Forge hosting and GitHub mirror

### What to build

Set `origin` to `https://git.tresbien.tech/tresbientech/drupack.git` and push `main`. Add a GitHub deploy key with write access. Store its private key as the `MIRROR_DEPLOY_KEY` Forge repository secret. `.gitea/workflows/mirror.yml` pushes branches and tags to GitHub on every Forge push. On GitHub, turn off issues and projects.

### Acceptance criteria

- [x] `main` is on the Forge.
- [x] GitHub issues, projects and wiki are off.
- [x] `main` on GitHub has the same SHA as `main` on the Forge.
- [x] Neither repository has an `implement/*` branch.
- [x] A commit pushed to the Forge reaches GitHub without a manual sync.

## Phase 2: Drupack rename and license

### What to build

Rename the executable, Composer package, environment variables, seed literals, tests and README to Drupack. Refresh the translation snapshot, because the Composer package name changes the lock hash. Add `LICENSE` with the GPL-2.0 text.

### Acceptance criteria

- [x] Outside `docs/`, `git grep -i portable` matches only the `_Avoid_` line in `CONTEXT.md`.
- [x] `DRUPACK_DATA_DIR` selects Site data when `--data-dir` is absent.
- [x] The three Linux test scripts pass on the renamed musl `amd64` executable.
- [x] `LICENSE` holds the GPL-2.0 text.

## Phase 3: Dependency advisories before the first tag

### What to build

`mcp/sdk` 0.6.0 carries GHSA-7m52-jw36-44r3 in its client HTTP transport. `drupal/mcp_tools` 1.0.0-beta18 accepts `mcp/sdk` up to `^0.6` only. Neither MCP module imports `Mcp\Client`. `composer.json` records the advisory under `config.audit.ignore` with that reason.

`drupal/core` 11.4.7 fixes SA-CORE-2026-013. On 2026-09-17 its French, Chinese, Arabic and Hindi translation exports on ftp.drupal.org were partial. The snapshot holds those partial files. Refresh it once the exports are complete.

### Acceptance criteria

- [x] `composer audit --locked` lists the `mcp/sdk` advisory as ignored, with its reason.
- [x] `drupal/core` is at 11.4.7 or later.
- [ ] Each core translation file in the snapshot has a size comparable to the previous release.
- [ ] Remove the `mcp/sdk` ignore entry once `drupal/mcp_tools` accepts `mcp/sdk` 0.7.1 or later.

## Phase 4: Linux release workflow

User stories: 1, 2, 7-9, 12-14, 16-19.

### What to build

`release.yml` builds the `artifact` target on `ubuntu-24.04` and `ubuntu-24.04-arm`. Each job runs the three test scripts and starts a SQLite site on Alpine. On a tag, the `publish` job names the assets and writes `checksums.txt` and `release.json`. It adds a CycloneDX SBOM and creates the GitHub Release.

### Acceptance criteria

- [x] A manual dispatch passes on both architectures and publishes nothing.
- [x] The `arm64` executable passes the same tests as `amd64` without emulation.
- [x] Both executables start a SQLite site on Alpine.
- [ ] A test installs a site on MySQL and on PostgreSQL service containers.
- [ ] A test replaces the executable and confirms Site data remains intact.

## Phase 5: Release 0.1.0

### What to build

Tag `0.1.0` on `main` at the Forge after phases 3 and 4.

### Acceptance criteria

- [ ] GitHub Release `0.1.0` holds both executables, `checksums.txt`, `release.json` and `drupack.cdx.json`.
- [ ] `release.json` lists each asset's target, URL, SHA-256 value and size.
- [ ] `sha256sum -c checksums.txt` passes on the downloaded files.

## Later stages

Commit `aa8004a` holds a draft of the macOS, Windows, Homebrew and WinGet code. It never ran on a native runner. Branch audits found these problems in it.

### macOS and Homebrew

- `build-static.sh` builds with xcaddy, which writes its own `main`. The Drupack entrypoint probably never compiles.
- The macOS job built its own application archive, so its Seed site differed from Linux.
- It copied the FrankenPHP version, PHP version and a reduced extension list from the Dockerfile. The Linux build uses the builder image's full extension set.
- No document gives the unsigned-binary launch steps, and no test covers them.
- The Homebrew template was never filled in or published.
- macOS runners cost $0.062 per minute on a private repository.

### Windows and WinGet

- No `php.ini` enables the extension DLLs, so the database drivers never load.
- The launcher's restart check compared a path FrankenPHP never uses on Windows. Each retry started another process.
- PHP and Watcher downloads were unpinned and unchecked. Pins to use: PHP 8.5.10 TS x64 zip `a6bc8b2f3d7bfb397ccb973db2f959e61e530e0986c9cea262dd4a317ec599d8`, devel pack `0031d279f13f21e81fd62f9a98e919f28b1875ba457916d60daed85586e479dd`, Watcher 0.14.5 `43034cbb07252751246afab8880da74c0340429f1865fd5bd6ce7c17e83c104f`.
- The only test used a stub runtime. No test started Drupal, checked HTTP protection or replaced the executable.
- The runtime bundles no Visual C++ runtime DLLs. This is unconfirmed on a clean Windows 10 host.
- The WinGet template was one of the three required manifest files and was never published.

### Release publication

- Homebrew and WinGet installs need public release assets.
- Provenance attestations need a public repository.

## Out of scope

- Making the GitHub repository public.
- Tests on the Forge's Build host.
- Tome, static export, static-host deployment, macOS signing and notarization.
- A drupal.org mirror. Before adding one, check:
  - whether a drupal.org SSH key can push to every project its owner maintains
  - drupal.org naming rules for release branches and tags
  - drupal.org policy on bundled files such as `translations.tar.gz` and `composer.lock`
  - whether the Composer name must be `drupal/drupack`
  - how drupal.org merge requests land through the Forge
