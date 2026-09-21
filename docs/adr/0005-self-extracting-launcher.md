# A self-extracting launcher unpacks the runtime into a user cache

Accepted on 2026-09-18. Windows joined on 2026-09-21.

## Context

The single executable was compressed with UPX. Antivirus products flag UPX
packing, macOS refuses the result, and the compressed image has to be written
somewhere before PHP can run from it anyway.

## Decision

- The executable carries a compressed runtime payload and a manifest naming
  every file with its size and checksum.
- A start unpacks that payload once per release into a per-user cache, then
  executes the entry from there.
- The cache entry is keyed by the version joined to the payload digest, so two
  builds sharing a version string keep separate entries.
- A warm entry is reused after a size check. A fallback to another release's
  runtime is refused, since the application beside it belongs to this release.

## Consequences

The first start of a release pays the unpack, about 120 MB, and prints its
progress. Every later start begins at the cache. `DRUPACK_CACHE_DIR` moves the
cache, and the launcher refuses a root another account can write.
