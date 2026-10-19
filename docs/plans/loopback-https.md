# Plan: Loopback HTTPS

**Spec:** docs/prd/loopback-https.md

## Architectural decisions

- **D1**: the launcher exports `DRUPACK_TLS_DIR`, `os.UserConfigDir()/drupack/tls`, keeping a caller's value.
- **D2**: the runtime word `tls-trust` creates the root when absent and installs or uninstalls it with smallstep/truststore.
- **D3**: the root is ECDSA P-256, 10 years, with a critical name constraint permitting `localhost`, `127.0.0.0/8` and `::1/128`.
- **D4**: the runtime word `tls-source` prints `mkcert`, `drupack` or `none` and the files Caddy needs.
- **D5**: mkcert wins when `mkcert -CAROOT` holds `rootCA.pem`; Drupack runs mkcert for a leaf and never opens its root key.
- **D6**: the Drupack root wins next when `root.pem` verifies against the system roots.
- **D7**: `trust` joins `launch.php` `COMMANDS` and the `engine/serve.php` word `match`.
- **D8**: only a loopback bind calls `tls-source`; `loopback()` moves to `application/process.php`.
- **D9**: both Caddyfiles take `DRUPACK_RUNTIME_SCHEME` and import `DRUPACK_RUNTIME_TLS_SNIPPET`; the global options set `skip_install_trust`.
- **D10**: one function builds a site URL from scheme, host and port.
- **D11**: the readiness probe trusts the root named by `DRUPACK_RUNTIME_TLS_ROOT`.
- **D12**: an HTTP loopback start prints the `trust` line.
- **D13**: `runtime/environment.txt` lists the six new variables.
- **D14**: ADR 0036 and the README, `docs/cli.md` and `docs/build-your-site.md` changes.
- **Snippets**: `tls-none.caddy`, `tls-files.caddy`, `tls-drupack.caddy`, in `application/` and linked from `engine/`.
- **Files**: `DRUPACK_TLS_DIR/root.pem`, `DRUPACK_TLS_DIR/root.key` (0600), `DRUPACK_TLS_DIR/mkcert/`.

---

## Phase 1: A Packaged site serves HTTPS from a trusted Drupack root

**User stories**: 5, 10, 15, 16
**Decisions**: D1, D3, D4, D6, D9, D10, D11, D13

### What to build

The tracer through every layer. The first step settles what Caddy needs: a site
address that serves `localhost`, `127.0.0.1` and `[::1]` from a pki CA rooted in
files, under the existing global options. `tls-source` creates nothing yet; the
case writes the root with a test helper that uses the D3 template. A start on
the default listener with a trusted root serves HTTPS, prints an `https://` URL
and login link, and Drush gets the same scheme. With no root the site serves
HTTP exactly as before.

### Acceptance criteria

- [ ] `tls_cases.py` Drupack-source case passes: with `SSL_CERT_FILE` holding the test root, `curl --cacert root.pem https://127.0.0.1:PORT/` answers, and the printed URL and `DRUSH_OPTIONS_URI` start with `https://`.
- [ ] `tls_cases.py` none case passes: no root, the site answers `http://` and the summary prints no `https://`.
- [ ] `go test ./...` in `runtime/watch` passes a case where the probe accepts a certificate from the named root and refuses one from another root.
- [ ] `launch_test.php` covers the URL function for both schemes and an IPv6 host.
- [ ] `test_environment.py` passes with the new variables in `runtime/environment.txt`.
- [ ] The existing `-k OfflineRun` and `-k NetworkListener` conformance runs stay green.

---

## Phase 2: `trust` installs and removes the root

**User stories**: 2, 3, 4, 6, 7
**Decisions**: D2, D7

### What to build

`./site trust` creates the root when absent, installs it through truststore
with NSS, and prints one line per store. `--uninstall` removes it. The root
created here is the one Phase 1's start verifies.

### Acceptance criteria

- [ ] A case runs `trust` as root in a `docker run --rm` container from the `drupack-job` image, never on the host. It asserts `root.pem`, `root.key` mode 0600, the printed store lines, and the root in `/etc/ssl/certs/ca-certificates.crt`.
- [ ] The same container then runs `trust --uninstall` and the root has left that bundle.
- [ ] `openssl x509 -in root.pem -noout -ext nameConstraints` prints the three permitted subtrees, marked critical.
- [ ] A second `trust` reuses the existing root: the `root.pem` digest is unchanged.

---

## Phase 3: mkcert comes first

**User stories**: 1, 15
**Decisions**: D5

### What to build

When `mkcert -CAROOT` holds `rootCA.pem`, a start runs mkcert once for the
loopback names, keeps the certificate in `DRUPACK_TLS_DIR/mkcert/`, and serves
it through `tls-files.caddy`. A certificate within 30 days of expiry is written
again.

### Acceptance criteria

- [ ] `tls_cases.py` mkcert case passes: with a stub `mkcert` on PATH, `curl --cacert <stub CAROOT>/rootCA.pem https://127.0.0.1:PORT/` answers.
- [ ] The same case with both a stub mkcert and a trusted Drupack root serves the mkcert certificate (its issuer matches the stub root).
- [ ] A case with a stub certificate expiring in 10 days asserts the stub ran again.
- [ ] The stub records its arguments; the case asserts it never received `-CAROOT` key paths or `-install`.

---

## Phase 4: The engine, the loopback rule and the hint

**User stories**: 8, 9, 11, 12, 13, 14
**Decisions**: D8, D12

### What to build

`drupack start` in a project follows the same sources, and `drupack trust`
works from any folder. `loopback()` lives in `process.php`, so both modes keep a
non-loopback listener on HTTP. An HTTP loopback start prints the `trust` line.
The hub URL carries the served scheme.

### Acceptance criteria

- [ ] `-k EngineExecutable` gains a Drupack-source case that answers HTTPS, and passes.
- [ ] `tls_cases.py` non-loopback case: `--listen 0.0.0.0:PORT` with a trusted root answers HTTP.
- [ ] The none case asserts the `trust` line in the start output, in both modes.
- [ ] `-k MercureHub` on an HTTPS start: a subscriber at the `https://` hub URL receives a publish.
- [ ] `serve_test.php` covers `loopback()` from `process.php`.

---

## Phase 5: ADR, docs and the browser check

**User stories**: 3, 5, 7
**Decisions**: D14

### What to build

ADR 0036 records the sources, their order, the constrained root and the HTTP
fallback. The README replaces "The site speaks plain HTTP" with the two sources
and `trust`. `docs/cli.md` lists `trust` and the variables. A Playwright script
outside the repo checks that Chromium and Firefox refuse a certificate for
`example.com` under the root, and the docs claim enforcement for those two only.

### Acceptance criteria

- [ ] `ls docs/adr/0036-*.md` finds the ADR, and its decisions name D3, D5, D6 and D12's behaviour.
- [ ] `grep -n "plain HTTP" README.md` finds only the non-loopback listener sentence.
- [ ] `grep -n "trust" docs/cli.md` finds the command and `DRUPACK_TLS_DIR`.
- [ ] The Playwright check prints a refusal for `example.com` in Chromium and Firefox, and the output is pasted in the ledger.
