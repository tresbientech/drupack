# PHP reads a typed Site contract

Accepted on 2026-10-20.

## Context

`launch.php` read `site.json` into an untyped array and looked up keys in about
fifteen places. Its tests built a hand-made array, so no test compared the PHP
reader with what the Go build writes.

## Decision

- `SiteContract` in `application/support/` holds the fields PHP reads, with
  their types. A missing or mistyped key fails at read time with its name.
- `application/tests/site.json` is a reference file. A Go test compares it with
  what `siteconfig.Write` produces for a fixed site, and the PHP tests build
  their contract from it.
- `build/install-translations.php` keeps its own read of `languages`.

## Consequences

- A change to `site.json` fails the Go test until the reference file holds the
  new output, and the PHP tests then read that output.
