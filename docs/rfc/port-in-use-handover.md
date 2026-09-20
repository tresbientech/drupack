# Hand over to the site already serving this port

Proposed on 2026-09-20.

## The question

A reader double-clicks the Windows executable while their site already runs. The window prints a full readiness block, then dies on the bind:

```
Drupack is ready

  URL:       http://localhost:7225/
  Login:     http://localhost:7225/user/reset/1/.../login
  Site data: C:\Users\theno\Downloads\data
...
Error: loading new config: http app module: start: listening on 127.0.0.1:7225:
bind: Only one usage of each socket address is normally permitted.
Press Enter to close this window.
```

The reader is told the site is ready and handed a working login link. The window then shows a bind error. This RFC decides how a start behaves on a taken port.

`docs/rfc/dashboard-on-every-start.md:51` left this open: two starts against one site produce two links and one dashboard, and nothing decided what the second start owes its reader.

## What happens today

- `--data-dir` defaults to `./data`, resolved from the working directory (`runtime/launch.php:104`). Explorer sets that directory to the executable's own folder. Every Drupack executable in one folder therefore serves one Site data, whatever its version.
- The startup lock (`runtime/launch.php:298`) guards a start with pending install steps. A second start of an installed site takes no lock and runs the whole sequence.
- That sequence writes the listener record (`:761`), runs install steps, mints a one-time link, prints the readiness block, then replaces itself with FrankenPHP. The bind happens last, after every line the reader trusts.
- `packaging/launcher/launch_windows.go:57` holds an Explorer console open on a non-zero exit, so the reader reads the Caddy error and nothing else.
- A failed start still rewrote the listener record, since the record is written before the bind.

## Decision: ask who owns the address

Before it records a listener or runs an install step, a start asks the address it is about to bind:

```
GET http://<bind>:<port>/.drupack-id?id=<token>   timeout 2s
```

- The token is the SHA-256 of `realpath($data)`, lowercased on Windows, where two launchers can spell one path with different casing.
- A served site answers from its own Caddy configuration, which carries the token in `DRUPACK_RUNTIME_ID`. The `respond` for this path sits ahead of the protected-file matchers, and a request without the matching token gets 404, so no request falls through to the file server.
- The check connects rather than test-binds. A trial bind reports a taken port as free under Windows `SO_REUSEADDR`.
- The probe address is the bind address, or `127.0.0.1` when the bind is a wildcard. Caddy answers this route for any `Host`.

## Decision: what each answer does

| Answer | Meaning | Start does |
|---|---|---|
| Connection refused | Port free | Serves, as today |
| 204 | My own Site data | Hands over |
| Anything else | Foreign owner | Names the port, exits 1 |

A handover mints a one-time link to `/admin/dashboard`, prints the block below, opens the browser, and exits 0. It writes no listener record, since the running server owns that address.

```
Drupack is already serving this Site data.

  URL:    http://localhost:7225/
  Login:  http://localhost:7225/user/reset/1/.../login
```

A `LoginLinkFailure` (`runtime/launch.php:417`) during a handover takes the path the catch at `:795` already takes: print the reason and the `dr user:login` hint, open the plain URL. A handover runs no install step, so `credentialsRequired()` is false and the failure never rethrows.

A handover needs a person: a terminal on standard input, or `DRUPACK_RUNTIME_CONSOLE_OWNED=1` from a file manager. `runtime/launch.php:810` already uses that condition for the browser. Without a person, both taken-port cases exit 1 with their own message.

## Consequences

- A script never inherits a server it did not start. `docs/rfc/test-architecture.md:34` records the cost of the other choice: a leaked server on 7225 answered for a later suite, and the suites passed against the wrong site.
- Every served site answers one new route. A caller who already knows the Site data path can confirm a Drupack serves there. A caller who does not gets 404.
- An older running version has no such route, so a newer executable reports a foreign owner on a port its own sibling holds. The foreign message therefore names the possibility and says to stop the other program first.
- The probe costs one refused loopback connection on the common path.
- A port conflict no longer reaches the reader as a Caddy error. Other bind failures, such as a privileged port or an unavailable address, still print after the readiness block.
- `README.md:95` describes a second start as stopping with a message. It becomes: a second start of a served site opens the browser on it.

## Tests

Three conformance cases, on ports the operating system picks:

- A handover. Start a site, then start again under a pseudo-terminal with a recorder named `xdg-open` on `PATH`. The second start exits 0, the recorder holds a `/user/reset/1/` link, the first server still answers, and no second server exists.
- A foreign owner. Hold a plain socket on a private port, start Drupack with `--listen` on it. The start exits 1, the message names the port, and the Site data gains no listener record.
- No person. Start twice with no terminal. The second start exits 1 and names the running site.

Windows runs the exit codes and messages. The browser recorder stays on Linux and macOS, as `docs/rfc/dashboard-on-every-start.md` already set out.

## Considered options

- Next free port from 7226, as `docs/rfc/windows-tray-app.md:33` proposed. A second double-click then runs two servers over one SQLite file, one `files` directory and one runtime extraction directory. The address also moves under the reader's bookmarks.
- A pid and address record in Site data. A crash leaves the record behind, and a foreign owner then inherits the reader's browser. Windows liveness from PHP needs `tasklist`.
- Trust the listener record. It says where this Site data last served, never who holds the port now, so a stranger on 7225 receives the reader.
- Hand over for every caller, including scripts. One rule, and it rebuilds the leaked-server failure above.

## Order of work

The mint-failure branch landed as `835f454`, so `LoginLinkFailure` and its catch are in `main`. Implementation starts from there in its own worktree. It touches `runtime/launch.php`, `runtime/Caddyfile`, `tests/conformance/` and `README.md:95`. The browser opener lives in `packaging/entrypoint.go`, which gains the one command a handover uses to reach it, since a handover runs no server of its own.

## Out of scope

- The parked tray app in `docs/rfc/windows-tray-app.md`.
- Readiness printed before the server answers, which every other late failure still shows.
