# Portable Drupal CMS

## Problem statement

A site owner wants to install and use Drupal CMS from one executable on Linux.
They need a working website without separately installing PHP, a web server, or a database server.
Installation and normal local use must work without internet access.
Site data must persist independently of the executable.

## Solution

Distribute a Linux x86-64 GNU FrankenPHP executable with Drupal CMS and the Byte site template embedded.
Bundle PHP, Caddy, required PHP extensions, application dependencies, and available interface translations.
The host supplies compatible `glibc` and a browser.

First launch presents Drupal CMS's installation process with Byte as the sole template.
Later launches reuse the installed site and SQLite database.
The default data directory is `./data`, relative to the launch directory; `--data-dir` selects another location.
Access defaults to localhost, with network access explicitly configured.

Use Drupal's standard language management and content translation interfaces.
Bundle language resources for English, French, Simplified Chinese, Spanish, Hindi, and Arabic without preinstalling all languages.
The application bundle is fixed for the first release.

## User stories

### Distribution and startup

1. As a site owner, I want one executable, so that I can run Drupal CMS without assembling its runtime.
2. As a Linux user, I want an x86-64 build, so that I can run it on my compatible Intel or AMD computer.
3. As a site owner, I want offline startup, so that a network connection is unnecessary for local use.
4. As a site owner, I want localhost access by default, so that other devices cannot access my site automatically.
5. As a site owner, I want explicit network configuration, so that I can choose whether other devices can access the site.

### Installation

6. As a site owner, I want Drupal's installer on first launch, so that I can configure my own site.
7. As a site owner, I want Byte bundled, so that its starting configuration is available offline.
8. As a site owner, I want to enter site details and administrator credentials, so that the installation belongs to me.
9. As a site owner, I want SQLite, so that I need no separate database server.
10. As a site owner, I want installation to finish offline, so that setup has no required downloads.

### Persistent site data

11. As a site owner, I want a predictable default data directory, so that I can locate my site's persistent files.
12. As a site owner, I want to select the data directory, so that I can use my preferred storage location.
13. As an editor, I want content and uploads to survive restarts, so that stopping the process preserves my work.
14. As a site owner, I want subsequent launches to reuse my installation, so that I do not repeat setup.
15. As a site owner, I want private data protected from HTTP downloads, so that visitors cannot retrieve database or private files directly.

### Languages and content

16. As a site owner, I want the six agreed interface languages available offline, so that language setup needs no downloads.
17. As a site owner, I want Drupal's normal language selection, so that I control which languages are installed.
18. As an editor, I want Drupal's content translation workflow, so that I can publish content in multiple languages.
19. As an Arabic-speaking editor, I want a usable right-to-left interface, so that I can administer the site in Arabic.
20. As an editor, I want to create content and upload files offline, so that I can work without connectivity.

### Application maintenance

21. As a site owner, I want to enable bundled modules and themes, so that I can use the included functionality.
22. As a maintainer, I want Docker builds with internet access, so that I can assemble dependencies before distribution.
23. As a maintainer, I want pinned dependencies, so that each release has a defined application and runtime composition.
24. As a maintainer, I want to rebuild the executable when adding code, so that the distributed application remains a fixed bundle.
25. As a site owner, I want executable replacement to preserve site data, so that replacing the program does not erase my installation.

## Implementation decisions

### Module boundaries

- Build and packaging accepts pinned application and runtime inputs and produces the distributable executable.
- It assembles Byte, required extensions, dependencies, and translation resources before embedding.
- Startup and site storage accepts launch configuration and a selected data directory and starts the site.
- It resolves persistent paths and connects Drupal's installation or existing site to FrankenPHP.
- These are responsibility boundaries; implementation should reuse upstream capabilities and avoid unnecessary wrapper layers.

### Runtime and persistence

- Use the GNU FrankenPHP build for Linux x86-64, with host-provided `glibc`.
- Embed the fixed Drupal CMS application and Byte template.
- Use SQLite with the required PHP database extension bundled.
- Persist the database and writable site files outside the executable.
- Keep database files and private site files inaccessible through HTTP.
- Default to loopback access; require explicit configuration for access from other devices.
- Delegate installation state and language workflows to Drupal where supported.

### Language behavior

- Support English, French, Simplified Chinese (`zh-hans`), Spanish, Hindi, and Arabic.
- Package available upstream translations during the build.
- Let the user select languages through Drupal's normal interfaces.
- Make content translation available through Drupal.
- Preserve user control over installed languages; provide no custom language installer.

## Testing decisions

### Test approach

Test externally observable behavior at the approved module boundaries.
Use the built executable for integration tests, with outbound network access disabled for offline scenarios.
Verify behavior through process execution, browser interactions, HTTP responses, and persisted data.
Do not duplicate upstream Drupal tests or assert internal implementation structure.

The repository has no implementation or existing test suite to use as prior art.

### Build and packaging coverage

- Build the GNU x86-64 artifact using Docker.
- Run it on a compatible host without separately installed PHP, Composer, a web server, or a database server.
- Complete Byte installation offline to verify required dependencies and assets are bundled.
- Exercise bundled translations through Drupal's language workflow while offline.

### Startup and site storage coverage

- Verify the default data location and a custom data directory.
- Complete first installation, create content, upload a file, and restart the process.
- Confirm the installed site, credentials, content, and uploads remain usable after restart.
- Verify database and private-file requests cannot retrieve their contents.
- Verify default loopback access and explicitly enabled network access.
- Confirm replacing the executable with the same build preserves the existing site.

### Drupal workflow coverage

- Exercise normal language selection without automatically installing all six languages.
- Enable an additional bundled language through Drupal while offline.
- Create and retrieve translated content through Drupal's standard workflow.
- Check Arabic interface layout and administration tasks.
- Confirm bundled module and theme activation remains usable.

## Out of scope

- Platforms beyond Linux x86-64 GNU builds.
- Downloading or adding application code to a running installation.
- Upgrade commands, automated migrations between releases, and cross-version compatibility guarantees.
- Email configuration, delivery setup, and local mail capture.
- Custom language installation or content translation interfaces.
- Preinstalling every bundled language.
- Additional site templates.
- Offline operation of features that inherently require external services.

## Further notes

### Open compatibility checks

- Select compatible versions of Drupal CMS, FrankenPHP, and PHP, including SQLite and extension requirements.
- Verify Byte installation can complete with all required resources bundled and no network access.
- Determine FrankenPHP's extraction behavior and map Drupal's writable paths into persistent storage.
- Confirm Drupal can discover and import bundled translation files offline.
- Check upstream translation coverage; complete translation of every string has not been established.
- Verify content translation support with the selected Byte configuration.
- Define the launch command, default port, and explicit network configuration interface.
- Specify the supported Linux distribution and `glibc` baseline before release.

### Status and references

This PRD records approved scope and test coverage. No implementation or compatibility test has been completed.
Preserving site data during executable replacement does not guarantee that another application version can use it.

- [Agreed design](../design.md)
- [Project glossary](../../CONTEXT.md)
- [FrankenPHP embedding guide](https://frankenphp.dev/docs/embed/)
- [FrankenPHP static-build guide](https://frankenphp.dev/docs/static/)
- [Drupal CMS setup guide](https://project.pages.drupalcode.org/drupal_cms/get-started/setup/)
