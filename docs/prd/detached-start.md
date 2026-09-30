# PRD: A start that returns the prompt, and a stop

Source: design settled on 2026-09-30, after a clarify session and four competing
designs.

## Problem Statement

A reader starts a site and the terminal stays busy until Ctrl+C. Running
`SITE drush status` needs a second terminal. A Windows reader who closes the
window ends the site, and nothing else stops it. The engine executable's folder
mode behaves the same way.

## Solution

`SITE` and `SITE start` are one command. A start prints its progress, waits
until the site answers, and returns the prompt. The site keeps serving in the
background, and `SITE stop` ends it. `--foreground` keeps today's behaviour for
containers, systemd units and tests. The engine executable gets the same pair:
`drupack [start] [DIR]` and `drupack stop [DIR]`.

## User Stories

1. As a reader, I want `SITE` to return the prompt once the site answers, so that I run Drush in the same terminal.
2. As a reader, I want `SITE start` to act exactly as `SITE`, so that either form works.
3. As a reader, I want a first start's progress, login link and generated password in my terminal, so that detaching hides nothing.
4. As a reader, I want the start to name its log file, so that I can read what the server wrote later.
5. As a reader, I want `SITE stop` to end the site the way Ctrl+C does, so that requests and cron finish cleanly.
6. As a reader, I want `SITE stop` with no running site to say so and succeed, so that a script can call it blindly.
7. As a reader, I want `SITE stop` to fail and say so when the site does not stop in time, so that I know to look.
8. As a reader, I want a second `SITE` while the site runs to hand me the running site, so that nothing changes there.
9. As a reader, I want a start that fails before the site answers to exit with the failure and its message, so that I see why.
10. As a reader, I want the browser to open on a detached start, so that detaching changes nothing I see.
11. As a reader on Windows, I want a detached site to survive closing the terminal and open no window, so that it runs in the background.
12. As a reader on Windows, I want a double-clicked site to stay in its window, so that I can still stop it.
13. As an operator, I want `--foreground` to serve until a signal, so that a container or systemd unit keeps its process.
14. As a reader, I want `SITE stop` to stop a foreground site too, so that I can stop it from another terminal.
15. As a reader of the engine executable, I want `drupack` in a folder to detach and `drupack stop` to stop it, so that folder mode matches a site.
16. As a reader of the engine executable, I want a start to leave my project folder untouched, so that nothing new appears in my repository.
17. As a reader, I want a second `drupack` on a served folder to say it already serves, so that I get a sentence instead of a bind error.
18. As a reader sharing a machine, I want only my account able to stop my site, so that another user cannot.
19. As a reader, I want the stop channel on loopback whatever `--listen` says, so that no other computer reaches it.
20. As a maintainer, I want every platform to run the detached cases in CI, so that a regression stops the release.

## Implementation Decisions

Command line:

- D1. `start` and `stop` are command words. A bare `SITE` means `start`. The
  engine executable reserves both words, so a folder named `start` takes
  `./start`.
- D2. `--foreground` is a new option on every start. The parser, the usage text,
  the help and `docs/cli.md` gain it, and the parity test covers it.
- D3. A start detaches unless `--foreground` is set or the Windows console
  belongs to the process (a double-click). Those two serve in the foreground.
- D4. `stop` takes `--data-dir` alone. The engine form takes the folder, the
  reader's directory by default.

Detaching:

- D5. The start takes the Serving lease first. A held lease leads to today's
  handover or refusal, and a taken port refuses, all in the reader's terminal.
- D6. With the lease free, the start releases it and runs the same command with
  `--foreground` as a detached child. The child takes the lease again. A child
  that loses that race prints its refusal, which the start relays.
- D7. The launcher owns detaching, through a new internal word. Its platform
  split follows the existing one: a new session on Unix, a hidden console on
  Windows (`CREATE_NO_WINDOW`). The runtime build copies no new file.
- D8. The child writes stdout and stderr to `server.log`, truncated per start.
  Its standard input is the null device.
- D9. The start tails `server.log` to the terminal until the ready line, then
  prints the log path and the stop command and exits 0.
- D10. A child that exits before the ready line makes the start exit with its
  code. A readiness timeout makes the start exit 1 and say the server runs.
