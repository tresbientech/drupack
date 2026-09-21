# The release carries its own trust store and php.ini

Accepted on 2026-09-21.

## Context

A Windows site owner read "Failed to fetch available update data" on the status
report. The static PHP build carries no CA bundle, and Windows has no system
path that curl reads.

## Decision

- `runtime/cacert.pem` ships beside the runtime, vendored from curl's published
  bundle and verified against its checksum.
- `runtime/php.ini` names it through `curl.cainfo` and `openssl.cafile`, both
  read from `${DRUPACK_CA_FILE}`.
- The launcher exports `PHPRC` and `DRUPACK_CA_FILE` from one function that both
  launch paths call. `PHPRC` always wins over a caller's value.
  `DRUPACK_CA_FILE` yields to a caller who set it, so a site owner can point at
  their own bundle.
- Every exported path takes the canonical form.

## Consequences

A release reaches updates.drupal.org with no host CA package installed. The
bundle ages with the release, so a stale executable trusts a stale set.
