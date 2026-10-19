# PRD: A loopback site serves HTTPS from a root the machine trusts

Date: 2026-10-19

## Problem Statement

Every site serves plain HTTP. Both Caddyfiles set `auto_https off` and listen on
`http://:PORT`. Some local agents refuse an MCP address that is not HTTPS, and
Agent Access asks for one. Browsers drop `__Secure-` cookies on plain HTTP, which
forced the hub's cookie onto a weaker name in 1.0.0-beta8.

Trust is the part that differs per system. A certificate helps only when the
reader's browser already trusts its root, and each system keeps roots in its own
store.

## Solution

A start on a loopback address serves HTTPS when the machine trusts a local root,
and HTTP otherwise. It checks two sources, in order:

1. mkcert, when `mkcert` is on PATH and its root exists. Drupack runs mkcert to
   write a certificate for `localhost`, `127.0.0.1` and `::1`, and Caddy loads it.
2. The Drupack root, once `drupack trust` installed it. Caddy's pki app signs
   from it.

With neither, the site serves HTTP as today and prints one line naming
`drupack trust`. The URL keeps its host and port in every case.

`drupack trust` creates one root per user account, limited by name constraints
to loopback names. It installs that root in the system's trust store. A Packaged
site offers the same command and installs the same root.

## User Stories

1. As a DDEV user with mkcert, I want my sites on HTTPS with no extra command, so that the root I already trust covers Drupack too.
2. As a reader without mkcert, I want one command that makes my sites HTTPS, so that I never install another tool.
3. As a reader, I want that command to work on Linux, macOS and Windows, so that the steps are the same everywhere.
4. As a reader, I want one trust step to cover every Drupack site and the engine, so that a new site asks for nothing.
5. As a reader, I want a root that can only vouch for loopback names, so that a stolen key cannot impersonate other sites.
6. As a reader, I want `drupack trust --uninstall` to remove the root from every store it entered.
7. As a reader, I want the command to report each store it changed or skipped, so that I know whether Firefox got the root.
8. As a first-time demo reader, I want the site to open with no certificate warning, so that my first contact works.
9. As a reader on HTTP, I want the start to tell me how to get HTTPS.
10. As a reader, I want the printed URL, the login link and the opened browser to use the scheme the site serves.
11. As a reader, I want a start on a non-loopback listener to stay on HTTP, so that no address outside the certificate is served over TLS.
12. As a reader, I want Drush to build links with the scheme the site serves.
13. As a site author, I want the hub URL to carry the scheme the site serves, so that `mercure_publish()` keeps working.
14. As an agent user, I want an HTTPS MCP address on a loopback site.
15. As a maintainer, I want a start to never prompt for a password, so that a piped install script and a detached start never block.
16. As a maintainer, I want the readiness probe to verify the site's certificate against the root it was served from.
17. As a maintainer, I want conformance cases for each source, run without touching the job's own trust store.

## Implementation Decisions

- D1. The launcher exports `DRUPACK_TLS_DIR`, `os.UserConfigDir()` joined with `drupack/tls`, and keeps a value the caller set. Children see it although `launch.php` moves `XDG_CONFIG_HOME` into the site. Evidence: `DRUPACK_CA_FILE` handling, `launcher/internal/runtime/environment.go:41-48`; `launch.php:800`.
- D2. The runtime gains a `tls-trust` word. It creates `root.pem` and `root.key` (mode 0600) in `DRUPACK_TLS_DIR` when absent. Then it installs `root.pem` with smallstep/truststore, including NSS. `--uninstall` removes it. It prints one line per store. Evidence: the `browser-open` word, `runtime/entrypoint.go:331`; truststore v0.13.0 comes with Caddy's pki app.
- D3. The root is ECDSA P-256 and valid 10 years. Its critical name constraint permits the DNS name `localhost`, which covers `*.localhost`, and the IP ranges `127.0.0.0/8` and `::1/128`. Evidence: none in the repo; new code.
- D4. The runtime gains a `tls-source` word. It prints the source a start uses and the files Caddy needs: `mkcert`, `drupack` or `none`. Evidence: the `browser-open` word, `runtime/entrypoint.go:331`.
- D5. `tls-source` picks mkcert when `mkcert -CAROOT` holds `rootCA.pem`. It writes the certificate into `DRUPACK_TLS_DIR/mkcert/` with `mkcert -cert-file -key-file localhost 127.0.0.1 ::1`. It runs mkcert again when that certificate expires within 30 days. Drupack never opens mkcert's root key. Evidence: none in the repo; new code.
- D6. `tls-source` picks the Drupack root when `root.pem` exists and verifies against the system roots through `x509.Certificate.Verify` with no `Roots` pool. Evidence: none in the repo; new code.
- D7. `trust` joins `COMMANDS` in `launch.php` and the word `match` in `engine/serve.php`. Both hand the arguments to the runtime's `tls-trust`. Evidence: `launch.php:65`; `engine/serve.php:328`.
- D8. A start on a loopback bind calls `tls-source` and serves the result. A non-loopback bind skips it and serves HTTP. `loopback()` moves to `application/process.php`, which `engine/` links, so the engine applies the same rule. Evidence: `loopback`, `launch.php:269`; `engine/process.php` is a link.
- D9. Both Caddyfiles take the scheme from `DRUPACK_RUNTIME_SCHEME`. They import `DRUPACK_RUNTIME_TLS_SNIPPET`, which names one of `tls-none.caddy`, `tls-files.caddy` and `tls-drupack.caddy`, after the `mercure.caddy` pattern. `tls-drupack.caddy` uses a pki CA whose root comes from `DRUPACK_TLS_DIR`. The global options set `skip_install_trust`. Evidence: `application/Caddyfile:25`, `engine/Caddyfile:21`; `DRUPACK_RUNTIME_MERCURE_SNIPPET`, `application/mercure.php:16-22`.
- D10. One function builds a site's URL from the scheme, host and port. `servedListener`, `DRUSH_OPTIONS_URI`, the printed URL and login link, `DRUPACK_RUNTIME_URL` and the hub URL call it. Evidence: `'http://'` at `launch.php:295,791`, `engine/serve.php:195,231`, `application/mercure.php:11`.
- D11. The readiness probe trusts the root named by `DRUPACK_RUNTIME_TLS_ROOT`: mkcert's `rootCA.pem` or the Drupack `root.pem`. With HTTP, the variable is unset and the probe is unchanged. Evidence: `http.Client{Timeout: 30 * time.Second}`, `runtime/watch/watch.go:142`.
- D12. An HTTP start on a loopback bind prints `HTTPS: run "<binary> trust" once to serve this site over HTTPS.` Evidence: the start summary, `launch.php:897`.
- D13. `runtime/environment.txt` lists `DRUPACK_TLS_DIR`, `DRUPACK_RUNTIME_SCHEME`, `DRUPACK_RUNTIME_TLS_SNIPPET`, `DRUPACK_RUNTIME_TLS_CERT`, `DRUPACK_RUNTIME_TLS_KEY` and `DRUPACK_RUNTIME_TLS_ROOT`. Evidence: `tests/conformance/test_environment.py` checks that file against the sources.
- D14. ADR 0036 records the two sources, their order, the name-constrained root and the HTTP fallback. `README.md`, `docs/cli.md` and `docs/build-your-site.md` describe `trust`, the variables and the hub cookie's `Secure` flag under HTTPS. Evidence: `README.md:149` says the site speaks plain HTTP; `docs/cli.md:216`.

