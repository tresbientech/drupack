# Plan: Request guards match Drupal's .htaccess

**Spec:** docs/prd/htaccess-parity.md

## Architectural decisions

- **D1**: `guards.caddy` reproduces the Drupal 11.4 `.htaccess` family, each matcher commented with its source line.
- **D2**: the engine links the application's snippets, checked by a binary-free test.
- **D3**: one matcher refuses PHP below the docroot root and `autoload.php` with 403, exemptions anchored at `^/core/`.
- **D4**: dotted segments answer 403, `/.well-known/` exempt at the root alone.
- **D5**: the protected-name list is `FilesMatch` plus sqlite and `package-lock.json`, case-insensitive, with the PATH_INFO tail.
- **D6**: `/vendor/`, private storage, config sync and site configuration answer 403 through regexps; `settings.php` moves to 403.
- **D7**: PHP under public storage keeps 404.
- **D8**: nosniff on every response after the handler, `Proxy` removed, `.svgz` typed and encoded.
- **D9**: `install.php` and `rebuild.php` redirect into `core/`; `core/install.php` gets `rewrite=ok`.
- **D10**: aggregates with a `.br` or `.gz` sibling serve pre-compressed, in the snippet and in the application's files route.
- **D11**: `index.html` then `index.htm` serve a directory with no `index.php`.
- **D12**: static files cache one year where a file exists; release-named assets add `immutable`; the application's files route sets its own header.
- **D13**: `zend.assertions = 0` in the server ini.
- **D14**: a test hashes the demo checkout's `.htaccess` sources (core's scaffold copy, `vendor/.htaccess`, `FileSecurity.php`) against pinned values.
- **D15**: a marker-file subtest per rule class in the engine cases; the site cases follow the new codes and headers.
- **D16**: a new ADR records the contract and its deviations, amending ADR 0022.
- **Status codes**: 403 wherever Apache answers 403; 404 for PHP under public storage alone.
- **Order**: headers, refusals, redirects and rewrites, aggregates and index, cache. The snippet is imported inside the `route` block, so written order is run order.
- **Local loop**: a phase is probed with the beta12 runtime's `php-server` word on the branch's Caddyfiles and a marker docroot. The built engine runs the suite once, through `bash build/qa.sh`, before merge.

---

## Phase 1: Refusals

**User stories**: 1, 2, 3, 4, 5, 13
**Decisions**: D1, D2, D3, D4, D5, D6, D7, D15

### What to build

The snippet carries the refusal matchers: subdirectory PHP with its anchored core exemptions, dotted segments with the `.well-known` root exemption, the protected-name list, the protected directories and site configuration. Every regexp is case-insensitive and ends with the PATH_INFO and trailing-dot tail. The dot-directory PHP rule and the `/vendor/*.php` rule go. Each matcher's comment names its `.htaccess` line. A binary-free test asserts the engine's links to the application's snippets. The engine cases gain the marker-file test with the refusal subtests; the existing `settings.php` assertions move to 403.

### Acceptance criteria

- [ ] `bash build/unit-tests.sh` passes, with the copy-identity test in it.
- [ ] A `php-server` probe on a marker docroot answers 403 for `/modules/probe/probe.php`, `/modules/probe/probe.php/x`, `/modules/probe/PROBE.PHP`, `/core/lib/Drupal.php`, `/autoload.php`, `/sites/default/settings.php/x`, `/sites/default/files/config_probe/sync/plain.txt`, `/package-lock.json`, `/.well-known/.hidden`, `/.well-known-evil/x.txt` and `/vendor/notes.txt`, and 404 for `/sites/default/files/probe.php`, while `/core/rebuild.php` and `/.well-known/probe.txt` are not refused.
- [ ] `tests/conformance/engine_cases.py` holds the marker-file test with one subtest per refusal class, and its `settings.php` assertion reads 403.
- [ ] `tests/conformance/site_cases.py` `test_protected_files` expects 403 for `settings.php`.

