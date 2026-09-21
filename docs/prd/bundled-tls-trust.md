# Bundled TLS trust and the runtime php.ini

## Problem Statement

A Windows site owner opens the status report and reads "Failed to fetch
available update data", with a pointer to the PHP OpenSSL handbook. The log
carries the cause: `cURL error 60`, unable to get the local issuer certificate
for `updates.drupal.org`. Nothing on their machine is broken, and no setting of
theirs repairs it. The same site on Linux or macOS reports its updates.

Every other outbound HTTPS call fails the same way on Windows. Remote media,
Drush, and any module a site owner enables later all hit the same wall.

A second fault has been invisible. The PHP settings the project ships never
reach a release start on Linux or macOS. Those starts run at PHP's 128M default
instead of the intended 512M, while the development server applies the file in
full. The two disagree, so a limit a developer tests against is not the limit a
site owner gets.

## Solution

A Packaged site carries its own certificate set and trusts it on all three
platforms. Update status fetches. Remote media loads. A site behaves the same
whether the host machine keeps a CA store or not.

A machine behind a TLS-inspection proxy points `DRUPACK_CA_FILE` at its own
bundle, and the site trusts that instead.

One php.ini reaches every platform and every start. The limits a release
applies are the limits the development server applies, and the file that sets
them lives in one place.

## User Stories

### Outbound HTTPS

1. As a Windows site owner, I want available update data to load, so that I
   learn when my site is out of date.
2. As a Windows site owner, I want no OpenSSL error in my status report, so
   that I stop hunting a fault on my own machine.
3. As a site owner, I want remote images and oEmbed media to load, so that
   content from other sites appears in mine.
4. As a site owner, I want a module I enable later to reach its own service, so
   that the packaged PHP behaves like any other PHP.
5. As a Drush user, I want a command that calls out to succeed, so that `dr`
   covers the same ground as the web interface.
6. As a Linux user in a minimal container, I want TLS to work with no host CA
   package installed, so that the executable stays self-contained.
7. As a macOS user, I want the same certificate set as Linux and Windows, so
   that one site behaves the same on every machine I own.
8. As a site owner, I want the certificates inside the executable, so that
   nothing has to be installed beside it.

### Trust I can steer and audit

9. As a user behind a TLS-inspection proxy, I want to name my own bundle, so
   that my site works on a corporate laptop.
10. As that user, I want the variable named in the README beside the other
    options, so that I find it without reading the source.
11. As a security-minded owner, I want the shipped certificate set recorded
    with its source and checksum, so that I can audit what I trust.
12. As an auditor, I want a refresh of that set to appear as a commit, so that
    a change of trust is visible in history.
13. As a maintainer, I want the daily watch job to notice an aged bundle, so
    that a release refreshes it on schedule.

### PHP settings that apply

14. As a site owner importing content, I want the 512M limit to apply, so that
    a large operation stops failing at 128M.
15. As a Windows site owner, I want the same limits as Linux and macOS, so that
    a site moved between machines behaves alike.
16. As a developer, I want the development server and the release to run the
    same settings, so that a bug reproduces where I can debug it.
17. As a site owner, I want the documented 64M upload limit to hold, so that a
    file the interface accepts actually uploads.
18. As a site owner, I want errors in the log and off the page, so that a
    visitor never reads a PHP notice.
19. As a person who keeps a php.ini in their own folder, I want a start to
    ignore it, so that the site behaves the same in every directory.

### Building and releasing

20. As a maintainer, I want one php.ini behind all three builds, so that a
    setting changes in one place.
21. As a maintainer, I want the Windows build to append its extension lines to
    that file, so that it stops authoring settings of its own.
22. As a maintainer, I want the environment rules in one function, so that the
    two platform entry points cannot drift apart.
23. As a maintainer, I want both new files packed by the existing packer, so
    that no build step lists them twice.
24. As a release engineer, I want the change to need no new download at build
    time, so that a build stays reproducible offline.

### Proof

25. As a maintainer, I want a case proving the ini loads on each platform, so
    that a silent regression cannot return.
26. As a maintainer, I want a case proving the certificate path resolves to a
    parseable set, so that a truncated copy fails the run.
27. As a maintainer, I want one case fetching the Drupal release history over
    HTTPS, so that real verification is proven, not assumed.
28. As a maintainer, I want the offline suite to stay green, so that a site
    with no network still installs.
29. As a maintainer, I want unit tests for the precedence rules, so that a
    mistake surfaces without a full platform run.

### Sites that already exist

30. As the owner of a site installed before this change, I want the error to
    clear on the next update check, so that no repair step falls to me.

## Implementation Decisions

