# A start writes its logs on request

Accepted on 2026-11-10.

## Context

No start logged a request. Both `log` blocks of the rendered Caddyfile are global, and a global `log` configures Caddy's process log. An access log needs a `log` directive in a site block, and Caddy refuses that directive inside `route`. A project's `drupack.caddy` is imported inside `route`, so a project could not add one.

An engine server also lost most PHP messages. Its process log goes to standard error at `ERROR`. Measured on FrankenPHP 1.13.5, PHP logs a fatal at `ERROR`, a warning at `WARN`, and a notice, a deprecation or an `error_log()` call at `INFO`.

## Decision

- `--access-log[=FILE]` logs each request of the site port and of the static port to one JSON file. The named loggers `http.log.access.site` and `http.log.access.static` key its lines.
- `--error-log[=FILE]` logs PHP and Caddy messages at `WARN` and up to one JSON file. The access loggers stay out of it.
- Both options are off by default. The bare option writes a default file, and `--option=FILE` names another. `--option FILE` is refused, since the next word of an engine start can be its folder.
- A packaged site's default files are `logs/access.log` and `logs/error.log` in Site data. An engine's are `access.log` and `error.log` in the folder's cache entry.
- The engine start and the packaged site start take both options.
- The outputs a start already had do not change: the engine's standard error at `ERROR`, and `logs/caddy.log` and `logs/server.log` in Site data.
- Both files roll at 100 MiB and keep 10 rolled files, as `logs/caddy.log` does. Caddy creates them with mode 0600.
- A FILE resolves against the working directory. The start refuses a FILE that holds a brace, and one inside a directory the server answers requests from.
- The start prints the path of each log it writes.
- The launcher's readiness polls of `/.drupack-id` stay out of the access log.

## Consequences

- One file holds both ports, so one `tail` or one shipper reads a site's whole traffic. A reader who wants one port filters on `logger`.
- Off by default means no start records client addresses unasked, and an upgrade fills no disk.
- The access log holds request paths, which include a one-time login link when a reader follows one. The file's mode keeps it to its owner.
- The engine's default files sit in a cache folder that `drupack stop` and `clean` manage. A server run under a service manager names its own FILE.
- A packaged site's drush reads the start's options, so it accepts both and ignores them.
- The level of the error log is fixed. A reader who wants PHP notices and deprecations has no option for them.
- PHP's Windows headers number `LOG_ERR` 4 and `LOG_WARNING` 5, and FrankenPHP 1.13.5 reads the Unix numbers. `build/windows/build.ps1` rewrites both in `go_log`, so a Windows log holds the levels the other platforms hold.
- An engine's Caddy log still drops PHP warnings without `--error-log`. The backlog holds that.
- A detached engine first sent that log to `server.log`, which never rolled. Since 2026-11-10 it writes `caddy.log` in the folder's cache entry, rolled like the other files, and `server.log` holds the start's own output.
