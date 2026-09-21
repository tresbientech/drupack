# The launcher cache keeps an entry for 30 days

Accepted on 2026-09-18.

## Context

Activating a release deleted every other entry. A site owner running two
releases in two folders paid a cold unpack, about 418 MB, on every start.

## Decision

- A start dates the entry it runs by writing a `used` file inside it.
- Cleanup removes an entry whose `used` file is older than 30 days.
- `drupack clean` removes every unpacked application, and `--dry-run` lists
  them instead.
- An entry a running start holds is kept by both paths, since removing it would
  pull PHP files out from under a serving site.

## Consequences

A machine that alternates between releases keeps both warm. A machine that
upgrades once reclaims the old entry a month later, or sooner with `clean`.
