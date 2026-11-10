# The release graph builds each thing once

Accepted on 2026-09-22. Amended on 2026-09-23: the Dockerfile lost its `packed` and
`artifact` stages, and a local build runs `drupack-build` in the job image.
Amended on 2026-11-09: macOS and Windows test in jobs apart from their build.
Amended on 2026-11-09: [ADR 0054](0054-a-script-plans-the-release-run.md) moves the gate's
plan into `build/release-plan.py`.

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
  tests. A local build runs `drupack-build` in the job image, which
  `CONTRIBUTING.md` documents as one `docker run`.
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
- CI and a local build pack through the same `drupack-build`, so a pack break
  shows up in a run.

## Amendment, 2026-11-09: macOS and Windows build once and test in shares

The 1.0.0-beta15 candidate run took 87 minutes of wall clock. Runtimes and the
payload took the first 12. One serial suite run then set the length of each
long job:

- Intel macOS: 75 minutes, with 45 in the suite
- QA: 69 minutes
- Windows arm64: 48 and 58 minutes for its two halves, with 43 in each
- Windows amd64: 53 minutes, with 43 in the suite

The suite's time is spread over its cases: about 6 seconds each on Linux and
16 on Intel macOS.

- The `macos` and `windows` jobs build, pack the variant fixture, and upload
  the files the suite runs as a `suite-*` artifact.
- `macos-test` and `windows-test` download that artifact. Each job runs one
  `DRUPACK_SHARD` share of the test classes.
- Intel macOS runs three shares and macOS arm64 two, which fills GitHub's five
  macOS jobs. `macos-test` waits for both builds.
- `build/macos/build.sh` compiles PHP only when the restored buildroot lacks
  `libphp.a`. The Intel build step took 19 minutes on a cache hit before, and 4
  after.
- A QA phase keeps its builds' test results, whose deletion took 6 to 10
  minutes. The whole chain still deletes them.
- A failed test job uploads its logs alone. The whole results folder took 17
  minutes to upload.
- Windows amd64 and Windows arm64 run five shares each. Windows arm64 runs its
  variant cases in a sixth job.
- The Windows arm64 jobs test the files the amd64 host built. Each built its
  own before.
- `build/qa.sh` takes a phase: `demo`, `variant` or `host`. The `qa` job runs
  one phase per job. The `demo` and `host` phases each build Drupacked Demo,
  and each build runs one half of its suite.
- Linux runs two jobs per architecture since 2026-11-10. Each packs its files,
  about 3 minutes, and runs one share. One job took 23 to 27 minutes.

This takes "build then verify" from the options above for macOS and Windows.
Each test job pays an artifact download and a Go setup.

Consequences:

- Every case runs once on every host, as before.
- Run 42696202800 on 2026-11-09 had three Windows amd64 shares, four Windows
  arm64 shares and macOS arm64 tested after both builds. Its last Intel macOS
  share ended at minute 51 and its macOS arm64 suite at minute 60.
- One Windows arm64 share of that run spent 35 minutes in the suite, failed one
  case, and ended at minute 73 after its results upload.
- Run 42734479576 on 2026-11-10 had the graph above on warm caches and ended
  green in 44 minutes. Its last jobs were Intel macOS share 3/3 and `QA host`.
- Run 42742364797 on main took 88 minutes the same day. Its Intel macOS build
  found no cache and took 45 minutes. Two Linux jobs failed a `TlsSources`
  start and passed on the rerun.
- A failed `qa` job uploads the logs under `/tmp/drupack-qa`.
- A share held every Nth class in name order until 2026-11-10, and Windows
  arm64 shares ran 15 to 24 minutes. `tests/conformance/class-seconds.json` now
  holds each class's seconds per platform, measured on run 42742364797. The
  classes go longest first to the share with the fewest seconds. Replayed on
  that run's times, the Windows arm64 shares come to 18 minutes each.
- `build/class-seconds.py` writes that table from a run's logs. The table of
  run 42805185885 follows the suite cut of ADR 0057, which left shares uneven:
  one Windows amd64 share went from 5 to 11 minutes on the old table.
- `+extra` on a share starts the last share with the seconds of its job's
  other cases: the variant cases on macOS and Windows amd64, the host cases in
  `build/qa.sh host`.
- The table needs a new measure when the classes' times move. A class missing
  from it counts as the mean.
- The `qa` jobs build the job image from the runtime jobs' files, as `payload`
  does. The build from the builder images took 4 minutes after a 5 minute pull.
- Run 42782226030 on 2026-11-10 had both changes and ended green in 42 minutes.
  `QA host` took 25 minutes against 37 and ended at minute 32. Two macOS test
  jobs waited 4 minutes for a runner, and the last of them ended the run.
  Windows arm64 shares took 16 to 22.5 minutes.
- The graph peaks at 19 jobs: 2 Linux, 1 demo, 3 QA, 2 macOS builds and 11
  Windows tests. GitHub's free plan runs 20 jobs at once, 5 of them on macOS.
- A suite rerun on macOS or Windows downloads the build.
- An edit to `build/macos/build.sh` or to a PHP pin changes the macOS cache
  key. The next run then builds for 43 minutes on Intel and 23 on arm64.
- An executable uploads before its tests pass. A tag publishes it from a green
  candidate run, or after the test jobs `publish` needs.
- No CI job builds on a Windows arm64 host.
- The `suite-*` artifacts keep the default retention. The gate rebuilds a
  candidate when any of its artifacts expired.
