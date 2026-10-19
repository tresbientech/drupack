# A loopback site answers on its drupack.site name

Accepted on 2026-10-19. Amends [ADR 0036](0036-a-loopback-site-serves-https-from-a-trusted-root.md).

## Context

Every site on a machine answered on `localhost`. Browsers key cookies by host
and ignore the port, so two sites shared one cookie jar: signing in to one
signed the reader out of the other.

`*.localhost` resolves inside browsers only. systemd-resolved answers `::1`
alone, slim containers answer nothing, and the macOS and Windows resolvers do
not special-case it. The public zone `drupack.site` answers `*.drupack.site`
with `127.0.0.1`, as `*.ddev.site` does for DDEV.

## Decision

- Each site has a Site host, `<label>.drupack.site`:
  - a Packaged site takes its label from `name` in `drupack.yml`
  - an engine start takes the Project folder's name
  - the label is lowercased, every character outside `[a-z0-9-]` becomes `-`,
    it is cut to 63 characters, and dashes at either end are dropped; an empty
    label becomes `site`
- The runtime's `tls-source` word decides the host and the TLS source in one
  answer. `launch.php` and `serve.php` pass the bind and the label source, and
  read the answer.
- The host a start's URL names:
  1. `--host`, when the reader gave it.
  2. The name, when the bind is `127.0.0.1` and the system resolver answers the
     name with `127.0.0.1` within one second.
  3. Otherwise `localhost` for a Packaged site and the bind address for the
     engine. A start that tried the name prints one line naming the answer.
- The Listener record keeps the chosen host, so `drush` and a handover use it.
- Caddy serves the name beside `localhost`, `127.0.0.1` and `[::1]`, whichever
  host the URL names, and `settings.php` trusts it.
- HTTPS covers the name. The Drupack root's name constraint permits
  `drupack.site` and its subdomains, and mkcert writes its certificate for
  `*.drupack.site` too. The root check runs for the chosen host.
- `DRUPACK_DNS_ANSWER` replaces the lookup with a fixed answer. The conformance
  harness sets it to `none`, so no case depends on the machine's resolver.

## Consequences

- Sites on one machine keep separate cookies whenever the name resolves.
- An offline machine, or a resolver that drops private answers, serves on
  `localhost` and says why. Any answer without `127.0.0.1`, `0.0.0.0` included,
  counts as a miss.
- Every start waits for one DNS lookup, at most one second.
- The record answers `127.0.0.1` alone, so a start bound to `::1` or another
  loopback address keeps `localhost`.
- A root made before this change cannot vouch for the name. No released root
  exists, so nothing migrates one: `trust --uninstall`, then `trust`, makes a new
  one.
- A Project folder whose own settings restrict trusted hosts must allow the
  name, for example with `^.+\.drupack\.site$`.
