# Plan: drupack.site per-site names

**Spec:** docs/prd/drupack-site-names.md

## Architectural decisions

Durable decisions that apply across all phases:

- **D1**: `tls-source` is the one decision point. It takes `--bind`, `--name` or `--folder`, and an optional `--host`, and prints one JSON object with today's fields plus `host`, `alias` and `reason`.
- **D2**: Go derives the label: dots to dashes for `--name`; lowercase, `[^a-z0-9-]` to `-`, trimmed, cut to 63 for `--folder`; empty becomes `site`. `alias` is `<label>.drupack.site`.
- **D3**: Host order: `--host` as given; a bind other than `127.0.0.1` gives the fallback; otherwise a one-second lookup of `alias`, whose answer must hold `127.0.0.1`.
- **D4**: `reason` names the outcome; the start prints `Name: <alias> did not resolve to 127.0.0.1 (<reason>), so the URL uses <host>.`
- **D5**: `tls-source` decides HTTPS eligibility; `localhost`, `127.0.0.1`, `::1` and `alias` qualify on a loopback bind.
- **D6**: The root check runs for the chosen host.
- **D7**: A new root permits `localhost` and `drupack.site`; no migration of older roots.
- **D8**: mkcert's certificate adds `*.drupack.site`; one that does not verify for `alias` is written again.
- **D9**: Caddy serves `"https://{$DRUPACK_RUNTIME_ALIAS}:{$DRUPACK_RUNTIME_PORT}"` in the Drupack and mkcert snippets.
- **D10**: `settings.php` trusts `DRUPACK_RUNTIME_ALIAS`.
- **D11**: `launch.php` drops the `localhost` default, calls `tls-source` first, and takes the answer's host for the URL, `DRUSH_OPTIONS_URI`, the Listener record and the hint.
- **D12**: The engine passes `--folder` and uses the answer's host for its URL.
- **D13**: `DRUPACK_RUNTIME_ALIAS` and `DRUPACK_DNS_ANSWER` are declared; the harness sets `DRUPACK_DNS_ANSWER=none` by default.
- **D14**: ADR 0037, `docs/cli.md`, `README.md` and the engine trusted-host note.
- **JSON answer**: `{source, root, rootKey, authority, certificate, key, host, alias, reason}`, plus the eligibility D5 needs.

---

## Phase 1: A packed site starts on its name over HTTP

**User stories**: 1, 2, 3, 4, 5, 8, 9, 10, 11, 14, 15, 17, 18
**Decisions**: D1, D2, D3, D4, D10, D11, D13

### What to build

`tls-source` takes the new arguments, derives the label, looks the alias up
and prints the chosen host with its reason. A packed start passes its name and
bind, prints the reason line on fallback, and uses the answer's host for the
URL, `drush`, the Listener record and trusted hosts. The harness pins the
lookup to `none`, so existing cases keep `localhost`.

### Acceptance criteria

- [ ] `go test ./...` in `runtime/tls` passes table cases for each D3 branch and the D2 label edge names, with an injected lookup.
- [ ] `DRUPACK_DNS_ANSWER=127.0.0.1 <binary> tls-source --bind 127.0.0.1 --name drupacked-demo` prints `"host":"drupacked-demo.drupack.site"`; with `none` it prints `"host":"localhost"` and a non-empty `reason`.
- [ ] `php application/tests/launch_test.php` passes cases for the named URL, the fallback line, `--host localhost`, and the recorded host reused by `drush`.
- [ ] A conformance case with `DRUPACK_DNS_ANSWER=127.0.0.1` prints `URL:       http://<label>.drupack.site:<port>/` and answers a request sent with that Host header.
- [ ] `python3 tests/conformance/test_environment.py` passes with the two new variables declared.

---

## Phase 2: HTTPS on the name

**User stories**: 6, 7, 16
**Decisions**: D5, D6, D7, D8, D9

### What to build

The root's constraint and mkcert's certificate cover `drupack.site`. The root
check runs for the chosen host, and eligibility moves from PHP into the
answer. Caddy serves the alias address, so a trusted start prints an
`https://<label>.drupack.site` URL that verifies.

### Acceptance criteria

- [ ] `go test ./...` in `runtime/tls`: `TestTheRootCannotVouchForAnotherSite` accepts `x.drupack.site` and rejects `example.com`; the mkcert call carries `*.drupack.site`; a certificate without it is written again.
- [ ] `tests/conformance/tls_cases.py` passes with the test root's constraint holding `DNS:drupack.site`, and a named case serves `https://<label>.drupack.site:<port>/` whose certificate verifies against that root.
- [ ] `php application/tests/launch_test.php` passes with `TLS_HOSTS` and `tlsEligible()` gone from `process.php`.

---

## Phase 3: An engine folder starts on its name

**User stories**: 12
**Decisions**: D12

### What to build

`drupack start DIR` passes the folder's basename and prints the answer's host
in its URL. A non-loopback bind keeps its IP URL.

### Acceptance criteria

- [ ] An engine conformance case with `DRUPACK_DNS_ANSWER=127.0.0.1` on folder `My Shop` prints `my-shop.drupack.site`; with `none` it prints `127.0.0.1`.
- [ ] `php application/tests/serve_test.php` passes.

---

## Phase 4: Docs and decision record

**User stories**: 13
**Decisions**: D14

### What to build

ADR 0037 records the name, the lookup and the fallback. `docs/cli.md`,
`README.md` and the engine docs match the code.

### Acceptance criteria

- [ ] `grep -n "localhost by default" application/launch.php docs/cli.md` prints nothing.
- [ ] `grep -n 'drupack\\.site' docs/adr/0037-*.md docs/cli.md README.md` lists the name rule, the fallback line and the trusted-host pattern.
- [ ] `php application/tests/launch_test.php` passes its usage and docs agreement test.
- [ ] `bash build/qa.sh` passes on the branch head.
