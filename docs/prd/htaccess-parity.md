# PRD: Request guards match Drupal's .htaccess

Date: 2026-10-25

## Problem Statement

Drupack serves Drupal through Caddy and FrankenPHP. Drupal's hardening lives in
Apache files: the root `.htaccess`, `vendor/.htaccess`, and the files core writes
into public and private storage. Caddy reads none of them. Drupack carries a
hand-written `guards.caddy` instead.

A probe of the 1.0.0-beta11 engine against a Drupal 11.4.11 docroot found nine
places where Apache refuses and Caddy serves or executes. PHP in any
subdirectory runs. A PATH_INFO tail such as `settings.php/x` reaches the script.
Files under a config sync directory are served. `package-lock.json`, dotfiles
under `.well-known`, and everything under `vendor/` are served. Static files
carry no `X-Content-Type-Options`. `zend.assertions` is on. Every guard except
one is case-sensitive, so Windows and macOS open `services.YML`.

Four functional rules are also absent: the `install.php` and `rebuild.php`
redirects, `rewrite=ok` on the installer, pre-compressed aggregates, and the
one-year cache on static files.

## Solution

`guards.caddy` reproduces every rule of the `.htaccess` family, security and
functional alike. Each rule carries a comment naming its `.htaccess` line. A
test in the conformance suite requests a marker file per rule class and asserts
the status, headers and body. A second test hashes the demo checkout's
`.htaccess` sources, so a core release that moves a rule fails QA with a message
naming the file to re-read.

Public storage returns to Apache's one-year cache, which reverses commit
ec2ce2e. The owner ruled this on 2026-10-25.

## Out of Scope

- A root-level `UPPER.PHP`, which FrankenPHP executes and Apache serves as text.
  Both are wrong, the file is deployer-controlled, and no `.htaccess` rule
  names it.
- IIS `web.config` parity.
- WordPress or any non-Drupal docroot.
- The demo repository and its CI.
- An `Expires` date header. Caddy has no date arithmetic. `Cache-Control`
  carries the same meaning.

## User Stories

1. As a site owner, I want a PHP file inside a module, theme or `sites/` folder to answer 403, so that a script a package ships cannot be run from a browser.
2. As a site owner, I want `settings.php` and its siblings refused with any PATH_INFO tail, so that a trailing path cannot reach the script.
3. As a site owner, I want every file under a config sync directory, `vendor/` and private storage refused, so that configuration and dependencies stay off the web.
4. As a site owner, I want the names Apache refuses (`package-lock.json`, `composer.lock`, backups, dotfiles) refused on Caddy too, so that one hardening list covers both servers.
5. As a site owner on Windows or macOS, I want the guards to ignore letter case and trailing dots, so that `services.YML` or `settings.php.` cannot open the real file.
6. As a browser user, I want every response to carry `X-Content-Type-Options: nosniff`, so that a served file is never sniffed into a script.
7. As a site owner, I want `zend.assertions` off under the server, so that Drupal's assertions never throw on a production request.
8. As a visitor, I want `/install.php` and `/rebuild.php` to redirect into `core/`, so that the Drupal 7 addresses still work.
9. As a visitor, I want CSS and JS aggregates served pre-compressed when Drupal wrote a `.br` or `.gz` sibling, so that the server skips compressing them again.
10. As a visitor, I want a directory with `index.html` and no `index.php` to serve the HTML, so that a static folder inside the docroot works as on Apache.
11. As a site owner, I want static files cached for a year as Apache does, so that a Drupack site behaves like the same site on Apache.
12. As a Drupack maintainer, I want a failing test when core's `.htaccess` changes, so that a new rule reaches `guards.caddy` instead of going unnoticed.
13. As a Drupack maintainer, I want one subtest per rule with a marker file, so that a wrong guard is reproduced from a request alone.

## Implementation Decisions

