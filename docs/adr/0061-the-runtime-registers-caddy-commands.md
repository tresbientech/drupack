# The runtime registers Caddy commands

Accepted on 2026-11-11. Amends [ADR 0011](0011-serving-lease-owns-site-data.md),
[ADR 0050](0050-the-launcher-owns-a-start.md) and
[ADR 0058](0058-a-start-writes-its-logs-on-request.md).

## Context

The runtime's code is copied into FrankenPHP's main package. Its `init()`
rewrote `os.Args` to Caddy's `run --config -` and fed the adapted configuration
through a pipe on standard input. Control never came back, so a goroutine polled
`/.drupack-id` for up to two minutes. When that poll timed out, the server kept
serving with no cron and no stop channel. Eleven failures ended in `panic`.

## Decision

- The runtime registers `drupack-serve`, `php` and `drupack-version` with
  `caddycmd.RegisterCommand`, as FrankenPHP registers `php-server` and `php-cli`.
  `init()` reads no argument, and nothing rewrites `os.Args` or `os.Stdin`.
- `drupack-serve CADDYFILE [PROJECT_FILE...]` adapts the Caddyfile and calls
  `caddy.Load`. Ready means that call returned and Caddy holds an unexpired
  certificate for every name its internal issuer signs for.
- The runtime reads those names from the adapted configuration. It waits for
  their certificates for 5 seconds at most, then prints the ready line.
- The stop channel opens before the ready line. A stop channel that cannot open
  stops Caddy and fails the start.
- A command returns its failure. Caddy's command line prints it as one `Error:`
  message and exits with 1.
- Cobra owns `--version` on Caddy's command line, so the launcher turns a
  reader's `--version` or `-v` into `drupack-version`.
- The code stays in package `main`, and no build path changes.

## Considered options

- A package in FrankenPHP's tree, a separate Go module and an xcaddy build each
  change four build paths. Their one gain is a package another build can
  import, which nothing needs.
- One probe after the load lost to the owner's ruling to drop the probe.

## Consequences

- The `/.drupack-id` route, `DRUPACK_RUNTIME_ID`, `DRUPACK_RUNTIME_URL` and
  `DRUPACK_RUNTIME_TLS_ROOT` are gone.
- Caddy issues a local certificate after it loads the configuration, and the
  certificate lasts 12 hours. A start that issues prints its ready line about
  15 ms later, and up to 105 ms later as measured on Linux amd64.
- `drupack-serve` takes no early log buffer and no container memory limit from
  Caddy's `run` command, as FrankenPHP's `php-server` takes neither.
- An application directory the runtime cannot enter now fails `php` too.
