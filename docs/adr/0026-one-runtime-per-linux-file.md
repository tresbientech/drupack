# A Linux file carries one runtime

Accepted on 2026-09-29. Supersedes [ADR 0015](0015-two-runtimes-in-one-executable.md).

## Context

ADR 0015 put a glibc runtime and a musl runtime in every Linux executable, so
a reader never chose a build before having a site. Each Linux download grew
from about 125 MB to about 179 MB.

ADR 0023 then gave every release an install script that finds the host's
build. The script can test for glibc as the launcher did. The second runtime
in each file now buys a reader nothing, and the selection code stays for every
maintainer to read and test.

## Decision

- Each Linux target publishes two files. `NAME-VERSION-linux-ARCH` carries the
  glibc runtime, and `NAME-VERSION-linux-ARCH-musl` carries the musl runtime.
- An executable carries one runtime and runs it. The packer takes one runtime
  directory, and the manifest records no program interpreter.
- On Linux the install script tests for the glibc program interpreter of the
  host's architecture. It installs the glibc file when the interpreter is
  present, and the musl file otherwise.
- `DRUPACK_LIBC` set to `glibc` or `musl` overrides that test. Another value
  stops the script before any download.
- `libc` in `drupack.yml` keeps `both`, `glibc` and `musl`, with `both` as the
  default. It names the Linux files a build publishes.
- The glibc runtime keeps its `-z now` link, and the launcher keeps dropping
  the loader variables, since a glibc file still loads host libraries.

## Considered options

- Keeping ADR 0015. Every Linux reader downloads about 54 MB for a runtime the
  host never runs.
- Dropping musl. An Alpine or slim container host would lose the only file
  that runs there.

## Consequences

- A Linux download measures about 125 MB.
- A reader downloading by hand picks between two Linux files. The file
  without a suffix is the glibc one.
- A glibc file copied onto a musl host fails to start. The musl file runs on
  either host.
- The release workflow runs the conformance suite on each glibc file, and
  starts each musl file on Alpine.
- An executable ignores `DRUPACK_LIBC`. A start never re-unpacks for a
  different libc, since its cache holds the one runtime it carries.
- Files and scripts of earlier releases stay as they were published.
