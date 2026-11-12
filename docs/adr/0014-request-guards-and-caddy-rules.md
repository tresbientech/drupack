# Request guards match .htaccess, and a project adds Caddy rules

## Context

Drupal's hardening lives in Apache files that Caddy never reads. They are the root `.htaccess` core scaffolds, `vendor/.htaccess`, and the files `FileSecurity` writes into public and private storage. A site author's own rules from `web/.htaccess` need a home too.

## Decision

### Guards

- `guards.caddy` reproduces every rule of the `.htaccess` family. Each matcher carries a comment naming its source line.
- Its refusals sit in `refusals.caddy`, which `guards.caddy` renders first and the static port renders alone. `static.caddy` holds the snippets for a static file.
- A rule on a script ignores case and ends with `[. ]*(/|$)`. FrankenPHP executes a script followed by a PATH_INFO suffix, and Win32 strips trailing dots and spaces from a path component.
- A rule on a name that is not a script takes no PATH_INFO tail. A Drupal path such as `/clients/acme.inc/edit` reaches Drupal. The CVS names keep Apache's letter case.
- Exemptions stay case-sensitive and take no tail: `core/*.php`, core's two test front controllers, and `/.well-known/` with no dotted segment below it.
- A denied directory answers 403 when it exists, in the filesystem's letter case. A directory request whose `index.php` sits below the root answers 403.
### Raw paths

- A raw path that opens with `//` or `/\`, or holds a control character or a backslash anywhere, answers 403 on both ports. Windows reads `\` as a separator after the guards matched.
### Headers and functional rules

- Every response carries `X-Content-Type-Options: nosniff`, set after the handler. The `Proxy` request header is removed.
- PHP under public storage answers 404. `settings.php` and `services*` under any suffix answer 403.
- `/install.php` and `/rebuild.php` redirect 301 into `core/` with their query. `/core/install.php` gets `rewrite=ok`.
- A directory with `index.html` or `index.htm` and no `index.php` serves that file after a 301 to its slash form.
- A `css_*.css` or `js_*.js` aggregate beside a `.br` or `.gz` copy serves the copy.
### Caching and tests

- Every file that is not a script caches for a year. Release-named files under `core/`, `modules/`, `themes/` and `libraries/` add `immutable`. A missing file or a directory reaches Drupal with Drupal's header alone.
- A conformance test hashes core's scaffold copy of `.htaccess`, `vendor/.htaccess` and `FileSecurity.php`. A core release that moves a rule fails QA with the file named.

### Deviations from Apache

- The core exemption is anchored at the docroot.
- A directory named like a refused file, such as `foo.module/`, answers 403.
- A denied directory that does not exist reaches Drupal.
- No `Expires` header. `Cache-Control` carries the same meaning.
- The installer redirect runs after the refusals, so `/modules/x/install.php` answers 403 at once.

### The project's own rules

- A project keeps one snippet, `drupack.caddy`, at its root beside `composer.json`. The engine reads the folder's file. A Packaged site reads the copy in its payload.
- The Caddyfile imports it inside the route after `guards.caddy` and before the Mercure fragment and `php_server`. A refused request is answered before the snippet runs.
- The engine's aggregate file server runs after the snippet, so a snippet's header reaches an aggregate.
- The file is read at start. A change, a creation or a removal takes a restart.
- The server word adapts the configuration before `run`. On an adapter error it prints Caddy's message, then a line naming each project file, and exits 1.
- The guards refuse `/drupack.caddy` by name.

## Reason

Caddy applies none of Apache's protections by default. An unguarded Drupack site would serve settings, vendor code and backup files that Apache hides. Generating `guards.caddy` from core's `.htaccess` fails: RE2 has no lookahead, Apache has semantics Caddy lacks, and the redirect and aggregate blocks end up hand-written. A hash test gives the drift signal.

The project snippet runs after the guards so a project cannot loosen a refusal by accident. A rule on a guarded path is dead text, and the docs say so. Drupack checks no directive in the file, because only the project's author writes it. A `--watch` reload would not help: the import target is fixed at start, so a file created later waits for a restart anyway.
