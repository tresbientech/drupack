# A start names the proxies it trusts

Accepted on 2026-11-10.

## Context

A server behind a reverse proxy logged the proxy's address as `remote_ip` and `client_ip` in the access log of [ADR 0058](0058-a-start-writes-its-logs-on-request.md). The rendered Caddyfile set no `trusted_proxies`, so Caddy ignored `X-Forwarded-For`. tresbien.tech runs behind Nginx Proxy Manager, and every line of its log would name the proxy.

The forwarded address was still in each line, under `request.headers`. Nothing checked who sent the header.

## Decision

- `--trusted-proxies CIDR[,CIDR]` names the proxies in front of a server, on the engine start and the packaged site start.
- The Caddyfile lists them as `trusted_proxies static` in the global `servers` block, so both ports read them.
- An entry is an address or a CIDR range. The start refuses any other text.
- No proxy is trusted without the option.
- The start refuses `0.0.0.0/0` and `::/0`.
- The option is a start option, by [ADR 0059](0059-composer-json-holds-what-an-engine-start-reads.md): the proxy belongs to the machine a server runs on, and the same project runs with none in development.

## Consequences

- The access log's `client_ip` holds the client for a request a trusted proxy sent. `remote_ip` still holds the proxy.
- A forwarded header from an untrusted sender changes nothing, so a client that reaches a port directly cannot name its own address.
- PHP's `REMOTE_ADDR` stays the proxy's address, measured on FrankenPHP 1.13.5. Drupal reads the client from the header once `settings.php` sets `reverse_proxy` and `reverse_proxy_addresses`. Drupack sets neither.
- Caddy's `private_ranges` shortcut is not accepted. A reader writes the ranges out.
- A packaged site's drush reads the start's options, so it accepts this one and ignores it.
