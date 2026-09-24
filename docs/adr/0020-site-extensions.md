# A site adds PHP extensions to the engine list

Accepted on 2026-09-24.

## Context

`runtime/php-extensions.txt` names every extension a runtime carries.
`check-extensions.py` fails a build when the site's lock declares one the file
omits. The first client site requires `drupal/simple_sitemap`, which needs `xmlwriter`,
and the file lists `xmlwriter` as not shipped.

RFC 0001 reserved `extensions` in `drupack.yml` for this. It named the caller's
builder registry as the cache and left the compile path open.

`drupack-build` runs in the job image, where no Docker daemon answers. A
runtime compiles inside a builder image, which `build-builder.sh` builds with
Docker in 18 to 25 minutes per libc.

## Decision

- The site's `drupack.yml` lists its additions in `extensions`. The engine file
  stays the base, so a site cannot drop a database driver or `pdo_sqlite`.
- `build/site-runtimes.sh SITE OUTPUT` runs from an engine checkout on a Docker
  host. It merges the two lists and builds each target's builder image through
  `build-builder.sh`. It writes `OUTPUT/linux-<arch>-<libc>/` from the
  Dockerfile's runtime stages.
- `builder-tag.sh` digests the merged list, so a rerun with the same inputs
  reuses the images.
- `drupack-build --runtimes OUTPUT` builds the site against those runtimes.
  `check-extensions.py` checks the lock against the merged list.
- The targets come from `drupack.yml`, as `docs/adr/0019-package-an-existing-site.md`
  decides, so the script compiles only what the site ships.

## Considered options

- Adding `xmlwriter` to the engine file. Every runtime would carry it, and each
  new need of one site would grow all of them.
- Compiling inside the job image. The image would carry static-php-cli and its
  buildroot, several GB for every caller, and an uncached build would compile
  inside the build job.
- A Go command driving Docker. It shells out to the same Dockerfile and adds an
  entry point the job image cannot run.

## Consequences

- A site with additions pays one compile per libc and target architecture. An
  arm64 compile on an amd64 host runs under QEMU and takes hours.
- The GitHub path, a `build.yml` job that pulls or compiles runtimes into
  `ghcr.io/<caller>/drupack-builder`, comes in a later slice.
- drupal.org GitLab runners have no Docker daemon. A site with additions cannot
  build there.

## Amendment, 2026-09-24

`build/site-runtimes.sh` compiles for the host's architecture only and refuses
another target. `build-builder.sh` builds for `uname -m`, and a QEMU compile
takes hours. A site that ships `linux-arm64` compiles its runtimes on an arm64
host.
