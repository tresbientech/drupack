# One extension list drives every builder, and a release carries its trust store

## Context

Three builders compile PHP: Linux, macOS and Windows. Each must ship the extensions Drupal needs and no others. A static PHP build has no system CA bundle, so a release must carry its own.

## Decision

### The allowlist

- `runtime/php-extensions.txt` lists every shipped extension, one per line, with a trailing comment naming what needs it.
- The three builders, `check-extensions.py` and the conformance suite read that file. None repeats a literal list.
- `check-extensions.py` runs `spc dump-extensions` on a site's lock and fails the build when the lock declares a name the file omits.
- The conformance suite asserts that a binary loads exactly the names the file lists, plus the extensions PHP always compiles.
- `tests/conformance/harness.py` holds the facts that differ by platform: `_UNLOADABLE` and `_REPORTED_AS`. `password-argon2` never loads. Windows has no `pcntl` or `posix`. `opcache` reports as `zend opcache`.
- `build.ps1` writes a php.ini `extension=` line for each listed name that has a DLL in the PHP zip or a pinned PECL zip. It runs no load check.
- On Windows the release job's conformance run fails the build for a name the runtime does not load. The publish job needs that job.
- A Drupal update that declares a new extension stops the build until someone adds a line.

### The Linux and macOS builders

- `runtime/builder-inputs.sh` pins the FrankenPHP commit, the PHP version and a static-php-cli v3 commit as `spc_commit`. `build/upstream.py` moves all three.
- `runtime/fetch-spc.sh` checks out that commit, verifies its hash and applies `runtime/spc-sqlite.patch`. The patch names a current SQLite release, because the pinned recipe passes flags SQLite's configure rejects.
- `runtime/build-builder.sh` builds FrankenPHP's toolchain image, runs `build-static.sh` on the checkout in a container, and commits that container as the builder image. PHP compiles when the container runs.
- The musl build targets `ARCH-linux-musl`, the architecture's baseline CPU, so the runtime starts on an older CPU.
- `builder-tag.sh` digests the pins, both extension lists, the recipe scripts and the patch. A recipe change builds a new image.
- The glibc build links against glibc 2.28, the glibc of the RHEL 8 toolchain image. `build/install.sh` and self-update take the glibc file on glibc 2.28 or later, and the musl file below it.

### Trust store and php.ini

- `application/cacert.pem` ships beside the runtime. It is curl's published bundle, and the Dockerfile records its checksum. No build fetches it.
- `application/php.ini` names it through `curl.cainfo` and `openssl.cafile`, both read from `${DRUPACK_CA_FILE}`.
- The launcher exports `PHPRC` and `DRUPACK_CA_FILE` from one function that every child's environment uses. `PHPRC` always takes the runtime's directory. `DRUPACK_CA_FILE` yields to a caller who set it, so a reader behind a TLS-inspection proxy points at their own bundle.

## Reason

One file removes the drift between three builders. Letting `build-static.sh` derive the set from the embedded application ships a build without `pdo_mysql`, `pdo_pgsql`, `opcache` and `argon2`. The list is hand-written because the lock never declares those four.

The platform facts sit in one Python file. The Linux builder containers have bash and no Python, and the Windows build host has no guaranteed Python. The conformance suite checks the finished executable on every Windows leg, so it holds the facts.

The pinned spc commit makes a rebuild compile with the same tool. The static-php-cli v3 branch has no tagged release and publishes an unchecked nightly, so a commit pin with a hash check is the available control.

Bundling the CA file lets a release reach updates.drupal.org on a host with no CA package, and on Windows, where curl reads no system path. The bundle ages with the release.
