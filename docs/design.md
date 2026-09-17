# Portable Drupal CMS: first release

> Superseded for platform and packaging by the [multi-platform releases PRD](prd/drupack-multi-platform-releases.md). Linux builds now use musl.

## What will the package provide?

One Linux executable will contain Drupal CMS, the Byte site template, and the FrankenPHP runtime, including PHP and Caddy.
Installation and normal local site use must work without internet access.
The browser runs separately on the host.

This document records agreed requirements. Compatibility has not yet been demonstrated with a working build.
Project terms are defined in [CONTEXT.md](../CONTEXT.md).

## Which platform and build process will it use?

- Target Linux x86-64 with the GNU FrankenPHP build.
- Rely on the host's `glibc`.
- Bundle required PHP extensions, including SQLite support.
- Allow Docker and internet access during the build.
- Resolve application dependencies before embedding the application.

FrankenPHP documents application embedding and GNU builds in its [embedding guide](https://frankenphp.dev/docs/embed/) and [static-build guide](https://frankenphp.dev/docs/static/).

## How will installation and startup work?

- First use starts the Drupal CMS installation process.
- Byte is the only bundled site template.
- The installer collects site details and administrator credentials.
- Subsequent launches reuse the installed site.
- Listen on localhost by default; access from other devices requires explicit configuration.

The startup command, default port, and network configuration interface remain to be defined.

## Where will site data live?

- Default to `./data`, relative to the launch directory.
- Accept `--data-dir` to select another directory.
- Store the SQLite database and persistent writable site files under that directory.
- Preserve site data across restarts and executable replacement.
- Keep the database and private files inaccessible through HTTP.

Preserving data during executable replacement does not establish compatibility between application versions.
The first release has no upgrade command.

## How will languages work?

Bundle the available interface translation files needed for offline use in these languages:

- English
- French
- Simplified Chinese (`zh-hans`)
- Spanish
- Hindi
- Arabic

Let Drupal handle language selection during installation and language management afterward.
Do not preinstall all languages or add a custom language-selection flow.
Content translation must be available through Drupal's normal interfaces.

## What customization is supported?

Users can enable bundled modules and themes.
Adding application code requires rebuilding the executable.
The first release has a fixed application bundle.

Email configuration is deferred. No local mail capture is included in this scope.

## What must be verified before implementation is settled?

- Select compatible, pinned versions of Drupal CMS, FrankenPHP, and PHP.
- Confirm SQLite and PHP extension requirements for the selected application dependencies.
- Complete Byte installation with network access disabled, including all required template assets and dependencies.
- Check translation availability and Drupal's use of bundled translation files without downloads.
- Verify language management and content translation, including Arabic interface layout.
- Determine FrankenPHP's embedded-file extraction behavior and Drupal's required writable paths.
- Confirm that installation settings, generated files, and uploads use the intended persistent locations.

## What will demonstrate completion?

- Build the executable with Docker.
- Run it on a compatible Linux host without separately installed PHP, Composer, or a database server.
- Install Byte and administer the site with network access disabled.
- Create content and upload a file, then confirm both survive a process restart.
- Exercise Drupal's language workflow with the bundled translations while offline.
- Repeat installation with a custom data directory.
- Confirm HTTP requests cannot retrieve the database or private files.
