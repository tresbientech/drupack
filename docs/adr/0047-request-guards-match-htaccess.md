# Request guards match Drupal's .htaccess

Accepted on 2026-10-25. Amends [ADR 0022](0022-engine-executable.md). Amended
on 2026-10-26 by [ADR 0048](0048-a-project-adds-its-own-caddy-rules.md): a
project's `drupack.caddy` runs after these guards, and `/drupack.caddy` joins
the refused names.

## Context

Drupal's hardening lives in Apache files: the root `.htaccess` core scaffolds,
`vendor/.htaccess`, and the files `FileSecurity` writes into public and private
storage. Caddy reads none of them. `application/guards.caddy`, which both
Caddyfiles import ahead of `php_server`, carried a hand-written subset.

A probe of 1.0.0-beta11 against Drupal 11.4.11 found nine rules Apache applies
and Caddy did not. PHP in any subdirectory ran. A PATH_INFO tail reached
`settings.php`. Files under a config sync directory, `package-lock.json`,
dotfiles under `.well-known` and everything under `vendor/` were served. Static
files carried no `X-Content-Type-Options`. `zend.assertions` was on. Every guard
but one was case-sensitive. Four functional rules were absent too: the installer
redirects, `rewrite=ok`, pre-compressed aggregates and the one-year cache.

Three Caddy facts shaped the old gaps and the new rules. The `path` matcher is
case-insensitive, and a `*` in the middle of its pattern cannot cross a slash.
`path` and `path_regexp` match a cleaned path, with doubled slashes merged. A
`file` matcher matches a file, and a directory only through an entry that ends
in `/`.

## Decision

- `guards.caddy` reproduces every rule of the `.htaccess` family. Each matcher
  carries a comment naming its source line. `static.caddy` holds the snippets
  for a static file, which `guards.caddy` applies under the docroot and the
  application's files route applies in the files directory.
- A rule on a script starts with `(?i)` and ends with `[. ]*(/|$)`. FrankenPHP
  executes a script followed by a PATH_INFO suffix, and Win32 strips trailing
  dots and spaces from a path component.
- A rule on a name that is not a script takes no PATH_INFO tail. `try_files`
  splits on `.php` alone, so such a file followed by a tail is never served, and
  a Drupal path such as `/clients/acme.inc/edit` reaches Drupal. The CVS names
  keep Apache's letter case, so `/tag` and `/template` reach Drupal.
- Exemptions stay case-sensitive and take no tail: `core/*.php`, core's two
  test front controllers, and `/.well-known/` with no dotted segment below it.
- A directory request whose own `index.php` sits below the root answers 403.
  `php_server` would rewrite it to that script after the guards ran, where
  Apache's `DirectoryIndex` subrequest meets the same rule.
- A denied directory answers 403 when it exists, in the filesystem's letter
  case. A Drupal path such as `/vendor/acme` on a site whose `vendor/` sits
  outside the docroot reaches Drupal.
- A raw path that opens with `//` or `/\`, or holds a control character,
  answers 403. Apache merges the slashes. Caddy's redirects repeat the raw
  path, where a browser reads it as a host once it has dropped any tab.
- Every response carries `X-Content-Type-Options: nosniff`, set after the
  handler so Drupal's own header is replaced. The `Proxy` request header is
  removed. An existing `.svgz` answers `image/svg+xml` with
  `Content-Encoding: gzip`.
- A missing `/favicon.ico` answers 404 without Drupal.
- `/install.php` and `/rebuild.php` redirect 301 into `core/` with their query.
  `/core/install.php` gets `rewrite=ok` appended.
- A `css_*.css` or `js_*.js` aggregate beside a `.br` or `.gz` copy serves the
  copy.
- A directory with `index.html` or `index.htm` and no `index.php` serves that
  file after a 301 to its slash form.
- Every file that is not a script caches for a year, public storage included.
  Release-named files under `core/`, `modules/`, `themes/` and `libraries/` add
  `immutable`. The header is set where a file exists, so a missing file or a
  directory reaches Drupal with Drupal's header alone.
- `application/php.ini` sets `zend.assertions = 0`.
- `tests/conformance/engine_cases.py` requests a marker file per rule class in
  the shapes Apache refuses. A second test hashes core's scaffold copy of
  `.htaccess`, `vendor/.htaccess` and `FileSecurity.php` in the demo, so a core
  release that moves a rule fails QA with the file named.

## Deviations from Apache

- The core exemption is anchored at the docroot. Apache's `/core/[^/]*\.php$`
  matches anywhere in the URI.
- PHP under public storage answers 404. Apache serves such a file as text.
- A directory named like a refused file, such as `foo.module/`, answers 403.
  Apache matches the served file's name alone.
- A denied directory that does not exist reaches Drupal. Apache has no file to
  deny either.
- A raw path opening with `//` answers 403. Apache merges the slashes.
- No `Expires` date header. Caddy has no date arithmetic, and `Cache-Control`
  carries the same meaning.
- Release-named files add `immutable`, which Apache does not send.
- The installer redirect runs after the refusals, so `/modules/x/install.php`
  answers 403 at once where Apache redirects first and refuses the target.

## Considered options

- Generating `guards.caddy` from core's `.htaccess`. RE2 has no lookahead and
  Apache has semantics Caddy lacks, so the redirect and aggregate blocks end up
  hand-written anyway, and the owner's policy lives in a template beside the
  generated part. About 150 lines of Python buy a drift signal one hash test
  gives.
- `php_server { file_server off }` with an allowlist of scripts and an own
  `file_server`. It re-implements try_files precedence for static files and
  gains only the PHP class, which one matcher closes.
- Cache headers by extension alone. A missing file reaching Drupal's 404 page
  then carries a one-year header beside Drupal's own.

## Consequences

- Commit ec2ce2e is reversed. A replaced upload or a regenerated derivative
  keeps its URL and stays in browsers for a year, as on any Drupal site under
  Apache. The owner ruled this on 2026-10-25.
- `settings.php` answers 403, as on Apache, where it answered 404.
- A Drupal path whose last segment ends in a refused extension, such as
  `/clients/acme.inc` or `/blog/v2.0-postgresql`, answers 403, as on Apache.
- A core release that changes one of the three hashed files fails the hash
  test. The fix is to re-read `guards.caddy` against the new file and move the
  hash.
- A root-level script in upper case, such as `UPPER.PHP`, still executes under
  FrankenPHP, where Apache serves its source. No `.htaccess` rule names it.
