# The launcher plans and runs every start

## Context

A start decides a listener, a TLS source, a host, a scheme, a static listener and the Caddy rules. Those values must come from one place, reach Caddy and PHP as arguments or one environment, and fail in `go test` without Docker when they disagree.

## Decision

### The owner

- The launcher plans and runs every start, `drush`, `export`, `stop` and `trust`, on both executables. PHP runs only through `runtime php-cli`, called by the launcher.
- One parser, `launcher/parse.go`, reads every word and option for both executables. `launcher/help.go` holds the help texts.
- `docs/cli.md` is the CLI reference. A Go test compares it and the help texts against the parser's tables.
- `planStart` turns the parsed words, the site settings, the TLS answer and the recorded listener into the start plan. No later step chooses a listener, a scheme or a static value again.
- A site and the engine are two modes of one start. They differ in the state directory, the settings source (`site.json` or `composer.json`), the first checks and the route of the files directory.
- Node's words (`node`, `npm`, `npx`) run Node from its own release directory. On Unix the launcher replaces itself with Node. On Windows it waits for Node.
- Two hidden leading arguments mark a start from a Windows file manager (`--console-owned`) and the child of a detached start (`--detached-child`).

### The detached start

- A start without `--foreground` or an owned console detaches first. The parent parses the words, refuses a syntax error in the terminal, then starts a background child with `--foreground`.
- The child claims the Serving lease once and runs the whole start: plan, first-start steps or handover, then the server.
- The parent relays the child's output until the ready line or the child's exit, and exits with the child's code.
- On Unix the child execs `runtime drupack-serve`. On Windows it waits for the runtime, so its handle holds the lease until the server exits.
- The child prints through two pipes until it holds the lease. It then truncates the server log under the lease and tells the parent with a marker line.

### The served address

- `Served` is the one reader of the Listener record. A typed value wins over the recorded one, and the recorded one over the default.
- `Address` holds the merged record, the parsed bind address and the port. `Recorded` is false when no start has recorded a listener.
- Both executables read `--listen` and the recorded listener with one parser, which takes IP:PORT and refuses a hostname. A record an earlier engine wrote with a hostname fails until the next start rewrites it.

### A child's environment

- `Facts` holds what a start knows. `Facts.Env(Kind)` is pure and is the one writer of a child's environment. Its kinds are `Reader`, `Server`, `Operate`, `Drush` and `Export`.
- `runtime/environment.txt` declares every `DRUPACK_*` and `DRUSH_*` name `Env` sets, with the kinds and modes that receive it. `TestEnvDeclared` and `TestEnvKinds` hold `Env` to that file.
- A variable the launcher owns overrides an inherited one. Windows matches names in any case.
- Environment variables carry only values that `settings.php`, Drush, Tome's children, Caddy or the runtime's watch read. The launcher passes its own choices as arguments.

### The server config

- The HTTP rules are Caddy fragments in `launcher/internal/caddyfile/fragments`: a main template, one fragment per mode and TLS source, and one each for guards, logs, static serving and the Mercure hub.
- Each start renders the fragments for its plan into one owner-only Caddyfile in the state directory. A feature that is off leaves its fragment out.
- One function quotes every value. The file holds no `{$...}` reference. A start refuses a path holding `{` or `}`, which Caddy reads as a placeholder.
- The Caddyfile imports the project's `drupack.caddy` by absolute path, after the guards and before the hub and `php_server`.

### The runtime's commands

- The runtime registers `drupack-serve`, `php` and `drupack-version` with `caddycmd.RegisterCommand`. Its `init()` reads no argument.
- `drupack-serve CADDYFILE [PROJECT_FILE...]` adapts the Caddyfile once and passes the JSON to `caddy.Load` in memory.
- Ready means the load returned and Caddy holds a certificate for every name its internal issuer signs, for 5 seconds at most.
- The stop channel opens before the ready line. A channel that cannot open stops Caddy and fails the start.
- A command returns its failure, and Caddy's command line prints it as one `Error:` message with exit code 1.
- The launcher turns `--version` and `-v` into `drupack-version`, because Cobra owns `--version`.
- `application/operate.php` runs one named operation per call: `check`, `prepare`, `seed` or `static-directory`. Step lines go to stderr and one JSON record to stdout. Credentials reach it through the environment, never argv.

## Reason

Routers in two languages that each read the reader's words drift apart. A Caddyfile that splices variable values into text before parsing it breaks on a quote. One parser and a pure plan turn a listener or TLS change into one edit in the plan and one fragment.

Caddy JSON built in the launcher cannot adapt `php_server` or a project snippet without FrankenPHP's modules. Rules written from Go code would turn the HTTP rules into string building. Fragments keep Caddy's own text, and Go supplies the values.

The detached child claims the lease once. A parent that claims, releases and reruns itself leaves a window for another start.

Registered commands return control to the runtime, so the runtime itself reports readiness. An `init()` that rewrites `os.Args` never returns, and readiness then needs a poll that can time out while the server keeps serving.