- Trust anchors ship as a Mozilla certificate bundle vendored in the
  repository. Its source URL and SHA-256 sit beside the copy step, and a
  refresh is one commit.
- Rejected: a pinned download inside each build script, which puts one URL and
  one hash in three scripts in three languages. Rejected: the build host's own
  store, which gives each platform a different trust set and records none of
  it.
- Rejected: exporting the Windows root store at start. Windows fills that store
  on demand from its update list, so a fresh machine holds few roots, and an
  uncached issuer still fails.
- The launcher sets `PHPRC` to the unpacked runtime directory on every
  platform. FrankenPHP's static binary otherwise reads php.ini from the
  directory it runs in, which the launcher never controls.
- `curl.cainfo` and `openssl.cafile` both read `${DRUPACK_CA_FILE}`. The
  launcher points that variable at the bundled file when the caller has not set
  it, so an override needs no edit inside the cache.
- A pure function in the launcher's runtime package builds the child
  environment from the runtime directory and the parent environment. Both
  platform entry points call it, and it holds both precedence rules.
- One php.ini serves every build. The Windows build appends its extension
  directory and extension lines to a copy of that file.
- The development entry point exports the same variable and copies the bundle
  with the other runtime files, so dev and release resolve trust alike.
- Loading the ini activates settings that have never applied to a release
  start: memory_limit 512M, max_execution_time 300, max_input_vars 5000,
  post_max_size 64M, upload_max_filesize 64M, display_errors off, log_errors
  on, expose_php off, UTC.
- A php.ini in the working directory stops reaching a start. That matches the
  existing rule for a launch.php in the same place.
- The bundle joins the daily watch job described in the dependency updates RFC,
  which fails its run when a newer bundle exists upstream.

## Testing Decisions

A good test here drives the packed executable from outside and asserts what a
site owner could see: a loaded setting, a resolved path, an HTTP status. No
assertion names a private function or reaches into the runtime cache. The one
exception is the environment function, which is pure and worth testing
directly.

- The environment function gets Go unit tests for precedence. `PHPRC` always
  points at the runtime directory. `DRUPACK_CA_FILE` survives when the caller
  set it and appears when they did not. Prior art: the cache and extract tests
  in the same package, which need no built executable.
- A new conformance case module covers the runtime's PHP configuration on all
  three platforms with no network. It asserts the loaded ini path, the active
  memory limit, a certificate path that resolves to a file parsing as a large
  certificate set, and a working-directory php.ini that changes nothing. Prior
  art: the php-cli probe in the site cases, and the case proving a
  working-directory launch.php never runs.
- One case in that module fetches the Drupal release history over HTTPS and
  asserts a 200. It skips when the harness reports an offline run, so the
  offline container stays green. Prior art: the offline cases' own skip.
- Before the release tag, the full conformance chain runs on Linux, Windows and
  macOS. The settings now apply where they never did, so every suite is
  exposed to the new limits.

## Out of Scope

- Whether a Packaged site should report updates for modules that only a new
  release can replace. The update module keeps its current behavior.
- Exporting the Windows certificate store, or merging machine roots into the
  shipped bundle. The override covers a corporate machine.
- The copy of php.ini that travels inside the application archive was scoped out
  here as a follow-up, on the reading that it sits unused once `PHPRC` wins. It
  came back into scope during phase 2: main had meanwhile started appending the
  application directory to `PHP_INI_SCAN_DIR`, which made that copy a second
  live source of PHP settings and broke this work's own "one ini" decision. The
  build now excludes both it and the bundle from the archive.
- The Windows extension set, smaller than the Linux one, already recorded in
  the multi-platform plan.
- Proxy configuration, certificate pinning, and the update check against the
  GitHub releases feed.

## Further Notes

Evidence behind this document, gathered 2026-09-21:

- A Windows start reported `cURL error 60` for `updates.drupal.org` through
  Guzzle, from the site owner's own log.
- A probe through the warm Linux runtime returned HTTP 200 with an empty
  `curl.cainfo` and OpenSSL's default directory at `/etc/ssl/certs`. Bogus
  `SSL_CERT_FILE` and `SSL_CERT_DIR` values reproduced error 60 there.
- `PHPRC` pointed at a test directory loaded its php.ini on the Linux static
  runtime. The memory limit changed, `curl.cainfo` reached curl,
  `openssl.cafile` reached stream wrappers, and `${PHPRC}` interpolated.
- FrankenPHP documents both halves: the static binary reads php.ini from the
  directory it runs in, then `/etc/frankenphp/php.ini`, and it bundles no TLS
  certificates.

An existing site clears its error on the next update check. A site owner in a
hurry can run the manual check from the available updates report.
