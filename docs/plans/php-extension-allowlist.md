# Plan: one file names every extension a build ships

Goal: every builder compiles the PHP extensions one file names, and each name
records what it serves. Payoff: a smaller runtime on evidence, three builders
that stop diverging, and a check that works on any site's lock.

## What was decided

`docs/adr/0016-extension-allowlist.md` holds the decision and the rejected
alternatives.

`packaging/php-extensions.txt` is the allowlist. One name per line, sorted, with
a trailing comment naming what it serves. The Linux, macOS and Windows builders
read it, and so does the conformance suite.

`spc dump-extensions` reads `drupal/composer.lock` and reports the extensions
packages declare. A CI check fails when the lock declares a name the file omits.
The lock declares 20 names today, and never declares `pdo_mysql`, `pdo_pgsql`,
`opcache` or `argon2`.

## Preconditions

- The dual-libc work lands first. This plan edits the same Dockerfile stages
  and the same CI jobs.
- `.github/workflows/release.yml:200` builds `--target build`, a stage that no
  longer exists. The application job fails until that reads `app`.

---

## Phase 1: The allowlist and the declared check

### What to build

`packaging/php-extensions.txt` starts from the Windows set, which ships today
and passes the suite. It adds the spc names for the same capabilities
(`opcache`, `password-argon2`, `mysqlnd`) and the build-time consumers. Composer
and the translation fetch run under the same PHP, so `phar`, `zip`, `curl` and
`openssl` are keeps with a comment saying so.

The always-loaded names belong in the file, among them `Core`, `standard`,
`SPL`, `Reflection` and `date`. Later phases compare the whole loaded set
against it.

`packaging/check-extensions.sh` downloads spc at the pinned version, runs
`spc dump-extensions drupal/ --no-dev`, and compares the result to the file. A
declared name the file omits fails the check, and the message names the packages
that declare it. A CI job runs the script.

### Acceptance criteria

- [ ] Every line in the file carries a comment naming what the extension serves.
- [ ] The check passes against the current lock.
- [ ] A line deleted from the file fails the check, and the message names the
      declaring packages.
- [ ] The check runs without the builder image and without a docker build.
- [ ] `spc dump-extensions` exists at the pinned spc version. If it does not,
      the version moves and `packaging/macos/build.sh` moves with it.

---

## Phase 2: Windows builds from the file

### What to build

`packaging/windows/build.ps1` holds two lists, `$dllExtensions` and
`$builtinExtensions`. Both go. The script reads the file and tests for
`ext\php_<name>.dll` in the PHP zip to tell a DLL from a built-in extension.

A name that is neither a DLL in the zip nor loaded by the built PHP stops the
build and names itself.

The conformance harness gains a helper that reads the file.
`tests/conformance/ascii_cache_root_cases.py:37` drops `DLL_EXTENSIONS` and
calls the helper. A case asserts that the executable loads exactly the names the
file carries.

### Acceptance criteria

- [ ] `build.ps1` names no extension.
- [ ] The Windows executable loads exactly the file's names.
- [ ] A name Windows cannot provide stops the build, and the message names it.
- [ ] `ascii_cache_root_cases` passes with no list of its own.

---

## Phase 3: macOS builds from the file

### What to build

`packaging/macos/build.sh:16` holds the 68-name `extensions` literal. It reads
the file instead. `extension_libs` keeps its own list, since `watcher`,
`nghttp2`, `nghttp3` and `ngtcp2` serve FrankenPHP and Caddy rather than any
extension.

The equality case from phase 2 runs on macOS.

### Acceptance criteria

- [ ] `build.sh` names no extension.
- [ ] The macOS executable loads exactly the file's names.
- [ ] The equality case passes on both macOS architectures.

---

## Phase 4: Linux builds its own builder images

### What to build

`PHP_EXTENSIONS` takes effect when a static-builder image is built, so the
pinned `dunglas/frankenphp` digests cannot carry a narrowed PHP. The workflow
builds both images from `php/frankenphp` at the commit
`packaging/macos/build.sh` already pins, with `PHP_VERSION=8.5.10` and
`PHP_EXTENSIONS` read from the file. It tags them locally and passes the tags as
`MUSL_BUILDER` and `GNU_BUILDER`.

Each libc gets its own CI job, since one runner holds neither two builder images
nor two PHP compiles. A packing job takes both `/out` directories and the
application payload.

`packaging/embed.sh:19` names five libraries for `pkg-config`. A narrowed PHP
drops some of them from the buildroot, so the line reads `spc spc-config
--libs` instead. `-lwatcher-c` and the database client libraries stay written
out.

### Acceptance criteria

- [ ] The Dockerfile takes both builder images as tags the workflow builds.
- [ ] Both Linux runtimes load exactly the file's names.
- [ ] The packed executable serves a site on a glibc host and inside Alpine.
- [ ] `docker build --target artifact` succeeds once the images exist.
- [ ] The Linux jobs report their build time and free disk.

---

## Phase 5: What the change bought

### What to build

Record the uncompressed size of each runtime and the size of the packed asset,
before and against the narrowed build. Replace the backlog entry
`docs/backlog.md:58` with the result.

`docs/plans/dual-libc-runtime.md` says both builder images stay pinned by
digest. That criterion now reads as a source commit and a PHP version.

### Acceptance criteria

- [ ] The measurement names both runtimes and the packed asset, on one
      architecture, before and after.
- [ ] `docs/backlog.md` carries the result in place of the open question.
- [ ] No document claims a saving the measurement did not produce.

---

## Out of scope

- Dropping an extension the file keeps but no package declares. Each one needs
  a capability check of its own.
- A per-site allowlist written with no review. The build-any-site goal needs it
  later, and nothing calls for it now.
- Symbol-level scanning for extension use no package declares. ADR 0016 records
  why.
