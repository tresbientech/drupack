# Builders run static-php-cli v3 at a pinned commit

Accepted on 2026-10-22. Amends [ADR 0016](0016-extension-allowlist.md) and
[ADR 0026](0026-one-runtime-per-linux-file.md).

## Context

FrankenPHP 1.13.2 is a security release. It moved its static builder to
static-php-cli v3:

- one RHEL 8 toolchain image serves both libcs
- PHP compiles when the container runs, not while the image builds
- the build root moves from `dist/static-php-cli/buildroot` to `dist/buildroot`
- musl builds link with zig, glibc builds with the image's GCC

The Linux builder built FrankenPHP's old Dockerfiles, so 1.13.2 broke it. The
macOS build and `check-extensions.py` ran their own pinned static-php-cli 2.8.5.

static-php-cli v3 is an alpha with no tagged release. Its only binary is a
nightly with no published checksum. FrankenPHP downloads that nightly on every
build. RHEL 8 ships glibc 2.28, and the glibc runtime linked against 2.17
before.

## Decision

- `runtime/builder-inputs.sh` pins a static-php-cli v3 commit as `spc_commit`.
  `runtime/fetch-spc.sh` checks it out and verifies the hash. Every builder
  runs spc from that checkout.
- `build/upstream.py` moves `spc_commit` to the v3 branch head when it moves
  FrankenPHP. `builder-tag.sh` digests it.
- `runtime/build-builder.sh` builds FrankenPHP's toolchain image, runs
  `build-static.sh` on the checkout in a container, and commits that container
  as the builder image.
- `build/install.sh` installs the glibc build only on glibc 2.28 or later.
  Self-update applies the same floor. Below it, both take the musl build.

## Considered options

- Take the nightly, as FrankenPHP does. HTTPS would be the only check, and a
  rebuild could compile with a different spc.
- Stay on static-php-cli 2.8.5, the last tagged release. It is verified by
  checksum. FrankenPHP 1.13.2's builder no longer runs it.
- Keep Drupack's own builder Dockerfiles. The glibc floor stays at 2.17.
  Drupack would maintain two toolchain images FrankenPHP dropped.

## Consequences

- A host below glibc 2.28, such as CentOS 7 or Ubuntu 18.04, runs the musl
  build. The musl build carries no Node, so WordPress conversion needs a newer
  host.
- Each builder needs PHP 8.4 and Composer to run spc. The toolchain image and
  the macOS runners carry them. The job image runs spc on its musl runtime.
- A static-php-cli fix reaches Drupack with the next FrankenPHP bump, or by
  hand.