- D11. The start passes a person-present flag, since the child has no terminal.
  The child then opens the browser and hands over as a watched start does.

Stop channel:

- D12. Once the site answers, the server binds `127.0.0.1` on a random port. It
  writes `stop.json` with the port, a 32-byte random token and its PID, owner-only.
  It then prints the ready line. A returned start is always stoppable.
- D13. The channel serves one route, `POST /stop`, with the token as a bearer
  value compared in constant time. Every other request gets 404, a wrong token
  403. Caddy's admin stays off.
- D14. A valid stop first sends the process SIGINT, which runs Ctrl+C's path. On
  Windows that call fails, so the server arms its stop guard, calls `caddy.Stop()`
  and exits 0.
- D15. `stop` tests the lease first. A free lease means not running: exit 0, and
  a stale `stop.json` is ignored. A held lease with no record means a start is
  still preparing: exit 1.
- D16. Otherwise `stop` posts the token and waits for the lease to free, 15
  seconds at most. The server's own forced exit comes at 10 seconds. Past 15
  seconds `stop` exits 1.
- D17. A site keeps `stop.json` and `server.log` in Site data, beside
  `serving.lock`.

Folder mode:

- D18. A folder's state lives in a cache entry keyed by a hash of its canonical
  path: `serving.lock`, `stop.json` and `server.log`. The project folder gets no
  new file.
- D19. A folder start takes that entry's lease. A held lease refuses with
  "already serves", without a handover.
- D20. The folder server runs the same runner as a site, with no cron. The
  separate folder server word goes, and the engine Caddyfile gains the
  readiness route.

Records:

- D21. A new ADR records the detach default and the stop channel. ADR 0022 gains
  an amendment: folder mode now holds a lease on its cache entry.

## Considered options

- Caddy's admin API on a TCP port. It has no authentication, and any local
  process could load a config serving the reader's files.
- Caddy's remote admin with mutual TLS. It restricts paths, but it is
  experimental, JSON-only, and needs a local CA and a client certificate per start.
- Caddy's admin on a Unix socket. It grants full admin, and a socket path is
  capped at 104 to 108 bytes.
- A stop request file the server polls, rejected by the owner.
- Detaching from PHP. PHP cannot hide a Windows console, so closing the window
  would end the site.
- Detaching before the lease, with the handover relayed from the child. A
  handover would then append to a live site's log.
- `CTRL_BREAK` on Windows. It reaches every process on the console, so stopping
  a foreground site would also interrupt the reader's own Drush.
- Detaching only when a terminal is attached. Testing it needs a pseudo-terminal
  on each platform, and `docker run -it` still detaches.

## Testing Decisions

- A good test drives the executable as a reader does: start, Drush, stop. It
  checks exit codes, output, the lease and the port.
- The harness's start helpers pass `--foreground`, so existing cases keep their
  behaviour. A detached case ends with `stop`, and falls back to the PID in
  `stop.json`.
- New conformance cases on Linux, macOS and Windows:
  - a start returns 0 after the ready line, prints the log path, and Drush then works
  - a first start's password and login link appear on the start's own output
  - `stop` exits 0, frees the lease and closes the port; a second `stop` says not running
  - a wrong or missing token leaves the site serving
  - the stop channel is on loopback under `--listen 0.0.0.0`
  - a start that fails early exits with the child's code
  - a second start hands over
  - `stop` ends a foreground site
  - a folder start and stop leave the project unchanged, and a second folder start says it already serves
- A Go unit test covers the stop handler's token check and route.
- Prior art: the harness's start and stop helpers, the engine cases' folder
  snapshot, and the handover cases.

## Out of Scope

- `status`, `logs` and `restart`, which go to the backlog.
- Log rotation for `server.log`.
- Pruning folder cache entries in `clean`.
- An environment switch for foreground mode.
- Surviving a CI job object that kills its children on close.

## Further Notes

- Detaching by default changes the contract ADR 0009 set for containers and
  systemd. A container running a bare `SITE` exits once the site is ready. The
  docs name `--foreground` wherever they show such a use.
- It ships in the next release, which also carries the install message change.
