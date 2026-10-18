# PRD: The Mercure hub key moves to composer.json, and the engine serves it

Date: 2026-10-18

## Problem Statement

A project served by the Engine executable, which `composer require
drupal/drupack` installs, has no Mercure hub. The binary links Mercure 1.1.1,
but `engine/Caddyfile` never mounts it, by ADR 0034's last decision. A site
author who develops with the engine and packs the site later gets live
updates only in the packed file.

The key that turns the hub on lives in `drupack.yml`, which the engine never
reads. A plain Composer project has no `drupack.yml` at all.

## Solution

The key moves to the file both modes read: `composer.json`, under
`extra.drupack.mercure`. A Packaged site build takes it from there and writes
`site.json` as today. An engine start of a project with the key serves the
same hub, from the same `mercure.caddy`, on the project's own port.

The engine keeps the hub's secrets and history in the folder's cache entry,
so the project folder still gains no file. `drupack drush` sees the hub's
variables once a start created them. A `drupack.yml` that still sets
`mercure` stops its build with a message naming the new key.

## User Stories

1. As a site author, I want one key in `composer.json` to turn the hub on, so that the engine and the packed site agree.
2. As a site author, I want the hub off unless I set the key, so that a project that publishes nothing opens no SSE endpoint.
3. As a site author, I want a non-boolean `extra.drupack.mercure` refused, so that a typo fails loudly.
4. As a site author, I want a `drupack.yml` that still sets `mercure` refused with the new key's name, so that I learn the fix in one step.
5. As a site author, I want my settings file to read the same three variables in both modes, so that it needs no branch per mode.
6. As an engine user, I want `drupack start` to serve the hub at `/.well-known/mercure`, so that I run no separate hub.
7. As an engine user, I want `mercure_publish()` to work in a request, so that publishing needs no token.
8. As an engine user, I want `drupack drush` to export the hub's variables once a start created them, so that Drush and cron publish over HTTP.
9. As an engine user, I want `drupack drush` to name the address the last start listened on, so that a start with `--listen` still gets the right hub.
10. As an engine user, I want `drupack drush` to create nothing, so that it behaves as it does on a Packaged site.
11. As an engine user, I want my project folder to gain no file, so that nothing new needs a `.gitignore` line.
12. As an engine user, I want the secrets kept across starts, so that a restart keeps tokens valid.
13. As an engine user, I want the secret files readable by my account alone.
14. As an engine user, I want the history kept across starts, so that a reconnect after a restart replays what it missed.
15. As an engine user, I want a project without the key served exactly as before.
16. As a maintainer, I want one `mercure.caddy` for both modes, so that a hub change lands once.
17. As a maintainer, I want one function that creates the secrets and exports the variables, so that both modes create them the same way.
18. As a maintainer, I want conformance cases that run the engine hub end to end.

## Implementation Decisions

