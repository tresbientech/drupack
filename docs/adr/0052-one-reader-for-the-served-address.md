# One reader for the served address

Accepted on 2026-11-09. Amends [ADR 0050](0050-the-launcher-owns-a-start.md).

## Context

Six readers took the address a site was served on from the Listener record.
Each merged the typed values, the record and the defaults on its own:

- site drush and site export, through `openSite`
- engine drush
- engine export
- the handover of a second start
- a start's files-directory read

The readers parsed the listen address their own way. The site start and the engine start each held a
fallback host, both `localhost`.

`planStart` always plans the static listener. With no static listener typed or
recorded it builds the address from the bind address and the next port, and
parses it as an IP. That refuses a hostname listen. The engine's Drush, the
handover and the hub URL need the main address alone, and must not gain that
refusal.

## Decision

- `Served` is the one reader of the Listener record. A typed value wins over
  the recorded one, and the recorded one over the default.
- `Address` is the merged record plus the parsed bind and port. `Recorded` is
  false when no start has recorded a listener.
- `Site.Listen` parses the listen text it is given and applies no default.
  `Site.Engine` picks the engine's parser, which takes a hostname. `Served`
  and the check of each start call it.
- A reader that needs the static listener calls `Address.Plan`. The others never
  plan it, so a hostname listen refuses for those readers alone.
- One constant, `unresolvedHost`, is the host of a site whose name does not resolve.

## Consequences

- A change to the precedence or the record's fields touches `Served` alone.
- Every refusal keeps its text. `TestServed` and `TestSiteListen` hold the rows.
- `Served` checks `--host` after it parses the listen address. A site's start checks it too.
