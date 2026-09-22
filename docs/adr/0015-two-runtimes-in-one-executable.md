# A Linux executable carries a runtime per C library

Accepted on 2026-09-22.

## Context

A Linux release shipped one runtime, linked against musl, because a static
executable runs on any host. A load study on 2026-09-22 compared two HEAD
builds differing only in libc, against one MariaDB, at 1 to 64 concurrent
users.

Anonymous throughput matched within 8 percent. Authenticated throughput did
not: 141 requests per second for musl against 488 for glibc at 16 users, 166
against 568 at 64, with p95 at 526ms against 150ms. An anonymous request comes
from the page cache and an authenticated one renders the page, so the split
follows allocation volume.

The measurement is one WSL2 host, one run per rung, on 2026-09-22. Reversing
the run order reproduced the musl figure. No user-facing document states the
ratio.

Shipping a second download would put a choice in front of a reader before they
have a site. The glibc runtime needs at most `GLIBC_2.17` and links only core
glibc libraries, so a single `stat` of its recorded ELF interpreter tells a
host that runs it from one that does not.

## Decision

- One Linux executable carries both runtimes. Asset names do not change. The
  download grows from about 125 MB to about 179 MB.
- `DRUPACK_LIBC` decides when it names a carried runtime, and an unknown value
  stops the start. Otherwise the first runtime whose recorded interpreter
  exists wins, which leaves musl as the fallback without a special case.
- An executable carrying one runtime runs it and reads no variable, so macOS
  and Windows are unaffected.
- `--version` names the runtime that ran.
- The application payload is built once, under one builder image, and both
  runtimes carry it.
- The glibc runtime links with `-z now`, and the launcher drops `LD_PRELOAD`,
  `LD_LIBRARY_PATH`, `LD_AUDIT` and `GLIBC_TUNABLES` from the environment it
  passes on.

## Consequences

A reader on a glibc host serves rendered pages several times faster without
choosing anything. A container host keeps a runtime that runs with no glibc
present.

The host's glibc enters the trust base. The launcher verifies every file it
unpacks by checksum and can say nothing about `/lib/x86_64-linux-gnu/libc.so.6`.
The coupling runs both ways: a glibc fix reaches Drupack through the
distribution without a Drupack release.

glibc name resolution loads `libnss_*.so` from the host, chosen by
`/etc/nsswitch.conf`. musl resolves internally.

A start that flips `DRUPACK_LIBC` re-unpacks, since the cache keeps one runtime
entry. That costs the cold-start time, measured at 1.1 to 1.5 seconds.

A second builder image enters the supply chain and stays pinned by digest.
