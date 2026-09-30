# A start detaches, and a token-checked channel stops it

Accepted on 2026-09-30.

## Context

A start held its terminal until Ctrl+C, so running `SITE drush` needed a second
terminal. Nothing else stopped a site. On Windows a process with no console
gets no Ctrl+C, so a background site there had no graceful stop at all.

## Decision

- `SITE` and `SITE start` are one command, and it detaches. `--foreground`
  serves in the terminal as before. A double-clicked Windows start owns its
  console and serves in the foreground.
- A detaching start takes the Serving lease first, so a handover or a refusal
  reaches the reader's terminal. It then releases the lease and runs itself
  with `--foreground` as a background child, which takes the lease again.
- The launcher's `detach` word starts that child in a new session on Unix, and
  on a hidden console on Windows. The child writes to `server.log`, truncated
  per start. The start relays that log until the ready line, then prints the
  log path and the stop command.
- Once the site answers, the server binds `127.0.0.1` on a random port. It
  writes `stop.json` with the port, a 32-byte token and its PID, owner-only,
  then prints the ready line.
- The channel serves `POST /stop` with the token as a bearer value, compared in
  constant time. A valid stop sends the server SIGINT, the path Ctrl+C takes.
  Windows cannot deliver that signal, so there the server arms its stop guard,
  calls `caddy.Stop()` and exits.
- `SITE stop` tests the lease before it reads the record, so a record a killed
  server left behind never counts. It waits at most 15 seconds for the lease.
- The engine executable's folder mode keeps `serving.lock`, `stop.json` and
  `server.log` in a cache entry keyed by the folder's path.

## Considered options

- Caddy's admin API on a TCP port. It has no authentication, and any local
  process could load a config that serves the reader's files.
- Caddy's remote admin. It limits a client to `/stop` through mutual TLS, but
  it is experimental and JSON-only, and it needs a local CA.
- Caddy's admin on a Unix socket. It grants full admin to the socket's owner,
  and a socket path is capped at 104 to 108 bytes.
- A stop file the server polls.
- Detaching from PHP. PHP cannot hide a Windows console, so closing the window
  would end the site.
- Detaching only when a terminal is attached. Every platform would need a
  pseudo-terminal to test it, and `docker run -it` would still detach.

## Consequences

- A container or systemd unit running a bare `SITE` exits once the site is
  ready. It passes `--foreground`, under which ADR 0009's start without a
  terminal works as before.
- The conformance harness starts every foreground site with `--foreground`.
- Folder mode now holds a lease, which ADR 0022's amendment records.
- Each detached Windows site keeps one more waiting process, the PHP start
  that holds the lease.
