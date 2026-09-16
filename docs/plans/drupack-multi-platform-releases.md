# Plan: Drupack multi-platform releases

> Sources: [PRD](../prd/drupack-multi-platform-releases.md), [ADR 0001](../adr/0001-forge-canonical-github-packaging-mirror.md) and the [project glossary](../../CONTEXT.md)

## Architectural decisions

- The Forge repository `https://git.tresbien.tech/tresbientech/drupack` is canonical.
- GitHub `tresbientech/drupack` is a private mirror with full history. It builds and publishes releases.
- A Forge Actions job pushes branches and tags to GitHub with an SSH deploy key stored as a Forge secret.
- Only `main` and version tags are pushed to the Forge.
- Version tags have no `v` prefix, for example `0.1.0`.
- Build workflows run on a version tag or a manual dispatch.
- The product is named Drupack everywhere. Public environment variables use the `DRUPACK_` prefix.
- Variables that the launcher sets for its own runtime use the `DRUPACK_RUNTIME_` prefix.
- Linux `amd64` and `arm64` executables use musl.
- macOS `arm64` and `amd64` executables are native builds for macOS 13 and later.
- Windows `amd64` is one self-extracting `.exe` for Windows 10 22H2 and later.
- Every target keeps the same CLI options, environment variables, default `./data` directory, seeded SQLite site, database drivers and `dr` command.
- Site data stays outside immutable application files. A runtime update stages, verifies, then activates. A failed activation leaves the prior runtime active.
- Each GitHub Release has versioned assets, `checksums.txt`, an SBOM, provenance attestations and `release.json`.
- A Homebrew tap installs Linux and macOS assets. WinGet installs the Windows asset.
- The license is GPL-2.0-or-later.

## Status

Code exists for every phase. Native validation has not run.

Verified locally:

- Linux `amd64` musl build, Alpine `--help`, database argument checks, shell syntax, PHP syntax, and Windows launcher cross-compilation.

Known gaps:

- `release.yml` writes `release.json` without the per-asset URL, SHA-256 value and size.
- The Windows workflow downloads the newest PHP and Watcher releases. Linux and macOS pin PHP 8.5.10.
- The Windows test uses a stub runtime. No test starts Drupal on Windows.
- The Homebrew and WinGet manifests have not been checked with their upstream tools.

## Phase 1: Forge hosting and GitHub mirror

### What to build

Set `origin` to `https://git.tresbien.tech/tresbientech/drupack.git` and push `main`. Add a GitHub deploy key with write access. Store its private key as the `MIRROR_DEPLOY_KEY` Forge repository secret. `.gitea/workflows/mirror.yml` pushes branches and tags to GitHub on every Forge push. On GitHub, turn off issues and projects.

### Acceptance criteria

- [x] `main` is on the Forge.
- [x] GitHub issues, projects and wiki are off.
- [ ] `main` on GitHub has the same SHA as `main` on the Forge.
- [ ] Neither repository has an `implement/*` branch.
- [ ] A commit pushed to the Forge reaches GitHub without a manual sync.

## Phase 2: Drupack rename and license

### What to build

Rename the executable, Composer package, environment variables, seed literals, tests and README to Drupack. Refresh the translation snapshot, because the Composer package name changes the lock hash. Add `LICENSE` with the GPL-2.0 text.

### Acceptance criteria

- [ ] Outside `docs/`, `git grep -i portable` matches only the `_Avoid_` line in `CONTEXT.md` and WinGet's `InstallerType: portable`.
- [ ] `DRUPACK_DATA_DIR` selects Site data when `--data-dir` is absent.
- [ ] The three Linux test scripts pass on the renamed musl `amd64` executable.
- [x] `LICENSE` holds the GPL-2.0 text.

## Phase 3: Dependency advisories before the first tag

### What to build

Clear or record each `composer audit` advisory before tagging `0.1.0`. `mcp/sdk` 0.6.0 carries GHSA-7m52-jw36-44r3, fixed in 0.7.1. `drupal/mcp_tools` 1.0.0-beta18 accepts `mcp/sdk` up to `^0.6` only. Neither MCP module imports the affected `Mcp\Client` namespace.

`drupal/core` 11.4.6 carries SA-CORE-2026-013, fixed in 11.4.7. The 11.4.7 core translation exports on ftp.drupal.org were partial on 2026-09-17. Update core once they are complete, then refresh the translation snapshot.

