# A site serves its Tome export on a second port

## Problem Statement

A site owner wants one executable that creates a Drupal site, writes it out as
HTML, and runs only when they edit. Drupack creates the site and serves it, and
nothing exports it. Tome exports a site to HTML, but its Drush command spawns
child Drush processes from a command line it rebuilds out of `$_SERVER['argv']`.
Under Drupack's `php-cli` that line starts with `drush.php`, a file no shell can
run, so `drush tome:static` fails. Nothing serves an export beside the backend,
and nothing lets a form or an API on the exported site reach Drupal.

## Solution

A site turns the static listener on in `composer.json`. The executable then
serves two ports: the Drupal site on its port, and the Tome export on the next
one. `SITE export` runs Tome's own Drush command with the static port's URL, on
a Drush launch whose child processes start. A site names the path patterns the
static port hands to Drupal, for a webform or an API. A site that names none
runs no PHP on that port. The same key and words work on a Packaged site and on
a project folder the engine serves. Drupacked Demo ships with the listener on.

## Out of Scope

- An export that follows content changes: a cache-tag invalidation signal is in
  [issue 3636033](https://git.drupalcode.org/project/drupack/-/work_items/3636033).
- The Mercure hub on the static port, in
  [issue 3636034](https://git.drupalcode.org/project/drupack/-/work_items/3636034).
- An engine override of the export directory when a project moves
  `tome_static_directory`.
- Pushing the export to a host, or the `quant_tome` and `tome_aws` modules.
- tresbien.tech as a Packaged site, and `ext-tidy`.
- Authenticated traffic on the static port.
- A method rule sending every POST on the static port to Drupal.
- A timer or cron that runs the export.

## User Stories

1. As a site author with Tome, I want the export on a second port, so that I
   host it and edit on the backend.
2. As an engine user, I want the same key and words on my project folder, so
   that what I test holds once packed.
3. As the owner of a purely static site, I want no PHP on the static port, so
   that the backend's forms stay out of reach.
4. As an owner with a webform, I want to name the prefixes the static port hands
   to Drupal, so that a form submits.
5. As an editor, I want `SITE export` to write the site into the export with the
   static URL, so that a change goes live.
6. As a reader on Windows, I want `export` to run there, so that the export is
   not a Linux-only word.
7. As a reader of Drupacked Demo, I want the demo to show both ports, so that I
   see the workflow first.

## Implementation Decisions

### The toggle

- D1: `composer.json` `extra.drupack.static` is an object. Present, it turns
  the static listener on. Its optional `dynamic` is a list of Caddy `path`
  matcher patterns. Each opens with `/` and holds no whitespace, control
  character, double quote or brace: it reaches the Caddyfile through an
  environment value, where a brace opens a placeholder. Absent
  `dynamic` means the static port runs no PHP. `siteconfig.ReadComposer` reads
  it in both modes, `Composer` and `Site` gain a `Static` field, `Write` puts it
  in `site.json`, and `SiteContract` gains `static`. A wrong value is refused by
  field. Evidence: the `mercure` and `subdomain` switches in `ReadComposer`,
  `refusal()`, and the `mercure` field of `SiteContract`.
- D2: With `static` set, the reader refuses a `composer.lock` that names no
  `drupal/tome`, in both modes. Evidence: `lockedReleases()` already reads the
  lock in `Read`. The engine reads its settings through the `composer-settings`
  word, so an engine start, `drush` and `export` get the same refusal.

### The static listener

- D3: The static listener binds the site listener's address on its port plus
  one. `--static-listen IP:PORT` overrides it on both executables, validated as
  `--listen` is by `listenerParts()`. A Packaged site writes it into the
  Listener record beside `listen`; a start never reads it back; `drush` and
  `export` do. An engine start writes the static URL into the folder's cache
  entry as it writes the hub's. Evidence: `SiteData::recordListener()`,
  `MERCURE_URL_FILE` in `engine/serve.php`.
- D4: `staticPlan(string $bind, int $port, string $host, string $scheme): array`
  in a new `application/static.php`, linked into `engine/`, gives the static
  port and URL. `staticServerEnvironment(?array $static, ?array $plan, string
  $tls): array` gives Caddy its variables. `DRUPACK_RUNTIME_STATIC_BIND` and
  `DRUPACK_RUNTIME_STATIC_PORT` hold the static listener's address, and
  `DRUPACK_RUNTIME_STATIC_ROOT` comes from `siteEnvironment()` on a Packaged
  site and from the engine's start on a project, never from this function (D7).
  `DRUPACK_RUNTIME_STATIC_SNIPPET` names `static-site-<tls>.caddy` or
  `none.caddy`, the one variable a site without the key exports.
  `DRUPACK_RUNTIME_STATIC_DYNAMIC_SNIPPET` names `static-dynamic.caddy` or
  `none.caddy`. `DRUPACK_RUNTIME_STATIC_DYNAMIC` holds the patterns joined by
  spaces, exported only when the list has one. Evidence: `listenerPlan()`,
  `mercureServerEnvironment()`, `projectSnippetEnvironment()`, and
  `exportServerEnvironment()`, which refuses an empty value.

### The Caddy blocks

- D5: Both Caddyfiles import `static-site.caddy`, which defines the
  `(static_site)` snippet, and import `DRUPACK_RUNTIME_STATIC_SNIPPET` at the
  top level beside the TLS site import. The three `static-site-<tls>.caddy`
  files name the static port's addresses with the TLS lines of their
  `tls-<tls>-site.caddy` twin and import the snippet. Evidence: the three
  `tls-*-site.caddy` files and the `(site)` snippet of each Caddyfile.
- D6: The `(static_site)` snippet roots at `DRUPACK_RUNTIME_STATIC_ROOT` and
  runs one route. The dynamic snippet comes first, then a `try_files` of the
  path's `index.html` and then the path, with no redirect, then `file_server`.
  `static-dynamic.caddy` matches `path` on `DRUPACK_RUNTIME_STATIC_DYNAMIC`,
  roots at the Drupal docroot, imports `guards.caddy` and runs `php_server`. A
  miss answers `404/index.html` from the export with status 404 when the file
  exists. Evidence: Tome writes `404/index.html` (tresbien.tech's export holds
  `404/`); `engine/Caddyfile` imports `guards.caddy` ahead of `php_server`.

### The export directory

- D7: On a Packaged site the export lives in `static` in Site data, through a
  `SiteData::static()` method, created by `prepare()`. `siteEnvironment()`
  exports `DRUPACK_RUNTIME_STATIC_ROOT` to the server and Drush, and
  `application/settings.php` sets `tome_static_directory` from it when set. On
  the engine the root is `html` beside the docroot, Tome's default, and the
  engine creates nothing. Evidence: `SiteData::files()` and `prepare()`;
  `settings.php` reads `DRUPACK_RUNTIME_DATA_DIR` and `DRUPACK_RUNTIME_HOST`;
  `StaticGenerator::getStaticDirectory()` defaults to `../html`.

### The export word

- D8: `export` joins `COMMANDS` on a Packaged site and the engine's words. It
  runs the site's Drush with `tome:static --yes --uri URL` followed by every
  word after `export`, where URL is the recorded static URL. When those words
  carry `--uri`, `--uri=URL`, `-l URL` or `-lURL` before any `--`, Drupack
  adds none. On a Packaged site it takes the options `drush` takes and needs
  an installed site. On the engine, a project no start has recorded needs the
  words to name the URL. Both refuse a site or project without the key, as
  `--static-listen` does. Its exit code is Drush's. Evidence: the `drush`
  branch of `launch.php` and `drush()` in `engine/serve.php`.

### The Drush launch

- D9: `drush` and `export` on both executables launch Drupack's own Drush
  entry through `php-cli`, with `bin/` on PATH, which a start gives its server
  too: `application/drush` requires
  the site's `vendor/drush/drush/drush.php`, and `engine/drush` requires the
  script `DRUPACK_DRUSH_SCRIPT` names, which `serve.php` sets to the project's.
  The entry has no extension, so Tome's rebuilt command line, which reruns
  argv[0], runs through sh's shebang or through the `.cmd` twin on Windows,
  each reaching the `php` shim on PATH. `engine/bin` moves to
  `application/bin` and is linked back. The Go cron runner keeps `drush.php`.
  Evidence: `vendor/bin/drush` is Composer's shell proxy, which `php-cli`
  cannot run; PHP 8 strips a shebang on `include`; `drush.php` finds its
  autoloader from its own directory; `runtimePhpOnPath()`;
  `ExecutableFinderTrait::findExecutable()` and `ProcessTrait::runCommands()`
  in Tome 1.16.
- D10: The entry point answers the `php` word on a Packaged site as it does on
  the engine, both forms, so the shim works on both. The launcher passes the
  word through on both executables, and the entry point routes it before it
  changes into the application, so a script path resolves from the reader's
  directory. `docs/cli.md` lists it. Evidence: `arguments()` in
  `launcher/plan.go` passed `php` through for the engine alone;
  `entrypoint.go` routed `php` only when `application == ""`;
  `phpArguments()` holds the two forms.
- D11: The engine ships `application/drush.cmd`, `@php "%~dp0drush" %*`, which
  `layEngine()` lays over the site with the entry, since Composer writes `.bat`
  proxies on Windows alone. The build writes nothing of its own. Evidence:
  tresbien.tech's Linux-built `vendor/bin` has no `drush.bat`; `layEngine()`
  copies `application/` and `copyFile()` keeps each file's mode.

### The demo

- D12: Drupacked Demo requires `drupal/tome`, gains `recipes/static_site`
  that installs `tome_static` and core `search_node`, imports the module's
  optional config for its `/search/node` page, grants anonymous readers
  `search content`, applies it from `drupacked_demo_site`, and sets
  `extra.drupack.static` to `{"dynamic": ["/search*"]}`. Drupal CMS's search
  recipe defines a block alone, so core content search at `/search/node` is
  the demo's dynamic page. Its README names the
  two ports. The demo change lands first; this repository then names its commit
  in `build/demo-pin.sh`, with an empty `demo_release`. Evidence: `recipes/drupacked_demo_site/recipe.yml` applies
  `mercury_demo` and `agent_access`; `ReadComposer` ignores an unknown key, so
  the demo builds on the current release meanwhile.

### Tests and docs

- D13: `siteconfig_test.go` and `testdata/composer.json` cover `static` absent,
  `{}`, with `dynamic`, each refusal, and the lock without Tome.
  `launch_test.php` covers `staticPlan()` and `staticServerEnvironment()`.
  Evidence: both files test `mercure` the same way.
- D14: A new `tests/conformance/static_site_cases.py` class `StaticSite` skips
  when the site contract has no `static`. It starts a site with
  `--static-listen` and asserts a 404 before any export. It runs `export`, then
  asserts `static/index.html` in Site data and a 200 on `/` whose markup names
  the static URL. It asserts a Drupal answer on `/search/node?keys=drupal` and
  a 404 on `/user/login`. `EngineExecutable` gains the same on the copied
  project and `drupack export`. `build/qa.sh` adds the class to the demo run
  and to the no-node run, whose site drops the key. Evidence:
  `MercureHub` skips on the contract; `EngineExecutable.setUpClass` copies
  `site_application()`.
- D15: `docs/cli.md` gains the `export` word, `--static-listen`, a "Static
  site" section and the `php` word on a site. `docs/build-your-site.md` gains
  "A static site" after "A site that pushes live updates". ADR 0049's Windows
  line and its exclude sentence are corrected. Evidence: ADR 0049 names
  `drush.bat` and `tome_static_path_exclude`.

### Considered options

- A PHP script driving `tome_static.generator` in one process, the admin form's
  path. It lost because the owner keeps the export on Drush's command.
- Rewriting `$_SERVER['argv'][0]` for Tome's finder. It lost because Tome joins
  argv into one shell string, so a path with a space breaks.
- A method rule: every POST on the static port reaches Drupal. It lost because
  the backend's login, password and register forms would answer there.
- Rules for the static port in the project's `drupack.caddy`. It lost because
  the engine would then serve a second block it cannot see.
- An export on a timer in the watch runner. It lost because it boots Drupal on a
  period with nothing to do.
- Mapping `dynamic` to `tome_static_path_exclude`. It lost because Tome's
  `ExcludePathSubscriber` matches exact paths, and the patterns are prefixes.

## Testing Decisions

- A good test requests a port and reads the answer. Or it runs a word and reads
  the directory it wrote, or parses a file through the reader. None reads the
  Caddyfile or the environment a start exported.
- Tested: the composer reader and the lock check, and the plan and environment
  functions. Tested on a Packaged site and on the engine: the static port
  before and after an export, a dynamic path, and `--static-listen`.
- Windows is covered by the tag run alone.
- Prior art: `tests/conformance/mercure_cases.py` for a class the site contract
  turns on; `tests/conformance/engine_cases.py` for a copied project;
  `application/tests/launch_test.php` for environment functions;
  `launcher/internal/siteconfig/siteconfig_test.go` for the reader.

## Further Notes

Tome may still export a page under a dynamic pattern. The static port never
serves that file, so the copy is stale text on disk.

The `php` word on a Packaged site is a command-line addition. It exists so the
one `php` shim serves both executables.

An exported page's absolute URLs name the static listener. A copy published
elsewhere needs `export --uri` with its address.
