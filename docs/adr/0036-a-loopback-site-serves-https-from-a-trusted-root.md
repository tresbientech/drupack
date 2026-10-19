# A loopback site serves HTTPS from a root the machine trusts

Accepted on 2026-10-19.
Amended on 2026-10-19 by [ADR 0037](0037-a-loopback-site-answers-on-its-drupack-site-name.md):
a start may also be addressed by its drupack.site name, and the root's name
constraint permits `drupack.site`.

## Context

Every site served plain HTTP. Some local agents refuse an MCP address that is
not HTTPS, and browsers drop `__Secure-` cookies over HTTP. A certificate helps
only when the browser already trusts its root, and each system keeps roots in
its own store.

## Decision

- A start on a loopback bind, addressed as `localhost`, `127.0.0.1` or `::1`,
  serves HTTPS from the first root the machine trusts:
  1. mkcert, when `mkcert` is on PATH, `mkcert -CAROOT` holds `rootCA.pem`,
     and the certificate mkcert writes verifies against the system's roots.
     Drupack runs mkcert to write a certificate for the three names and never
     opens mkcert's root key. A certificate within 30 days of expiry, or one
     an earlier mkcert root signed, is written again.
  2. The Drupack root, when a certificate it signs for `localhost` verifies
     against the system's roots. Caddy's pki app signs from it and rotates its
     own intermediate and site certificates. The CA's name carries the root's
     fingerprint: Caddy reuses a stored intermediate without checking it
     against the root, so a new root gets a new name.
- Any other start serves HTTP. A loopback start that could have served HTTPS
  prints one line naming `trust`.
- `trust` creates the Drupack root on first use and installs it in the system
  store and the NSS databases. `--uninstall` removes it from both and deletes it.
  A Packaged site and the engine run the same command.
- The Drupack root is ECDSA P-256 and valid 10 years. Its critical name
  constraint permits `localhost` and its subdomains, `127.0.0.0/8` and `::1`.
  Its extended key usage is server authentication alone, since name
  constraints leave code signing and mail unbound.
  One root serves every site of the user account, in `DRUPACK_TLS_DIR`.
- The listener record keeps the scheme a start served, which `drush` and a
  handover use.

## Consequences

- No start asks for a password. Only `trust` writes a trust store.
- A Site data moving from HTTP to HTTPS signs its reader in once, since Drupal
  names its session cookie per scheme.
- PHP verifies HTTPS against `DRUPACK_CA_FILE`. An HTTPS start writes that
  bundle plus its served root into `ca-bundle.pem` and exports it to the
  server, cron and `drush`, so they reach the site's own hub. An HTTP start
  deletes it, so `drush` stops trusting a root the site no longer serves.
- `--host` with any other name keeps HTTP. On-demand issuance would cover it but
  would let any page make Caddy sign certificates into Site data.