- D1. `composer.json` `extra.drupack.mercure` turns the hub on. It is a boolean, false when absent. The engine and `drupack-build` both read it. Evidence: `docroot()` reads `composer.json` `extra` in `engine/serve.php:45` and in `launcher/internal/siteconfig/siteconfig.go:323`.
- D2. `drupack.yml` drops `mercure`. `Parse` refuses the key with `drupack.yml: mercure moved to composer.json extra.drupack.mercure`. Evidence: `Site.Mercure` at `siteconfig.go:89`; `yaml.UnmarshalStrict` at `siteconfig.go:167`.
- D3. `siteconfig.Read` sets `Site.Mercure` from `composer.json`, and refuses a value that is not a boolean. `site.json` carries `mercure` as before, so `application/launch.php` reads it unchanged. Evidence: `Read` at `siteconfig.go:271`; `siteSettings()['mercure']` at `launch.php:872`.
- D4. `engine/Caddyfile` imports `"{$DRUPACK_RUNTIME_MERCURE_SNIPPET}"` in its route block, after `import "guards.caddy"` and before `php_server`. `engine/mercure.caddy` and `engine/none.caddy` link to the files in `application/`. Evidence: the `guards.caddy` and `process.php` links in `engine/`; `stageEngine` follows links, `launcher/internal/build/engine.go:55`.
- D5. `mercure.caddy` names its history file through `{$DRUPACK_RUNTIME_MERCURE_DB}`, in place of `{$DRUPACK_RUNTIME_DATA_DIR}/mercure.db`. `launch.php` and `engine/serve.php` write it. `runtime/environment.txt` gains the line, and `MERCURE_SNIPPET` gains the engine as writer and reader. Evidence: `application/mercure.caddy`; `runtime/environment.txt:14,23`.
- D6. The engine keeps `mercure_publisher_secret`, `mercure_subscriber_secret`, `mercure.db` and `mercure_url` in `folderEntry($project)`. A start creates each missing secret and keeps an existing one. Evidence: `folderEntry` at `engine/serve.php:124`.
- D7. `exportMercure` moves from `launch.php` to `application/mercure.php`, linked as `engine/mercure.php`. It takes the two secret paths, the hub URL and whether the caller is `drush`, and keeps today's creation and export rules. Evidence: `exportMercure` at `launch.php:217`.
- D8. An engine start of a project with the key exports `DRUPACK_MERCURE_URL` and both secrets, and writes the URL to `mercure_url`. The URL is `http://`, the probe host of the bind address, the port and `/.well-known/mercure`. Evidence: `DRUPACK_RUNTIME_URL` in `serve()`, `engine/serve.php:221`.
- D9. `drupack drush` in a project with the key exports the three variables when both secrets and `mercure_url` exist, and none otherwise. It creates no file. Evidence: `runScript` at `engine/serve.php:265`; `stop.json` holds no server address, `application/process.php:174`.
- D10. A non-boolean `extra.drupack.mercure` stops an engine `start` and `drush` with a message naming the key. Evidence: `composer.json` is the reader's file, `engine/serve.php:40`.
- D11. `build/variant-fixture.sh` sets the key in the fixture's `composer.json` in place of `drupack.yml`. Evidence: `build/variant-fixture.sh:18`.
- D12. ADR 0035 records the move and the engine hub, and amends ADR 0034. ADR 0034 gains an `Amended on 2026-10-18 by ADR 0035` line. `docs/build-your-site.md` and `docs/cli.md` name the new key and the engine hub. Evidence: the amendment form in `docs/adr/0030-the-launcher-routes-the-readers-words.md:3-4`; `docs/build-your-site.md:46`; `docs/cli.md:146`.

### Considered options

- A `--mercure` start flag. `drush` cannot see it unless the start records it, and every start repeats it.
- A hub on every engine start. Every project gets an endpoint and a token contract it never asked for.
- Turning the hub on when `composer.lock` holds a Mercure client. A dependency would open an endpoint without the author's say.
- Secrets inside the project, such as `.drupack/`. It breaks the rule that the folder gains no file and risks a committed secret.
- Secrets set by the reader in the environment. Every machine needs setup, unlike a Packaged site.
- Independent keys in `drupack.yml` and `composer.json`. A project wanting the hub in both modes sets both.
- A build that falls back to `composer.json` when `drupack.yml` leaves `mercure` unset. Two sources of truth for one setting.
- The strict parser's generic error for the old key. The reader has to find the new key in the docs.
- Accepting the old key with a warning. Two sources of truth until a later release.

## Testing Decisions

A test drives an executable from outside: it starts a project, sends HTTP requests and reads files. It never reads a generated Caddyfile.

- `launcher/internal/siteconfig`: `mercure` in `drupack.yml` is refused with the new key's name. `composer.json` `extra.drupack.mercure` reads as true, false and absent. A string is refused. Prior art: `TestParseReadsMercure` and `TestParseRefusesAMercureString`, `siteconfig_test.go:286,298`.
- `tests/conformance/engine_cases.py`, cases in `EngineExecutable` on its project fixture with the key written into its `composer.json`, which `-k EngineExecutable` already runs:
  - a subscriber receives what a request publishes with `mercure_publish()`
  - an HTTP publish signed by the publisher secret reaches a subscriber
  - `drupack drush` sees the three variables, with the URL of a start on a non-default port
  - the secrets sit in the folder's cache entry, readable by their owner alone, and the project folder gains no file
  - a project without the key answers 404 at `/.well-known/mercure`
  - a string value stops the start
- The variant fixture's `MercureHub` cases run unchanged, now on the key from `composer.json`.
- Prior art: `token()` and `Stream` in `mercure_cases.py`; the folder snapshot in `test_a_start_serves_the_folder_and_writes_only_its_files`, `engine_cases.py:138`.

## Out of Scope

- The Launcher turning the hub on for the Drupal CMS project it creates. A follow-up in that repository once beta7 ships.
- A hub for a project the engine serves without the key.
- Secret rotation, and a history cap the author chooses.

## Further Notes

- Moving or renaming a project folder gives it a new cache entry: new secrets and an empty history.
- `drupack clean` removes unpacked applications and Node releases, never folder entries, so it keeps the secrets.
- The bolt file needs one process to open it. The Serving lease already gives a folder one server.
- The engine fixture shares its project across cases, so the engine class restores the original `composer.json` when it ends.