---

## Phase 2: Response headers and ini

**User stories**: 6, 7
**Decisions**: D8, D13, D15

### What to build

The snippet sets `X-Content-Type-Options: nosniff` after the handler on every response, removes the `Proxy` request header, and types `.svgz`. The server ini turns assertions off. The engine marker test gains subtests for the three headers; a unit test checks the ini value through `drupack php -r` with the ini loaded.

### Acceptance criteria

- [ ] The probe shows `x-content-type-options: nosniff` once on `/core/misc/drupal.js`, on a 403 and on `/user/login`.
- [ ] The probe shows `content-type: image/svg+xml` and `content-encoding: gzip` on a marker `.svgz`.
- [ ] A marker PHP echoing `HTTP_PROXY` under a `Proxy:` request header prints nothing for it.
- [ ] `drupack php -r 'echo ini_get("zend.assertions");'` with the branch ini under `PHPRC` prints 0.

---

## Phase 3: Installer redirects, aggregates and HTML index

**User stories**: 8, 9, 10
**Decisions**: D9, D10, D11, D15

### What to build

`/install.php` and `/rebuild.php` redirect 301 into `core/` with the query kept, unless the URI contains `core`. `/core/install.php` gets `rewrite=ok` appended. A `file_server` with `precompressed br gzip` serves `css_*` and `js_*` aggregates found in the docroot ahead of `php_server`, and the application's files route adds `precompressed br gzip` to its own `file_server`. A directory with `index.html` or `index.htm` and no `index.php` serves that file. The engine marker test gains the three subtests; the site cases gain an aggregates check in the files directory.

### Acceptance criteria

- [ ] The probe answers 301 with `Location: /core/install.php?a=1` for `/install.php?a=1`, and no redirect for `/modules/core/install.php`.
- [ ] A marker `css_probe.css` with a `.gz` sibling answers `content-encoding: gzip` and `vary: Accept-Encoding` under `Accept-Encoding: gzip`, and the plain file without it.
- [ ] A marker `probe-dir/index.html` answers 200 with its body for `/probe-dir/`.
- [ ] `tests/conformance/site_cases.py` holds an aggregates test against the files directory.

---

## Phase 4: One-year cache

**User stories**: 11
**Decisions**: D12, D15

### What to build

The two cache matchers and the revalidate header go. A `file` matcher excluding PHP sets `max-age=31536000`; release-named assets under `core/`, `modules/`, `themes/` and `libraries/` set `max-age=31536000,public,immutable`. The application's files route sets `max-age=31536000` on files it finds. The site cache test asserts the one-year values on an upload, a derivative and a core asset, and that a missing static file carries Drupal's header alone.

### Acceptance criteria

- [ ] The probe shows `cache-control: max-age=31536000,public,immutable` on `/core/misc/drupal.js`, `cache-control: max-age=31536000` on `/sites/default/files/probe.txt` and on `/robots.txt`, and one `cache-control` header on a missing `/sites/default/files/absent.png`.
- [ ] `tests/conformance/site_cases.py` `test_public_storage_cache_headers` asserts the one-year values.

---

## Phase 5: Drift test and ADR

**User stories**: 12
**Decisions**: D14, D16

### What to build

A conformance test over the demo project hashes `web/.htaccess`, `vendor/.htaccess` and the rule text of `FileSecurity.php`, and fails with a message naming the changed file and `guards.caddy`. A new ADR records the parity contract, the deviations and the reversal of ec2ce2e, and amends ADR 0022.

### Acceptance criteria

- [ ] The hash test passes against the demo checkout at `build/demo-pin.sh`, and fails with the named file when one byte of `web/.htaccess` changes.
- [ ] `docs/adr/0047-request-guards-match-htaccess.md` exists, names the five deviations, and ADR 0022 links it.
- [ ] `bash build/qa.sh` passes on the branch head.
