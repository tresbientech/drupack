# Drupack multi-platform releases

## Problem statement

Drupack currently ships one Linux x86-64 executable. Site owners on macOS, Windows, ARM Linux, and Raspberry Pi cannot use the product.

People expect a native download or package-manager install for their operating system. Automation needs stable asset names, checksums, and machine-readable release metadata.

The executable must keep Drupal CMS, FrankenPHP, PHP, Drush, SQLite, MySQL, PostgreSQL, MCP Tools, and MCP Server source. Site data must remain outside the Packaged site.

## Solution

Ship native Drupack releases in three stages. The Linux stage ships fully static musl executables for `amd64` and `arm64`.

The macOS stage ships native executables for Apple Silicon and Intel. The Windows stage ships a self-extracting `amd64` executable.

Every target keeps the same command-line flags, environment variables, default `./data` directory, seeded SQLite site, database choices, and `dr` command. The Windows executable extracts its immutable FrankenPHP and PHP runtime into a per-user cache after checksum verification.

GitHub Releases is the canonical distribution channel. The Forge holds the canonical source, and GitHub mirrors it. The GitHub repository is public.

A Homebrew tap installs Linux and macOS releases. WinGet installs the Windows portable executable.

## User stories

1. As a Linux user, I want a musl `amd64` executable, so that I can run Drupack on common Intel and AMD distributions.
2. As a Linux ARM user, I want an `arm64` executable, so that I can run Drupack on a 64-bit Raspberry Pi or ARM server.
3. As a macOS Apple Silicon user, I want a native `arm64` executable, so that I can run Drupack without Rosetta.
4. As a macOS Intel user, I want a native `amd64` executable, so that I can run Drupack on supported Intel Macs.
5. As a Windows user, I want one `.exe` download, so that I do not install PHP or FrankenPHP separately.
6. As a Windows user, I want the executable to unpack its runtime into a per-user cache, so that the application download remains one file.
7. As a site owner, I want the same first-start workflow on every target, so that operating systems do not change site setup.
8. As a site owner, I want SQLite, MySQL, and PostgreSQL available on every target, so that database choice remains portable.
9. As an administrator, I want Drush and MCP Tools on every target, so that administration commands remain portable.
10. As a Homebrew user, I want a tap formula, so that I can install Drupack with `brew`.
11. As a Windows package-manager user, I want a WinGet package, so that I can install and update Drupack with `winget`.
12. As an automation author, I want stable release names, so that scripts can select the correct target.
13. As an automation author, I want `release.json`, so that scripts can discover assets, versions, URLs, sizes, and SHA-256 values.
14. As a site owner, I want checksums and provenance records, so that I can verify downloaded executables.
15. As a macOS user, I want documented unsigned-binary launch steps, so that I can run the release before code signing is available.
16. As a site owner, I want old Site data to survive executable replacement, so that application updates preserve my site.
17. As a site owner, I want a failed runtime extraction to keep the previous runtime, so that a damaged update does not block my site.
18. As a maintainer, I want native build and test jobs, so that each release is validated on its target operating system.
19. As a maintainer, I want release tests for database setup and protected paths, so that every target preserves the existing product contract.

## Implementation decisions

### Source, tags and triggers

- The Forge repository is canonical. GitHub and drupal.org are push mirrors, per [ADR 0001](../adr/0001-forge-canonical-github-packaging-mirror.md).
- Version tags have no `v` prefix, for example `0.1.0`.
- Build workflows run on a version tag or a manual dispatch.
- Public environment variables use the `DRUPACK_` prefix.
- Standard GitHub-hosted runners, macOS included, cost nothing on the public repository.

### Release targets

- The Linux stage ships `drupack-<version>-linux-amd64` and `drupack-<version>-linux-arm64` as fully static musl executables.
- The macOS stage ships `drupack-<version>-macos-arm64` and `drupack-<version>-macos-amd64` as native macOS executables.
- The Windows stage ships `drupack-<version>-windows-amd64.exe` as a self-extracting Windows executable.
- Linux supports `amd64` and `arm64` distributions without a distribution-specific version promise.
- macOS supports version 13 and later. Windows supports version 10 22H2 and later.
- macOS releases remain unsigned until Apple code-signing credentials are available.

### Build and runtime modules

