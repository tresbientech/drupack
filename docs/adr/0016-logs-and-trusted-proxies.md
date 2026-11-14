# A start writes logs and names proxies on request

## Context

Caddy's global `log` block configures the process log, and an access log needs a `log` directive in a site block. Caddy refuses that directive inside `route`, where a project's `drupack.caddy` is imported. An engine server's process log goes to standard error at `ERROR`, which drops most PHP messages. A server behind a reverse proxy sees the proxy's address as the client.

## Decision

### Logs

- `--access-log[=FILE]` logs each request of the site port and of the static port to one JSON file. The named loggers `http.log.access.site` and `http.log.access.static` tag its lines. The 421 a listener gives an unknown name is logged under its port's logger.
- `--error-log[=FILE]` logs PHP and Caddy messages at `WARN` and up to one JSON file. The access loggers stay out of it.
- Both options are off by default. The bare option writes a default file, and `--option=FILE` names another. `--option FILE` is refused, because the next word of an engine start can be its folder.
- A Packaged site's default files are `logs/access.log` and `logs/error.log` in Site data. The engine's are `access.log` and `error.log` in the folder's cache entry.
- The engine start and the Packaged site start both take both options. A Packaged site's `drush` reads the start's options, so it accepts them and ignores them.
- Both options add a file and change no other output. The engine's process log goes to standard error at `ERROR`, or to `caddy.log` in the cache entry for a detached start. A Packaged site writes `logs/caddy.log` and `logs/server.log`.
### Log files

- Each file rolls at 100 MiB and keeps 10 rolled files. Caddy creates them with mode 0600.
- A FILE resolves against the working directory. The start refuses a FILE that holds a brace, one inside a directory the server answers requests from, and one file named by both options.
- On Windows, `build/windows/build.ps1` rewrites FrankenPHP's `go_log` level numbers, so a Windows log holds the levels the other platforms hold.

### Trusted proxies

- `--trusted-proxies CIDR[,CIDR]` names the proxies in front of a server, on the engine start and the Packaged site start.
- The Caddyfile lists them as `trusted_proxies static` in the global `servers` block, so both ports read them.
- An entry is an address or a CIDR range. The start refuses other text, and refuses `0.0.0.0/0` and `::/0`.
- No proxy is trusted without the option.
- The access log's `client_ip` holds the client for a request a trusted proxy sent. `remote_ip` holds the proxy.
- PHP's `REMOTE_ADDR` stays the proxy's address. Drupal reads the client from the header once `settings.php` sets `reverse_proxy` and `reverse_proxy_addresses`. Drupack sets neither.
- The option is a start option, not a `composer.json` setting, as [0009](0009-the-engine-executable.md) sorts them. The proxy belongs to the machine, and the same project runs with none in development.

## Reason

One file holds both ports, so one `tail` or one shipper reads a site's whole traffic. A reader who wants one port filters on `logger`. Logs are off by default so no start records client addresses unasked and no upgrade fills a disk. The access log holds request paths, including a one-time login link when a reader follows one, so the file's mode keeps it to its owner.

The level of the error log is fixed at `WARN`. FrankenPHP logs a fatal at `ERROR` and a warning at `WARN`. Notices, deprecations and `error_log()` calls go to `INFO`, and no option reaches them.

Trusting a forwarded header from an unnamed sender lets a client name its own address. The option names the senders, and the refusal of a range that covers every address keeps the option from disabling that check.