### Considered options

- Let's Encrypt through DNS-01, under a domain resolving to 127.0.0.1. It needs an issuing service, a zone and a rate-limit plan, and the URL leaves `localhost`.
- HTTPS with a warning when no root is trusted. Every first-time reader clicks through a warning, and agents that check certificates refuse.
- A trust prompt at first start. A piped install script and the CMS Launcher have no terminal for it.
- Caddy signing from mkcert's root. The server would hold a key that signs for any domain.
- Caddy's default root. It signs for any domain, and the constrained root costs one certificate template.
- Drupack signing 825-day certificate files from its root. One Caddy path, but our own CA code and long-lived site keys, where Caddy's pki app rotates 7-day intermediates and 12-hour certificates.
- `trust` and the source check in the Go launcher. The launcher's go.mod gains truststore, and the check runs before every word, `drush` included.
- One root per Site data. Every new site asks for a password again.

## Testing Decisions

A test drives an executable from outside. It starts a site, connects over TLS and reads output and files. It never reads a generated Caddyfile.

- Conformance cases, in `tests/conformance/tls_cases.py`, each with its own `DRUPACK_TLS_DIR`:
  - mkcert source: a stub `mkcert` on PATH serves a test CAROOT and writes a test certificate. The site answers HTTPS verified against that root.
  - Drupack source: `SSL_CERT_FILE` names a bundle holding the test `root.pem`, which Go's Linux verifier reads as the system roots. The site answers HTTPS verified against that root.
  - neither: the site answers HTTP and prints the `trust` line.
  - a non-loopback listener with a trusted root answers HTTP.
  - `DRUSH_OPTIONS_URI`, the login link and the hub URL carry the served scheme.
  - the root's name constraint refuses a certificate for `example.com` signed under it.
- `runtime/watch/watch_test.go`: the probe accepts a certificate from the named root and refuses one from another root.
- `application/tests/launch_test.php` and `serve_test.php`: the URL function per scheme, host and port; `loopback()` from `process.php`.
- Prior art: `mercure_cases.py` for snippet selection; `network_cases.py` for non-loopback listeners; `engine_cases.py` for engine starts.
- `tls-trust` writes to the system stores, so CI never runs it. Each system gets a manual check before release.
- Whether browsers enforce the root's name constraint is checked once, in Chromium and Firefox through Playwright. The docs claim enforcement only for the browsers checked.

## Out of Scope

- The CMS Launcher's trust button with GUI elevation. A later slice in that repository. A machine that ran `drupack trust` already gets HTTPS through the Launcher's engine.
- Let's Encrypt and any public domain.
- HTTPS on a non-loopback listener.
- Adding the local root to `DRUPACK_CA_FILE`'s bundle. PHP calling its own site over HTTPS fails verification until then.
- A `__Secure-` hub cookie name. The hub keeps `mercure_access_token` on both schemes.

## Further Notes

- Drupal names its session cookie per scheme, so a site moving from HTTP to HTTPS asks the reader to sign in once.
- On Linux, `tls-trust` needs `sudo` for the system store, and NSS needs `certutil`. Without `certutil`, Firefox on Linux keeps warning, and the command says so.
- On Windows, truststore writes the current user's ROOT store, which shows one confirmation dialog and needs no admin rights.
- Caddy's intermediate and site certificates live in the site's own Caddy storage, so each site rotates its own.
