# One reader for a project's composer.json settings

## Problem Statement

Drupack reads three settings from a Drupal project's `composer.json`: the web
root, `extra.drupack.mercure` and `extra.drupack.subdomain`. Go reads them when
`drupack-build` packs a site. PHP reads them again on every engine start, in
`engine/serve.php`. The two readers disagree:

- PHP serves a `web-root` of `/var/www/html` from `<project>/var/www/html`, and
  takes `C:/web` as a folder name. Go refuses both.
- PHP accepts the subdomain `demo\n`, since PCRE's `$` matches before a final
  newline. Go refuses it.
- A `composer.json` that is not JSON makes PHP's `docroot()` fall back to `web`,
  while PHP's `drupackExtra()` refuses the same file.

`application/tests/composer-extra.json` keeps the `extra.drupack` cases in step,
and the drifts above passed it.

## Solution

Go holds the one reader. The engine's PHP asks the engine executable for a
folder's settings through an internal word, as `launch.php` asks it to lay an
application through `lay-app`. A packed site keeps reading `site.json`, which
the same reader filled at build time.

## User Stories

1. As a developer serving a folder with the engine, I want a bad `web-root`
   refused with the field's name, so that I fix `composer.json` before a start.
2. As a developer, I want the engine to accept exactly the `composer.json` that
   packing accepts, apart from the folder-only defaults, so that a project that
   serves also packs.
3. As a developer whose project keeps Drupal at its root, I want `web-root: ./`
   to keep working with the engine.
4. As a developer whose project has no `composer.json` key for the web root, I
   want the engine to keep serving `web`.
5. As a developer with a subdomain or Mercure key, I want the same refusal
   wording from `drupack` and from `drupack-build`.
6. As a maintainer, I want one function to own the rules, so that a rule change
   lands once.
7. As a maintainer debugging a wrong docroot, I want one command that prints
   what a start uses.

## Implementation Decisions

- D1. `siteconfig` exports `ReadComposer(project string, mode Mode)
  (Composer, error)`. `Composer` holds `Docroot`, `Mercure` and `Subdomain`.
  `Mode` is `Packed` or `Folder`. `Read` calls it with `Packed` and copies the
  three fields into `Site`, so `site.json` keeps its shape. Evidence:
  `launcher/internal/siteconfig/siteconfig.go` `composerSettings`.
- D2. Both modes share these rules:
  - `composer.json` must be a JSON object, and `extra.drupack` an object.
  - `web-root` must be a non-empty string. After `path.Clean` it matches
    `relativePathRe` and holds no `..` segment.
  - `mercure` must be a boolean.
  - `subdomain` must be one DNS label, matched by RE2.
- D3. The modes differ in three cases, each a field of `Mode`:

  | Case | Folder | Packed |
  |---|---|---|
  | no `composer.json` | defaults | refused |
  | no `web-root` key | `web` | refused |
  | `web-root` cleans to `.` | the project root | refused |

  Packed refuses `.` because `application/settings.php` loads
  `$app_root/../support`. Evidence: `engine/serve.php` `docroot`,
  `tests/conformance/engine_cases.py` `test_a_start_refuses_a_folder_without_drupal_core`.
- D4. Every refusal reads `<project>/composer.json: <field> must <rule>`, with
  the field's dotted path directly before `must`. The project path joins with
  `/`. The offending value follows the rule. Evidence: `engine_cases.py`
  asserts `extra.drupack.subdomain must be one DNS label`.
- D5. The engine's launcher handles `composer-settings DIR` before it prepares
  the cache, as it handles `lay-app`. A site's launcher does not. On success it
  prints one JSON object, `{"docroot", "mercure", "subdomain"}`, with `docroot`
  relative to DIR and `subdomain` null when unset, and exits 0. On a refusal it
  prints the message on stderr and exits 1. Evidence: `launcher/main.go` `run`,
  `DRUPACK_RUNTIME_LAUNCHER` set in `launcher/plan.go` `environment`.
- D6. `engine/serve.php` gets the settings from one helper that runs the word
  and throws its stderr on failure. `serve()` calls it once, `drush()` once for
  `mercure`. `docroot()` keeps only the Drupal core check, over the docroot the
  word names. `drupackExtra`, `mercureKey` and `folderLabelSource` go.
- D7. ADR 0044 records the one reader and the word. `docs/cli.md` "Other words"
  names `composer-settings` beside `lay-app`.

### Considered options

- Two readers kept in step by a shared table of cases, extended to `web-root`.
  It needs no extra process. It lost because a table catches only the inputs
  it lists, and three drifts passed the existing table.
- The Go launcher finds the project folder and passes the settings in the
  environment. It lost because Go would parse the start's arguments and the
  `drush` walk a second time, against ADRs 0014, 0030 and 0039. A `stop` or
  `drush` facing a bad `composer.json` would also need a deferred error.

## Testing Decisions

- A test feeds a module input and checks its output or its refusal text.
- `siteconfig_test.go` holds one table over both modes, from
  `launcher/internal/siteconfig/testdata/composer.json`. That file replaces
  `application/tests/composer-extra.json` and adds the `web-root`, missing-file
  and mode rows. Prior art: `TestReadAppliesTheSharedComposerExtraCases`.
- A launcher test runs the word's function on a folder and checks its JSON and
  its refusal. Prior art: `launcher/plan_test.go`.
- `application/tests/serve_test.php` drops its composer cases and the
  shared-case test. It keeps the Drupal core, Drush and core script checks.
- `tests/conformance/engine_cases.py` checks the word end to end: an absolute
  `web-root` stops a start with the field's name, and a folder without
  `composer.json` serves `web`.

## Out of Scope

- The `composer.lock` platform check in `serve.php`.
- How a packed site reads `site.json`.
- Any new `composer.json` key.

## Further Notes

Each engine start and each `drupack drush` runs one more short process. The word
skips the cache, as `lay-app --check` does.
