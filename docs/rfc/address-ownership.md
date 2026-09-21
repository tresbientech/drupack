# Answer who owns an address without building an executable

Proposed on 2026-09-21. Not implemented.

## The question

A start asks the address it is about to bind who holds it, and acts on one of three answers. [The handover RFC](port-in-use-handover.md) decided those answers. The check now lives in 13 lines of `runtime/launch.php` and one route in `runtime/Caddyfile`, with no test below a built executable. How does a change to either side get checked?

## What ships today

`portOwner()` (`runtime/launch.php:212`) connects to the address, then fetches `/.drupack-id?id=<token>` and reads for status 204. `runtime/Caddyfile:24-30` answers 204 for a matching token and 404 for anything else.

The two sides agree on three things, spelled twice in two languages:

- the path `/.drupack-id`
- the query key `id`
- the success status 204

`siteToken()` (`:203`) hashes the resolved Site data path, lowercased on Windows. `personPresent()` (`:227`) reads a terminal or `DRUPACK_RUNTIME_CONSOLE_OWNED`. The main block at `:748-760` validates `--listen` with a regex, then re-parses the result into a bind and a port. `handOver()` (`:505`) mints a link and replaces the process.

Seven conformance cases cover this ground: four in `handover_cases.py`, three in `network_cases.py`. The network cases run inside Docker containers.

## Why this decision is worth making

The probe has a two-stage shape that a reader gets wrong easily. A refused connection means free. A connection that opens, followed by a fetch that fails or returns any other status, means foreign. The comment at `:209` records why a trial bind was rejected: Windows `SO_REUSEADDR` reports a taken port as free.

Nothing proves the PHP prober and the Caddy route still agree. A future edit to either file passes review and passes CI until a second start meets a first start on a real machine.

The probe is a pure function of an address and a token. A stub HTTP server on a loopback port is four lines of PHP. Today the cheapest check is a Docker build.

## Decision

Add `runtime/listener.php` with one final class and three constants.

```php
// The route a served site answers to name its Site data. runtime/Caddyfile
// answers this path, and a conformance case asserts both spellings match.
const IDENTITY_PATH = '/.drupack-id';
const IDENTITY_QUERY = 'id';
const IDENTITY_STATUS = 204;

final class Listener
{
    // Parses and validates a listen address and a permitted request host.
    // Throws InvalidArgumentException on anything Caddy must not receive.
    public static function parse(string $listen, string $host): self;

    public function bind(): string;
    public function port(): int;
    public function host(): string;

    // The address a reader types, never the bind address.
    public function url(): string;

    // Who holds this address: 'free', 'mine' or 'foreign'.
    public function owner(string $token): string;

    // The port a refusal suggests instead.
    public function nextPort(): int;
}
```

### How a caller uses it

```php
$listener = Listener::parse($options['listen'], $options['host']);
$url = $listener->url();

$owner = $listener->owner(siteToken($data));
if ($owner === 'mine' && personPresent()) {
    handOver($binary, $url, $data, $options);
}
if ($owner !== 'free') {
    throw new RuntimeException($owner === 'mine'
        ? "Drupack already serves this Site data at $url: $data"
        : "Another program is listening on {$options['listen']}. Stop it, or start on"
            . " a free port: drupack --listen {$listener->bind()}:{$listener->nextPort()}");
}
```

### What the class hides

- The `--listen` regex, the bracket stripping for IPv6, and the `FILTER_VALIDATE_IP` and port-range checks.
- The host check, which accepts a hostname or an IP address.
- The wildcard rule: a bind of `0.0.0.0` or `::` gets probed on `127.0.0.1`.
- The bracket rule that an IPv6 host needs in a URL, applied once for the probe and once for `url()`.
- The two-stage probe, its 1 second connect budget and its 2 second read budget.

### What stays outside

`siteToken()` stays a free function. It belongs to the Site data directory, and [the Site data RFC](site-data-owns-its-layout.md) may claim it later. `personPresent()` and `handOver()` stay where they are: one reads the terminal, the other mints a link and execs.

## Dependency strategy

Local-substitutable. `parse()` touches nothing. `owner()` needs a listening socket, which a test creates with `stream_socket_server()` on a port the operating system picks.