- D1. `guards.caddy` reproduces the Drupal 11.4 `.htaccess` family: the root `.htaccess`, `vendor/.htaccess`, and the public and private storage rules `FileSecurity` writes. Each matcher carries a comment naming its source line. Evidence: `engine/guards.caddy`; Drupal core `assets/scaffold/files/htaccess`; `core/lib/Drupal/Component/FileSecurity/FileSecurity.php`.
- D2. The engine and the application serve one snippet: `engine/guards.caddy` is a symbolic link to `application/guards.caddy`, as every `engine/*.caddy` is. A unit test that needs no binary asserts the links. Invariant: the engine packs the `engine/` directory as its own application, so the link puts the snippet in its payload. Evidence: `launcher/cmd/pack/main.go` flag `-engine`; `ls -la engine/`.
- D3. One matcher refuses with 403 every PHP script below the docroot root and `autoload.php` at the root, with a PATH_INFO or Windows trailing-dot tail, in any letter case. Exempt: `core/*.php`, `core/modules/system/tests/https.php`, `core/modules/system/tests/http.php`, and `core/modules/system/tests/modules/legacy_front_controller_test/test_index.php`. The exemption is anchored at the docroot root, stricter than Apache's unanchored `/core/[^/]*\.php$`. This matcher replaces the dot-directory PHP rule and the `/vendor/*.php` rule. Evidence: `.htaccess` lines 143 to 149; probe of beta11 executed `/modules/probe/probe.php`, `/core/lib/Drupal.php`, `/autoload.php`.
- D4. Any path segment that starts with a dot answers 403. `/.well-known/` is exempt at the docroot root alone, and a dotted segment inside it is still refused. Evidence: `.htaccess` line 93; `FilesMatch` alternative `^\.(?!well-known).*`; probe served `/.well-known/.hidden` and `/.well-known-evil/x.txt`.
- D5. The protected-name matcher carries the `FilesMatch` list of line 6, plus `sqlite` and `sqlite3` beside `sql`, and `package-lock.json`. It answers 403. Every alternative ends with the PATH_INFO and trailing-dot tail and matches case-insensitively. Evidence: `.htaccess` line 6; probe served `/package-lock.json`.
- D6. Directories and site configuration answer 403 through regexps: `/vendor/` as a whole, `/private/`, `/sites/*/private/`, `/sites/*/files/config_*/`, and `/sites/*/settings*`, `/sites/*/services*`, `/sites/*/site-templates.php` with any tail. `settings.php` moves from 404 to Apache's 403. Invariant: a regexp spans a slash, the glob `path` matcher does not. Evidence: `vendor/.htaccess`; `FileSecurity::denyPublicAccess()`; caddy v2.11.7 `modules/caddyhttp/matchers.go` uses `path.Match` for a mid-pattern `*`; probe served `/sites/default/files/config_probe/sync/plain.txt` and executed `/sites/default/settings.php/x`.
- D7. PHP under public storage keeps its 404. Apache serves such a file as text through `SetHandler`, so neither answer is parity, and 404 reveals less. Evidence: `FileSecurity::htaccessPreventExecution()`; `tests/conformance/site_cases.py` `test_public_storage_blocks_php_execution`.
- D8. Every response carries `X-Content-Type-Options: nosniff`, set after the handler so Drupal's own header is replaced, not doubled. The `Proxy` request header is removed before PHP. `.svgz` answers `Content-Type: image/svg+xml` and `Content-Encoding: gzip`. Evidence: `.htaccess` lines 22, 23, 204 to 207; probe showed no nosniff on `/core/misc/drupal.js`.
- D9. A path ending in `/install.php` or `/rebuild.php` whose URI does not contain `core` redirects 301 into `core/` with the query kept. `/core/install.php` gets `rewrite=ok` appended to its query before PHP. Evidence: `.htaccess` lines 123 to 129.
- D10. A `css_*.css` or `js_*.js` file with a `.br` or `.gz` sibling is served pre-compressed with `Content-Encoding` and `Vary: Accept-Encoding`. In the snippet, a `file_server` with `precompressed br gzip` behind a `file` matcher serves the engine's in-docroot aggregates ahead of `php_server`. The application's files route adds `precompressed br gzip` to its `file_server @stored`. Invariant: `php_server` takes no `precompressed` option, and the snippet's `file` matcher sees the docroot alone. Evidence: `.htaccess` lines 153 to 192; FrankenPHP `docs/config.md` `php_server` subdirectives; `application/Caddyfile` route `/sites/default/files/*`; `AssetDumper::dump()` writes `.gz` and `.br`.
- D11. A directory request with no `index.php` but an `index.html` or `index.htm` is rewritten to that file ahead of `php_server`, in Apache's `DirectoryIndex` order. Evidence: `.htaccess` line 19; `php_server` tries `{path}/index.php` then `index.php`.
- D12. Static files cache for one year. In the snippet, a `file` matcher excluding `.php` sets `Cache-Control: max-age=31536000`, and release-named assets under `core/`, `modules/`, `themes/` and `libraries/` get `max-age=31536000,public,immutable`. The application's files route sets `max-age=31536000` on `@stored`. The `@versionedAssetPath` and `@publicStorage` matchers and the revalidate header go. Invariant: a header is set only where a file exists, so Drupal's own `Cache-Control` on a 404 page stays alone. Evidence: `.htaccess` line 45 and the `.php` `ExpiresActive Off`; commit ec2ce2e; owner ruling of 2026-10-25.
- D13. `application/php.ini` gains `zend.assertions = 0`. Evidence: `.htaccess` line 36; `drupack php -r` on beta11 printed `zend.assertions=1` with that ini loaded through `PHPRC`.
- D14. A conformance test over the demo project hashes core's scaffold copy of `.htaccess`, `vendor/.htaccess` and `FileSecurity.php`, and compares them with hashes pinned in the test. A mismatch fails with a message naming the changed file and `guards.caddy`. Invariant: QA checks out the demo at `build/demo-pin.sh` and builds it, so the files are on disk with no network. Evidence: `build/qa.sh` lines 21 to 31; `tests/conformance/engine_cases.py` runs against that project.
- D15. `engine_cases.py` gains one test with a subtest per rule class. Each subtest writes a marker file into the docroot, requests it in the shapes Apache refuses (plain, PATH_INFO tail, trailing dot, upper case, `%2e`), and asserts status, headers and that the marker text is absent from the body. `site_cases.py` updates `test_public_storage_cache_headers` to the one-year values, `test_protected_files` to 403 for `settings.php`, and adds an aggregates check in the files directory. `engine_cases.py` line 167 moves to 403. Evidence: `tests/conformance/engine_cases.py` `start()` and `status()`; the probe script that found the gaps.
- D16. A new ADR records the parity contract and its deviations: the anchored core exemption, the 404 for public-storage PHP, no `Expires` date header, `immutable` on release-named assets, and the reversal of ec2ce2e. It amends ADR 0022. `docs/cli.md` line 336 and ADR 0022 line 25 keep their wording. Evidence: `docs/adr/0022-engine-executable.md`; `docs/adr/0046-builders-run-static-php-cli-v3.md` as the format.

