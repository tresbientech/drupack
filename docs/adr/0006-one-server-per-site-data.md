# One server runs per Site data, claimed by a lease and stopped through a token channel

## Context

Two FrankenPHP processes over one database and one runtime directory corrupt both. A background site needs a stop that works on Windows, where a process with no console gets no Ctrl+C. A `drush` call needs the address its site serves on.

## Decision

### The Serving lease

- A start takes an exclusive lock in its state directory: Site data, or a folder's cache entry. It holds the lock until the server exits.
- On Unix the lock lives on an open descriptor that survives the exec into the server. The kernel releases it on a kill. On Windows the launcher waits for the runtime, so its handle holds the lock.
- The same lease covers initialization. No separate startup lock exists.
- A start that cannot take the lease refuses. When a person watches and the site is installed, it hands over: it prints the URL and a login link when one mints, opens a browser on the link or else the URL, and exits 0. A project folder hands over the same way, with `/core/install.php` in place of the link when it holds no installed site.
- A browser the server opens holds no lease.
- No route answers a readiness probe. A port probe asks one question: does another program hold the address.

### The Listener record

- Every start writes `listener` in its state directory: the addresses, the permitted host and the scheme.
- `drush` and `export` read the record as their default. `--listen` and `--host` typed on the command line win.
- A handover reads the record alone and ignores a typed `--listen` and `--host`. With no record it uses its own plan.
- A site start ignores the record's addresses, so `--listen` does not stick. It reads the files folder a record from an earlier release names, and refuses until `files` leads there.
- A site serves on `127.0.0.1:7225` by default, or the `port` its contract names. The engine serves on `127.0.0.1:3787`.
- The server exports `DRUSH_OPTIONS_URI`, so every Drush child builds URLs from the address a reader types.
- A record that is not a JSON object fails the start, naming the file.

### Detach and stop

- `SITE` and `SITE start` are one command. It detaches; `--foreground` serves in the terminal. A double-clicked Windows start owns its console and serves in the foreground.
- The child writes `server.log`, truncated per start. The parent relays it until the ready line, then prints the stop command.
- Once the site answers, the runtime binds `127.0.0.1` on a random port and writes `stop.json`: the port, a 32-byte token and its PID. The file is owner-only on Unix.
- The channel serves `POST /stop` with the token as a bearer value, compared in constant time. A valid stop ends the server the way Ctrl+C does.
- On Windows the server arms its stop guard, calls `caddy.Stop()` and exits.
- `SITE stop` tests the lease before it reads the record, so a record a killed server left behind never counts.
- A container or a systemd unit passes `--foreground`.

### Status and restart

- A start that claims the lease writes `start.json`: its working directory, its words, and whether it detached. The words leave out the `--foreground` a detaching parent appends and the passwords.
- `status` exits 0 while a server answers, 3 with none and 4 while a start prepares, as an LSB status does. A held lease with no `stop.json` is a start that prepares.
- A server that answers also gets the project's `drush status`. Its failure prints one line and keeps the exit code.
- `restart` replays the record of a detached start. It enters the recorded directory, stops the server and starts again in the background, so a relative path names the file the first start named.
- `restart` refuses with no record, and on a foreground start, which its terminal or its service manager restarts.
- The engine refuses `logs` as `DIR`, so a later release can add the command without changing what an existing line does.

## Reason

An HTTP ownership probe trusts any local program that answers it, and a second start on another port finds that port free. A lock on a file in Site data rests on filesystem permissions, and the kernel releases it on a kill.

A command that gets no `--uri` prints `http://default/...` links. Recording the address once lets every command, with the site stopped, build a working URL.

A restart needs the start's whole line again. The Listener record holds addresses that `drush`, `export` and the handover read on every call, so the line gets a record of its own.

Caddy's admin API on TCP has no authentication. On a Unix socket it grants full admin, and a socket path is capped at 104 to 108 bytes. A bearer token on a loopback port limits a client to `/stop`.

Ports 7225 and 3787 spell PACK and DRUP on a phone keypad. Both sit above 1024, so no start needs root, and below 32768, the range Linux draws ephemeral ports from.
