# A start plans its listener

Accepted on 2026-10-20.

## Context

`launch.php` worked out where a start serves along its top-level script. The
host took four meanings on the way: the reader's `--host`, the recorded host,
the host `tls-source` chose, then `SiteData::UNRECORDED_HOST`. `putenv` calls
spread through the script exported the result. `serve.php` repeated the same
tail by hand. Only the Docker conformance cases caught a wrong combination.

## Decision

- Each caller decides the host and scheme once. A start and the engine take
  `tls-source`'s answer. `drush` takes the recorded listener.
- `listenerPlan()` in `process.php` turns the bind address, port, host and
  scheme into the URL a reader types and the URL a probe reaches.
- `serverEnvironment()` adds the listener and the TLS snippets to the caller's
  own Caddy variables. `exportServerEnvironment()` keeps the empty-value and
  double-quote check.
- `siteEnvironment()` in `launch.php` builds what Drush and the site's PHP read,
  for a start and for `drush`.

## Consequences

- A wrong URL, host or variable shows in the return value of a pure call.
- The engine hands Caddy an IPv6 bind address without brackets, as a site does.