### Considered options

- A snippet generated from core's `.htaccess` by a Python translator with a hand-owned template and a QA check. RE2 has no lookahead and Apache has semantics Caddy lacks, so the redirect and aggregate blocks end up fingerprinted by hand, and the owner's policy still lives in a template. About 150 lines of Python and their tests buy a drift signal D14 gives with one hash test.
- `php_server { file_server off }` with an allowlist of PHP scripts and an own `file_server`. It re-implements try_files precedence for static files and gains only the PHP class, which D3 closes with one matcher.
- Cache headers matched on extension alone, so the application's files route needs no header of its own. A missing static file then reaches Drupal's 404 page with a one-year header beside Drupal's own.

## Testing Decisions

A test sends an HTTP request to a running engine or site and asserts the status,
the headers and the body. It never reads `guards.caddy` or a Caddy internal.

Modules tested:

- the guards, through `engine_cases.py` marker probes (D3 to D12)
- the application's files route, through `site_cases.py` (D10, D12)
- the ini, through `drupack php -r` under `PHPRC` (D13)
- the two snippet copies and the `.htaccess` hashes, through binary-free unit
  tests (D2, D14)

Prior art: `test_public_storage_blocks_php_execution` writes a marker PHP file
and requests it in refused shapes. `test_public_storage_cache_headers` asserts
`Cache-Control` per class. `test_environment.py` checks a declaration against
sources with no binary.

## Further Notes

Apache's `RewriteRule` and `FilesMatch` apply to the request path and the
basename. Caddy's `path_regexp` runs on the decoded path, so one regexp covers
both shapes, and `(?i)` plus a `[. ]*(/|$)` tail cover the Windows and PATH_INFO
cases Apache leaves open.

FrankenPHP already drops the `Proxy` header. D8 keeps the explicit removal so
the parity reads from the snippet alone.
