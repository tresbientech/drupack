# Plan: A site chooses its drupack.site subdomain

**Spec:** docs/prd/site-subdomain.md

## Architectural decisions

Durable decisions that apply across all phases:

- **D1**: `composer.json` `extra.drupack.subdomain` sets the Site host label of a Packaged site and a Project folder; without it the label is today's.
- **D2**: A subdomain is one DNS label; pack and the engine refuse another value, naming the key.
- **D3**: `site.json` carries `subdomain` when set; `drupack.yml` refuses a `subdomain` key.
- **D4**: `tls-source` keeps its interface; `launch.php` and `serve.php` pass the subdomain as `--name` or `--folder`.
- **D5**: The demo sets `"subdomain": "demo"` on its main, and the pin moves to the release that carries it.
- **D6**: Conformance builds the expected Site host from `site.json` `subdomain`, otherwise `name`.
- **D7**: `docs/cli.md`, `docs/build-your-site.md`, `README.md` and ADR 0038 name the key.

---

## Phase 1: A Packaged site takes its subdomain from composer.json

**User stories**: 1, 2, 3, 4, 7, 8, 9
**Decisions**: D1, D2, D3, D4, D6

### What to build

Pack reads and checks `extra.drupack.subdomain` and writes it to `site.json`.
`drupack.yml` refuses the key. A start passes the subdomain to `tls-source`,
and conformance expects the Site host it names.

### Acceptance criteria

- [ ] `go test ./internal/siteconfig/` in `launcher` passes cases for a set subdomain, an invalid one, a `drupack.yml` `subdomain` key and an empty value missing from `site.json`.
- [ ] `php application/tests/launch_test.php` passes a case where the fake runtime receives `--name demo` from a `site.json` holding `"subdomain": "demo"`.
- [ ] `grep -n "subdomain" tests/conformance/tls_cases.py` shows the expected host built from `subdomain` with `name` as fallback.

---

## Phase 2: An engine folder takes its subdomain from composer.json

**User stories**: 5, 6
**Decisions**: D1, D2, D4

### What to build

`drupack start DIR` reads and checks the folder's `extra.drupack.subdomain`
and passes it as the label. An invalid value stops the start.

### Acceptance criteria

- [ ] An engine conformance case with `DRUPACK_DNS_ANSWER=127.0.0.1` on a folder whose `composer.json` sets `"subdomain": "shop"` prints `http://shop.drupack.site:<port>`.
- [ ] An engine conformance case with `"subdomain": "-bad"` exits non-zero and prints `extra.drupack.subdomain`.
- [ ] `php application/tests/serve_test.php` passes.

---

## Phase 3: Docs and decision record

**User stories**: 2, 3
**Decisions**: D7

### What to build

ADR 0038 records the key and amends ADR 0037. The CLI and site-building docs
name the key; the README gives the demo URL as `demo.drupack.site`.

### Acceptance criteria

- [ ] `grep -n "extra.drupack.subdomain" docs/cli.md docs/build-your-site.md docs/adr/0038-*.md` lists each file.
- [ ] `grep -n "demo.drupack.site" README.md` prints the demo URL line.
- [ ] `grep -n "0038" docs/adr/0037-*.md` prints the amendment line.
- [ ] `bash build/qa.sh` passes on the branch head.

---

## Deviations

- D5: the demo commit and the pin move are pushes to shared branches. They run after this branch merges, each with the owner's approval.
