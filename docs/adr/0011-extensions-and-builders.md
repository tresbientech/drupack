# One extension list drives every builder, and a release carries its trust store

## Context

Three builders compile PHP: Linux, macOS and Windows. Each must ship the extensions Drupal needs and no others. A static PHP build has no system CA bundle, so a release must carry its own.

## Decision

### The allowlist

- `runtime/extensions/php-extensions.txt` lists every shipped extension, one per line, with a trailing comment naming what needs it.
- The three builders, `check-extensions.py` and the conformance suite read that file. None repeats a literal list.
- `check-extensions.py` runs `spc dump-extensions` on a site's lock and fails the build when the lock declares a name the file omits.
- The conformance suite asserts that a binary loads exactly the names the file lists, plus the extensions PHP always compiles.
- `tests/conformance/harness.py` holds the facts that differ by platform.
- `build.ps1` writes a php.ini `extension=` line for each listed name that has a DLL in the PHP zip or a pinned PECL zip. It runs no load check.
- On Windows the release job's conformance run fails the build for a name the runtime does not load. The publish job needs that job.
- A Drupal update that declares a new extension stops the build until someone adds a line.

### The Linux and macOS builders

- `runtime/builder/builder-inputs.sh` pins the FrankenPHP commit, the PHP version and a static-php-cli v3 commit. `build/release/upstream.py` moves all three.
- `runtime/builder/fetch-spc.sh` checks out that commit and verifies its hash.
- A Linux builder image is FrankenPHP's toolchain image on the pinned RHEL 8 base, plus one layer: the paths `embed.sh` reads from the PHP compile. `build-builder.sh` lists them: PHP's libraries, headers and tools, spc, the Go toolchain and, for musl, zig. Sources, downloads and Go caches stay out: over 80 percent of the compile's bytes.
- The glibc build links against glibc 2.28, the glibc of the RHEL 8 toolchain image. `build/install/install.sh` and self-update take the glibc file on glibc 2.28 or later, and the musl file below it.

### Trust store and php.ini

- `runtime/cacert.pem` ships beside the runtime. It is curl's published bundle, and the upstream run moves it to each new extract.
- The launcher sets `DRUPACK_CA_FILE` for every child. It yields to a caller who set it, so a reader behind a TLS-inspection proxy points at their own bundle.

## Reason

One file removes the drift between three builders. Letting `build-static.sh` derive the set from the embedded application ships a build without `pdo_mysql`, `pdo_pgsql`, `opcache` and `argon2`. The list is hand-written because the lock never declares those four.

The platform facts sit in one Python file. The Linux builder containers have bash and no Python, and the Windows build host has no guaranteed Python. The conformance suite checks the finished executable on every Windows leg, so it holds the facts.

The pinned spc commit makes a rebuild compile with the same tool. The static-php-cli v3 branch has no tagged release and publishes an unchecked nightly, so a commit pin with a hash check is the available control.

Bundling the CA file lets a release reach updates.drupal.org on a host with no CA package, and on Windows, where curl reads no system path. A published release keeps the bundle it shipped with.
