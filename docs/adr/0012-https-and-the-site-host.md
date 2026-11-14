# A loopback site serves HTTPS on its Site host

## Context

Some local agents refuse an MCP address that is not HTTPS, and browsers drop `__Secure-` cookies over HTTP. Browsers scope cookies to a host and ignore the port, so two sites on `localhost` share one cookie jar. `*.localhost` resolves inside browsers only. The public zone `drupack.site` answers every label with `127.0.0.1`.

## Decision

### HTTPS

- A start on a loopback bind serves HTTPS when a root the machine trusts exists. The start must be addressed as `localhost`, `127.0.0.1`, `::1` or its `drupack.site` name. The first source that qualifies wins:
  1. mkcert. `mkcert` is on PATH, `mkcert -CAROOT` holds `rootCA.pem`, and the certificate mkcert writes verifies against the system's roots. Drupack runs mkcert for the three loopback names and `*.drupack.site`, and never opens mkcert's root key. A certificate within 30 days of expiry is written again.
  2. The Drupack root, when a certificate it signs for the chosen host verifies against the system's roots. Caddy's pki app signs from it. The authority's name carries the root's fingerprint, because Caddy reuses a stored intermediate without checking it against the root.
- Any other start serves HTTP. An eligible loopback start with no trusted root prints one line naming `trust`.
### The Drupack root

- `trust` creates the Drupack root on first use and installs it in the system store and the NSS databases. `trust --uninstall` removes and deletes it. A Packaged site and the engine run the same command.
- The Drupack root is ECDSA P-256, valid 10 years, with server authentication as its only extended key usage. Its critical name constraint permits `localhost`, `drupack.site` and their subdomains, `127.0.0.0/8` and `::1`.
- One root serves every site of the user account, in `DRUPACK_TLS_DIR`.
- The Listener record keeps the scheme and the host a start served. `drush` and a handover read them.
- PHP verifies HTTPS against `DRUPACK_CA_FILE`. An HTTPS start writes the CA bundle plus the served root into `ca-bundle.pem` in Site data and exports it to the server, cron and `drush`. An HTTP start deletes it.

### The Site host

- Each site has a name `<label>.drupack.site`. A Packaged site takes the label from `name` in `drupack.yml`, an engine start from the Project folder's name.
- `composer.json` `extra.drupack.subdomain` overrides the label for both. It is one DNS label: `a` to `z`, `0` to `9` and dashes, at most 63 characters, no dash at either end. A build and an engine start refuse another value. `drupack.yml` refuses a `subdomain` field.
- A derived label is lowercased, every character outside `[a-z0-9-]` becomes a dash, it is cut to 63 characters, and edge dashes drop. An empty label becomes `site`.
### The URL's host

- The URL names, in order:
  1. `--host`, when the reader gave it.
  2. The Site host, when the bind is `127.0.0.1` and the system resolver answers it with `127.0.0.1` within one second.
  3. Otherwise `localhost` for a Packaged site and the bind address for the engine. The start prints one line with the resolver's answer.
- Caddy serves the name beside `localhost`, `127.0.0.1` and `[::1]`, and `settings.php` trusts it.
- A Packaged site and the engine both take `--host`, one name.
- A loopback listener on plain HTTP answers every other name with status 421 and one fixed sentence that names `--host`. The answer holds nothing of the site and repeats nothing of the request.
- `--host` with any other name keeps HTTP.
- `DRUPACK_DNS_ANSWER` overrides the lookup with a fixed answer. The conformance harness sets it to `none`.

## Reason

A certificate helps only when the browser already trusts its root, and each system keeps roots in its own store. Taking an existing mkcert root or an explicit `trust` keeps every start free of privilege prompts.

The name constraint limits the root to loopback names, so a leaked root key vouches for nothing else. On-demand issuance for other hosts would let any page make Caddy sign certificates into Site data, so `--host` with another name serves HTTP.

A loopback listener answers only its own names against DNS rebinding. A web page the reader opens can point its own name at `127.0.0.1`. Its script then reaches the site under that name, as the same origin, and reads every reply. Caddy serves public files, the static export and the Mercure hub without Drupal, so Drupal's trusted host check covers none of them.

The rule covers plain HTTP on loopback alone. Over HTTPS the certificate names loopback hosts only, so the browser refuses a rebound name before any request. A bind beyond loopback serves the network, where a proxy in front sets the name.

An empty answer left a proxy that forwards an unknown name with a blank page and no error. A 421 with one fixed sentence says why, and holds nothing of the site.

The name resolves only against `127.0.0.1`, because the record answers that address alone. A start bound elsewhere keeps its own host. Sites on one machine keep separate cookies whenever the name resolves.
