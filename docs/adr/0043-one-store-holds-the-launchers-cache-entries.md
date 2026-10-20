# One store holds the launcher's cache entries

Accepted on 2026-10-20. Amends [ADR 0002](0002-shared-application-extraction.md)
and [ADR 0007](0007-launcher-cache-retention.md).

## Context

The runtime, the application and the Node release each had their own code to
lock, stage, hold, sweep and clean. `clean` removed applications and Node
releases alone. It left the runtimes, and the `folders/` entries `serve.php`
writes for each folder the engine serves.

## Decision

- One store type in `launcher/internal/runtime` owns each cache kind. A kind
  sets its directory, its readiness test and its retention rule.
- A start holds an entry without the lock when it finds the entry ready. Every
  removal therefore moves the entry to a staging name and tests it again before
  deleting it.
- The store and `LaySiteApp` share one swap: fill a staging directory, write
  the marker last, then move the result into place.
- Each kind keeps its retention rule: the runtime while active, the
  application for 30 days after its last use, Node until `clean`.
- `clean` walks the runtimes, the applications, the Node releases and the
  folder entries. It keeps an entry a running site uses: the usage marker for
  the first three, the Serving lease for a folder entry.

## Consequences

- After `clean`, the next start unpacks its runtime again.
- `clean` removes a stopped folder's Mercure hub state. `drush` in that folder
  exports no hub until the next start.
- A start that opens a folder's lease just as `clean` moves the entry away can
  serve from a removed entry.