- A shared application archive contains Drupal CMS, seed data, configuration, Drush, MCP Tools, MCP Server source, and required PHP assets.
- A release manifest defines target names, release version, checksums, and runtime metadata.
- Linux builds use the FrankenPHP musl builder with every required PHP extension compiled into the executable.
- macOS builds run on native macOS build hosts with the FrankenPHP static build script and the same application archive.
- The Windows launcher embeds the Windows FrankenPHP and PHP runtime files with the application archive.
- The Windows launcher extracts immutable files into `%LOCALAPPDATA%\Drupack\runtime\<version>`. It verifies all files before activation.
- The Windows launcher passes every argument to the runtime unchanged. A failed start writes no Site data.
- The launcher retains the prior runtime when extraction or verification fails. It prints a warning and runs the prior runtime without changing the active runtime.
- Each target exposes the current CLI contract without shell-specific aliases.

### Distribution modules

- GitHub Releases publishes every target file, `checksums.txt`, an SBOM, provenance attestations, and `release.json`.
- Provenance attestations cover every file in a GitHub Release.
- `release.json` lists version, target, asset name, download URL, SHA-256, file size, and build commit.
- The Homebrew tap has a formula that installs the matching Linux or macOS asset after hash verification.
- The WinGet manifest declares the Windows file as a portable installer and supplies its SHA-256 value.
- Package-manager manifests refer to immutable, versioned GitHub Release asset URLs.

### Test modules

- Linux, macOS, and Windows jobs run on native GitHub Actions runners.
- Each job tests the executable help output, checksum, and release manifest entry.
- Each job tests the SQLite first start, restart, Drush status, MCP Tools configuration, and HTTP path protection.
- Each job tests MySQL and PostgreSQL first installation where a supported test service is available.
- Windows tests verify first extraction, a failed extraction rollback, and WinGet installation.
- macOS tests verify the documented unsigned launch path.
- Update tests start a site with one release, replace the executable, and confirm Site data remains intact.

## Testing decisions

### Test approach

Tests exercise release artifacts through their public commands and HTTP responses. Tests do not assert build-stage internals.

The existing offline, browser, database, and network tests define the current product behavior. Each target adopts those tests with platform-specific setup code.

### Acceptance coverage

- Build every Linux and macOS target from the same locked Drupal dependency set.
- Verify each Linux and macOS target runs without a host PHP installation, Composer installation, or database server for SQLite use.
- Verify each Linux and macOS target retains MySQL, PostgreSQL, Drush, MCP Tools, and MCP Server source.
- Verify the Windows executable installs all required immutable runtime files into its per-user cache.
- Verify a failed Windows extraction leaves the active runtime unchanged.
- Verify `release.json`, checksums, SBOMs, and provenance attestations identify every release asset.
- Verify Homebrew and WinGet metadata refer to the release asset and its SHA-256 value.

## Out of scope

- Making the GitHub repository public.
- Static export, Tome integration, editor-only service mode, and static-host deployment.
- Windows `arm64` and 32-bit targets.
- Linux distribution repositories for Debian, RPM, or Alpine packages.
- macOS code signing and notarization.
- Automatic application updates, schema migrations, and cross-version compatibility guarantees.
- Runtime Composer operations and installing contributed modules after release.
- A graphical desktop application.

## Further notes

### Windows packaging research

FrankenPHP's published Windows build uses PHP runtime DLLs. Its static embedding tooling supports Linux and macOS, not Windows. The Windows phase therefore packages the required runtime files inside one executable and extracts them into a per-user cache.

This extraction can trigger antivirus reputation warnings before code signing. The release documentation must state this condition and provide checksum verification instructions.

### Deferred static-site work

Tome Static can export anonymous Drupal pages to HTML. It needs a configured export directory, build command, static-file serving policy, output validation, and deployment workflow. That work belongs in a later PRD.

### References

- [FrankenPHP static builds](https://frankenphp.dev/docs/static/)
- [FrankenPHP embedded applications](https://frankenphp.dev/docs/embed/)
- [FrankenPHP Windows build workflow](https://github.com/php/frankenphp/blob/main/.github/workflows/windows.yaml)
- [GitHub Release API](https://docs.github.com/en/rest/releases)
- [GitHub artifact attestations](https://docs.github.com/en/actions/how-tos/secure-your-work/use-artifact-attestations/use-artifact-attestations)
- [Homebrew Formula Cookbook](https://docs.brew.sh/Formula-Cookbook)
- [WinGet package manifests](https://learn.microsoft.com/en-us/windows/package-manager/package/manifest)
