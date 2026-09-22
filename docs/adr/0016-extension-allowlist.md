# The extension allowlist drives every build

Accepted on 2026-09-22.

## Context

Three builders carry three extension sets, and nothing records what any
extension serves.

- The Linux `Dockerfile` links the FrankenPHP static-builder image's prebuilt
  buildroot, so the repo never names its extensions. That image carries
  FrankenPHP's 68-name default set.
- `packaging/macos/build.sh` repeats those 68 names as a literal.
- `packaging/windows/build.ps1` names 28 across two lists, split by how the PHP
  zip provides each one.
- `tests/conformance` repeats 13 of the Windows names and the three PDO drivers.

`drupal/composer.lock` declares 20 extensions across 169 packages. It never
declares `pdo_mysql`, `pdo_pgsql`, `opcache` or `argon2`.

## Decision

- `packaging/php-extensions.txt` lists every shipped extension, one per line,
  with a trailing comment naming what it serves.
- All three builders and the conformance suite read that file. Their literals go.
- Linux builds a static-builder image per libc from `php/frankenphp` at a pinned
  commit, with the list passed as `PHP_EXTENSIONS`.
- A CI step runs `spc dump-extensions drupal/ --no-dev`. It fails the build when
  the lock declares a name the file omits.
- The conformance suite asserts that a binary loads exactly what the file names.
- `build.ps1` tells a DLL from a built-in extension by testing for
  `ext\php_<name>.dll` in the PHP zip.
- A name a platform cannot provide fails that platform's build. The exception
  goes in that platform's script.

## Considered options

- Let `build-static.sh` derive the set. It runs `dump-extensions` on the
  embedded application when `PHP_EXTENSIONS` is unset. The result ships without
  `pdo_mysql`, `pdo_pgsql`, `opcache` and `argon2`, which breaks two database
  backends and password hashing.
- Scan symbols with `shipmonk/composer-dependency-analyser`. It finds extension
  use that no package declares. It costs a pinned tool, a dev install and a
  build stage, for a gap the hand-written file already covers.
- Publish narrowed builder images by digest. Release builds stay as fast as
  today. It adds a registry artifact to maintain, and one image cannot serve a
  per-site extension set.

## Consequences

- The builder pin moves from an image digest to a FrankenPHP source commit and a
  PHP version. An upgrade review reads a source diff instead of two digests.
- Linux gains one CI job per libc, each compiling PHP, and a job that packs both
  runtimes. A cold builder image takes 18 to 25 minutes, which
  `docs/adr/0018-release-graph.md` answers with a published image.
- Measured on `f6b280c` against `74d1992`, the last build before the allowlist:
  the musl runtime executable fell from 170,275,248 to 146,212,008 bytes, 14.1
  percent. The macOS executables fell 5.4 percent on arm64 and 5.9 percent on
  amd64. Windows moved 0.01 percent, since its DLL set already matched. A build
  loads 41 extensions where the FrankenPHP default set loaded 77.
- The always-loaded extensions belong in the file, among them `Core`,
  `standard`, `SPL`, `Reflection` and `date`. The assertion compares the whole
  loaded set.
- A Drupal update that declares a new extension stops the build until someone
  adds a line.
- The same file and check work against any site's lock, which the build-any-site
  goal needs.
