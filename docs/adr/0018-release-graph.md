# The release graph builds each thing once

Accepted on 2026-09-22.

## Context

A tag run costs about 191 runner-minutes and 45 minutes of wall clock, measured
on `b6ceb1d` and on the last full green run.

- Four builder images compile PHP from source on every run: amd64 musl 25m,
  amd64 gnu 21m, both arm64 legs 18m. Their content follows six inputs: the
  FrankenPHP commit, the PHP version, the two extension list files, the libc
  and the architecture. Nothing stores the result between runs.
- The application is built three times on a tag. `payload` builds it for Windows
  and macOS. Each Linux architecture builds it again, because the `packed` stage
  reads `--from=app`.
- The `linux` job pulls the 8.6 GB static-builder image to run a packer that
  needs no PHP.
- Five jobs repeat one `contains(fromJSON(...))` filter, and three carry an
  `exclude` block, two of them the same nested ternary. A skipped matrix leg
  still draws a node in the graph.
- The macOS cache key hashes the build script alone, so a changed allowlist
  restores a buildroot built for the old extension set.

## Decision

- A `gate` job merges `changes` and `extensions`. It emits the version and one
  JSON matrix per platform family. Each job reads it with `fromJSON` and carries
  no `exclude`.
- `runtime/builder-tag.sh` digests a builder image's six inputs into a tag under
  `ghcr.io/tresbientech/drupack-builder`. A runtime job pulls that tag. A miss
  builds the image, uses it in place and pushes it for later runs.
- `payload` runs for every build and feeds all five platforms. Linux downloads
  the artifact that Windows and macOS already use.
- Linux packs on the runner with the Go toolchain the job installs for the unit
  tests. The `packed` and `artifact` stages stay for a local build, which
  `CONTRIBUTING.md` documents as one `docker build`.
- The macOS cache key hashes the two extension list files as well.

## Considered options

- Compact Linux: link both runtimes in the job that packs and tests them. Two
  jobs instead of six, about 101 runner-minutes. One runner then holds an 8.6 GB
  image twice in sequence against 14 GB free, and a failed test discards both
  links.
- Build then verify: every platform uploads its executable and a second job
  tests it. A suite rerun costs a download rather than a 24-minute macOS build.
  It adds five jobs and an artifact hop per platform.
- `actions/cache` for the spc buildroot, as macOS does today. No registry and no
  new permission. Four Linux entries beside the two macOS ones exceed the 10 GB
  repository cache.
- Keep rebuilding every run. Each run proves the image builds from source, at a
  20-minute floor under any Linux change.

## Consequences

- Measured on `aa2bc7a` with an empty registry: five platforms in 41 minutes of
  wall clock and 158 runner-minutes, against 45 minutes and 191 before. A main
  push with the images published takes 13 minutes and 20 runner-minutes, where
  a runtime job pulls its builder image in 78 seconds instead of compiling PHP
  for 25 minutes.
- `0016` rejected published builder images because one image cannot serve a
  per-site extension set. A tag derived from the inputs answers that objection.
  A different allowlist is a different tag, and a miss builds the image.
- The workflow needs `packages: write`. A fork cannot push to the registry, so a
  miss there builds the image in the job and skips the push.
- The registry holds one image per input set. Old tags need a retention rule.
- A builder change costs one 25-minute run. Every later run pulls.
- The application that Linux ships is the one Windows and macOS tested.
- No CI job runs the `packed` stage any more, so a break there shows up in a
  local build rather than in a run.
