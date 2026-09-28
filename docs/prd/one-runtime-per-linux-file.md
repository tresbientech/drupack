# PRD: one runtime per Linux file

Source: design settled on 2026-09-29. Supersedes ADR 0015.

## Problem Statement

A Linux executable carries a glibc runtime and a musl runtime and picks one when
it starts. Every Linux reader downloads about 179 MB where one runtime needs
about 125 MB. ADR 0015 accepted that cost so a reader never chose a build before
having a site.

The install scripts now pick the build for the host. The second runtime in
every file buys nothing, and the code that selects between runtimes stays for
every maintainer to read and test.

## Solution

Each Linux target publishes two files. `NAME-VERSION-linux-ARCH` carries the
glibc runtime, and `NAME-VERSION-linux-ARCH-musl` carries the musl runtime. The
install script checks the host for glibc and downloads the matching file.

An executable carries one runtime and runs it. The code that packed, recorded
and selected between two runtimes leaves the repository.

## User Stories

1. As a reader on a glibc host, I want the install script to download the glibc build, so that I get the faster runtime without choosing.
2. As a reader on a musl host such as Alpine, I want the install script to download the musl build, so that Drupack runs where glibc is absent.
3. As a reader building a container on a glibc host for a musl image, I want `DRUPACK_LIBC=musl` to make the install script fetch the musl build, so that the file matches its destination.
4. As a reader, I want an unknown `DRUPACK_LIBC` value refused by the install script with the two accepted values, so that a typo never installs the wrong build.
5. As a reader downloading by hand, I want the file without a suffix to be the glibc build, so that the obvious choice is the fast one.
6. As a reader, I want a Linux download of about 125 MB, so that the second runtime costs me nothing.
7. As a reader, I want `--version` to keep naming the libc, so that a bug report says which build ran.
8. As a reader, I want the checksum check of the install script to cover both files, so that either download is verified.
9. As a reader of an older release, I want its files and scripts unchanged, so that a pinned install keeps working.
10. As a Composer user, I want `drupack-install` to pick the build for my host through the same script, so that a project pin gets the right file.
11. As a reader of the `drupack` Engine executable, I want the same two files and the same pick, so that the engine and the demo install alike.
12. As a site owner, I want `libc: both` to publish both files, so that my site's contract needs no edit.
13. As a site owner, I want `libc: glibc` or `libc: musl` to publish only that file, so that I can halve my Linux build time.
14. As a site owner, I want `release.json` and `checksums.txt` to list each file, so that my own tooling finds the musl build.
15. As a maintainer, I want the runtime selection code, its variable and its tests gone, so that no reader of the launcher meets a mechanism that no longer runs.
16. As a maintainer, I want the packer to take one runtime, so that its interface and its tests shrink.
17. As a maintainer, I want the full conformance suite to run on the glibc build, so that coverage stays where readers are.
18. As a maintainer, I want the musl build started on a musl host in CI, so that the second file is proven to run.
19. As a maintainer, I want the docs to stop describing a runtime choice inside one file, so that no page contradicts the release.

## Implementation Decisions

Release files:

- A Linux musl build's target carries a `-musl` suffix: `linux-amd64-musl`,
  `linux-arm64-musl`. The glibc build keeps today's target name.
- The release file names, `checksums.txt`, `release.json` and the scripts'
  build list gain the musl targets.

Install script:

- On Linux, the script tests for the glibc program interpreter of the host's
  architecture. Present, it installs the glibc build. Absent, it installs the
  musl build.
- `DRUPACK_LIBC` set to `glibc` or `musl` overrides the test. Another value
  stops the script with both accepted values.
- A release without the chosen build stops with the builds it has, as today.

Contract and build:

- `libc` keeps `both`, `glibc` and `musl`, with `both` as the default. The value
  now names which Linux files a build publishes.
- The build plans one packed file per platform and libc, each with one runtime.
- The Engine executable packs the same way.

Packer and launcher:

- The packer takes one runtime. It records no program interpreter and writes
  one manifest.
- The launcher unpacks and runs its one runtime. The runtime selection, its
  environment variable and the interpreter record leave the code.
- The glibc runtime keeps its immediate binding and the launcher keeps dropping
  the loader variables, since a glibc build still runs host libraries.

Removal:

- Every symbol, test, CI step and doc passage that served two runtimes in one
  file is deleted. ADR 0015 is marked superseded by the ADR this PRD records.
- The builder image per libc and the job image holding both runtimes stay:
  they build two files.

## Testing Decisions

- A good test drives a public interface and checks what a reader or site owner
  sees. That means the file installed, the files a build plans, and the runtime
  a start runs.
- The build planner's Go tests move to one file per platform and libc, with the
  musl names.
- The install script cases gain the glibc pick, the musl pick and the override,
  with the interpreter path pointed at a fixture.
- The runtime selection cases are deleted with the mechanism.
- CI runs the full conformance suite on each glibc build, and starts each musl
  build on a musl host.
- Removal is decided by a repository search for the selection symbols, the
  variable and the multi-runtime pack flags, which finds only ADR 0015.
- Prior art: the install script cases, the build planner tests, and the
  release workflow's musl host step.

## Out of Scope

- Dropping musl.
- Changing macOS or Windows files.
- Bundled Node, which its own PRD covers.
- Changing the `libc` field's values.

## Further Notes

- This ships first, in its own release. The Bundled Node PRD builds on its one
  runtime per file.
- The load figures in ADR 0015 still hold: the glibc build serves rendered
  pages several times faster.
