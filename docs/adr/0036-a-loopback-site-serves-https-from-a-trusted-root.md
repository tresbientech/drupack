# A loopback site serves HTTPS from a root the machine trusts

Accepted on 2026-10-19.

## Context

Every site served plain HTTP. Some local agents refuse an MCP address that is
not HTTPS, and browsers drop `__Secure-` cookies over HTTP. A certificate helps
only when the browser already trusts its root, and each system keeps roots in
its own store.

## Decision

- A start on a loopback bind, addressed as `localhost`, `127.0.0.1` or `::1`,
  serves HTTPS from the first root the machine trusts:
  1. mkcert, when `mkcert` is on PATH and `mkcert -CAROOT` holds `rootCA.pem`.
     Drupack runs mkcert to write a certificate for the three names and never
     opens mkcert's root key. A certificate within 30 days of expiry is written
     again.
  2. The Drupack root, when a certificate it signs for `localhost` verifies
     against the system's roots. Caddy's pki app signs from it and rotates its
     own intermediate and site certificates.
- Any other start serves HTTP. A loopback start that could have served HTTPS
  prints one line naming `trust`.
- `trust` creates the Drupack root on first use and installs it in the system
  store and the NSS databases. `--uninstall` removes it from both and deletes it.
  A Packaged site and the engine run the same command.
- The Drupack root is ECDSA P-256 and valid 10 years. Its critical name
  constraint permits `localhost` and its subdomains, `127.0.0.0/8` and `::1`.
  One root serves every site of the user account, in `DRUPACK_TLS_DIR`.
- The listener record keeps the scheme a start served, which `drush` and a
  handover use.

## Consequences

- No start asks for a password. Only `trust` writes a trust store.
- A Site data moving from HTTP to HTTPS signs its reader in once, since Drupal
  names its session cookie per scheme.
- PHP verifies HTTPS against `DRUPACK_CA_FILE`, which holds no local root, so a
  request from the site to itself over HTTPS fails verification.
- `--host` with any other name keeps HTTP. On-demand issuance would cover it but
  would let any page make Caddy sign certificates into Site data.