A test asserts all three answers:

- Nothing listening returns `free`.
- A stub answering `IDENTITY_STATUS` for the matching token returns `mine`.
- A stub answering 404, a plain socket that accepts and closes, and a server that never replies each return `foreign`.

## Tests

| Case today | After |
|---|---|
| `HandoverCases.test_a_start_whose_site_already_serves_opens_the_browser` | Stays. It proves the browser opens and the first server survives. |
| `TakenPortCases.test_a_port_another_program_holds_stops_the_start` | Shrinks. The verdict becomes a `foreign` unit test against a plain socket. The exit code and the message stay e2e. |
| `TakenPortCases.test_a_different_site_on_the_port_stops_the_start` | Shrinks. The verdict becomes a `foreign` unit test against a stub answering 404. The two-server case stays e2e. |
| `TakenPortCases.test_a_second_start_with_no_terminal_stops` | Stays. It asserts the `personPresent` branch, which needs a real process without a terminal. |
| `NetworkListener.test_default_listener_refuses_another_container` | Stays. It proves a bind address reaches or refuses another container. |
| `NetworkListener.test_explicit_listener_serves_the_site_and_protects_private_paths` | Stays. It asserts Caddy route behaviour. |
| `NetworkListener.test_a_site_data_path_with_a_space_serves_the_login_page` | Stays. |

New tests with no counterpart today:

- `parse()` accepts `127.0.0.1:7225`, `0.0.0.0:80` and `[::1]:7225`.
- `parse()` refuses `localhost:7225`, `127.0.0.1`, `127.0.0.1:0`, `127.0.0.1:65536` and `999.1.1.1:7225`.
- `parse()` refuses a host of `example.com/evil` and accepts `example.com` and `192.168.1.10`.
- `url()` on an IPv6 host brackets it, and never names the bind address.
- `owner()` returns `free`, `mine` and `foreign` against the four stubs above.
- `owner()` on a wildcard bind probes loopback.
- `nextPort()` on 65535 does not offer 65536.

One new conformance case, replacing nothing: read `runtime/Caddyfile`, assert it spells `IDENTITY_PATH` and `IDENTITY_STATUS` as `runtime/listener.php` declares them. This is the check that exists nowhere today.

## Consequences

- The three-answer verdict gains tests that run without Docker and without a built executable.
- A drifting Caddy route fails a test instead of a second start on a user machine.
- The `--listen` regex gains negative cases. Today no test feeds it a bad address.
- `runtime/` gains a third PHP file, so `packaging/dev-entry.sh:20` copies six names.
- `launch.php` loses about 45 lines from its main block, where the validation currently sits inline.
- `nextPort()` fixes a small defect on the way: the message at `:833` offers `$port + 1` without a range check.

## Considered options

- Export the route as a new `DRUPACK_RUNTIME_ID_PATH` and have the Caddyfile read it. The two sides then cannot drift. It adds a fourteenth variable to the protocol [another RFC](runtime-handover-protocol.md) proposes to shrink, and nobody would ever set it.
- Generate the Caddyfile from PHP at start. It removes every duplicated placeholder, and it makes the served configuration invisible to a reader inspecting the shipped file.
- Keep `portOwner()` as a free function and test it directly. Cheapest. The address parsing stays inline in the main block, where no test reaches it, and that is where the untested regex lives.
- Add conformance cases for the bad addresses. Each costs a process start, and the regex has about eight cases worth covering.

## Order of work

1. `runtime/listener.php` with `parse()`, its accessors and their unit tests. No caller changes.
2. The main block calls `Listener::parse()`. Delete the inline regex and the checks under it.
3. Move `portOwner()` in as `owner()`, with its stub tests. Delete the free function.
4. The Caddyfile agreement case in `tests/conformance/`.
5. `packaging/dev-entry.sh` copies the new file.

## Out of scope

- The handover behaviour itself, decided in [port-in-use-handover.md](port-in-use-handover.md).
- The listener record in Site data, claimed by [site-data-owns-its-layout.md](site-data-owns-its-layout.md).
- Caddy's protected-file matchers, which the network cases already cover.