### Acceptance criteria

- [ ] `composer audit --locked` reports no advisory, or each remaining advisory has a recorded decision.
- [ ] `drupal/core` is at 11.4.7 or later.
- [ ] Each core translation file in the snapshot has a size comparable to the previous release.

## Phase 4: Linux musl amd64 release

User stories: 1, 7-9, 12-14, 16-19.

### What to build

Build one musl `amd64` Drupack executable from the application archive. Run the first-start, database, Drush, MCP Tools, restart and protected-path checks on native Linux. Package a release candidate with a checksum and `release.json`.

### Acceptance criteria

- [ ] The Linux `amd64` executable runs on a musl host and a glibc host without host PHP or Composer.
- [ ] SQLite, MySQL, PostgreSQL, Drush, MCP Tools and MCP Server source remain available.
- [ ] `release.json` identifies the artifact, version, URL, SHA-256 value, size and build commit.
- [ ] The native Linux test job passes on GitHub Actions.

## Phase 5: Linux musl arm64 release

User stories: 2, 7-9, 12-14, 16-19.

### What to build

Add the `arm64` Linux release to the same application and release contract. Run its test path on a native ARM runner. The artifact supports 64-bit Raspberry Pi systems and ARM Linux hosts.

### Acceptance criteria

- [ ] The Linux `arm64` executable passes the same first-start and restart checks as `amd64`.
- [ ] The `arm64` executable completes MySQL and PostgreSQL first installation.
- [ ] The release candidate has a separate `arm64` asset and manifest entry.
- [ ] The native ARM test job passes without emulation.

## Phase 6: macOS Apple Silicon release

User stories: 3, 7-9, 12-19.

### What to build

Build the application archive into a native macOS Apple Silicon executable with FrankenPHP's static build script. Release the `macos-arm64` artifact with the same CLI behavior and documented unsigned launch steps.

### Acceptance criteria

- [ ] The macOS `arm64` executable starts a seeded SQLite site on macOS 13 or later.
- [ ] The executable completes MySQL and PostgreSQL first installation.
- [ ] Drush, MCP Tools, restart behavior and protected HTTP paths match the Linux contract.
- [ ] Release documentation gives the unsigned-binary launch and checksum verification steps.

## Phase 7: macOS Intel and Homebrew release

User stories: 4, 10, 12-19.

### What to build

Add a native Intel macOS artifact built on the `macos-15-intel` runner. GitHub retired `macos-13`. Publish a Homebrew tap formula that selects the matching Linux or macOS asset and verifies its SHA-256 value.

### Acceptance criteria

- [ ] The macOS `amd64` executable passes the macOS release checks on native Intel hardware.
- [ ] The Homebrew formula installs each Linux and macOS target from a versioned release URL.
- [ ] Homebrew checks the matching asset hash before installation.
- [ ] Formula tests confirm `drupack --help` resolves after installation.

## Phase 8: Windows self-extracting release

User stories: 5-9, 12-19.

### What to build

Package FrankenPHP, the PHP runtime files, the application archive and the launcher in one Windows `amd64` executable. On first start, extract immutable runtime files into a versioned Site data directory. Verify checksums before activation. Keep the prior runtime if extraction fails.

### Acceptance criteria

- [ ] One Windows `.exe` download starts Drupack without a separate PHP or FrankenPHP installation.
- [ ] The first start creates valid Site data and starts the seeded SQLite site.
- [ ] SQLite, MySQL, PostgreSQL, Drush, MCP Tools and MCP Server source match the Linux contract.
- [ ] A forced extraction failure prints a warning and leaves the prior runtime active.
- [ ] Native Windows tests cover first extraction, restart, HTTP protection and executable replacement.

## Phase 9: WinGet and verified release publication

User stories: 11-19.

### What to build

Publish a WinGet portable manifest for the Windows release. Generate SBOMs and provenance attestations for every asset. The release workflow publishes only after every native target reports success for the tag.

### Acceptance criteria

- [ ] The WinGet manifest installs the Windows asset and declares its SHA-256 value.
- [ ] Each release includes `checksums.txt`, an SBOM, provenance attestations and `release.json`.
- [ ] Each release asset has a successful native test result before publication.
- [ ] Replacing an executable preserves existing Site data on every target.

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
