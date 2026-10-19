# PRD: A site chooses its drupack.site subdomain

## Problem Statement

A Packaged site's Site host comes from its `name`, so the Drupacked Demo
answers `drupacked-demo.drupack.site`. The owner wants `demo.drupack.site`.
Renaming the demo changes its executable, its release assets, the updater's
match and its data directory.

A Project folder served by the engine takes its folder name. Its author has no
way to choose another label.

## Solution

A site's `composer.json` may set `extra.drupack.subdomain`. Pack and the engine
both read it, as they read `extra.drupack.mercure`. The value replaces the
label, so `"subdomain": "demo"` gives `demo.drupack.site`. Without it, the
label comes from `name` or the folder name, as today. The demo sets it.

## User Stories

1. As the Drupack owner, I want the demo on `demo.drupack.site`, so that the URL people read is short.
2. As a site author, I want to pick my site's subdomain, so that its URL matches the brand and not the executable name.
3. As a site author, I want the subdomain beside `extra.drupack.mercure`, so that every Drupack setting I share between pack and engine sits in one place.
4. As a site author, I want pack to refuse an invalid subdomain, so that I find out before a release ships.
5. As a developer on the engine, I want `drupack start DIR` to use the folder's subdomain, so that the folder answers the same host as the packed site.
6. As a developer on the engine, I want an invalid subdomain to stop the start with the key named, so that I know what to fix.
7. As a site author, I want `drupack.yml` to refuse a `subdomain` key, so that I am told where the key lives.
8. As a reader of the demo, I want the executable, the data directory and updates to stay as they are, so that the change costs me nothing.
9. As a site author without the key, I want today's label, so that nothing changes for me.

## Implementation Decisions

- D1. `composer.json` `extra.drupack.subdomain`, a string, sets the Site host label of a Packaged site and of a Project folder. Without it the label is today's: `name` for a Packaged site, the folder name for the engine. Evidence: `extra.drupack.mercure` is read by pack in `composerSettings()`, `launcher/internal/siteconfig/siteconfig.go:333-366`, and by the engine in `mercureKey()`, `engine/serve.php:249-261`.
- D2. A subdomain is one DNS label: lowercase letters, digits and dashes, 1 to 63 characters, no dash at either end. Pack refuses another value in `composerSettings()` with `composer.json: extra.drupack.subdomain: ...`. The engine refuses it at start, as `mercureKey()` refuses a non-boolean. Both read a file the site author writes.
- D3. `siteconfig.Site` gains `Subdomain`, written to `site.json` as `subdomain` and omitted when empty. `Read` sets it; `Parse` leaves it empty. `drupack.yml` refuses a `subdomain` key and names `composer.json extra.drupack.subdomain`, as it refuses `mercure`. Evidence: the moved-key check, `siteconfig.go:166-174`; Site's JSON tags are also the `drupack.yml` keys, `siteconfig.go:69`.
- D4. `tls-source` keeps its interface. `launch.php` passes `--name` with the subdomain when `site.json` holds one, otherwise the executable name. `serve.php` passes `--folder` with the subdomain when `composer.json` holds one, otherwise the folder name. `siteLabel()` returns a valid label unchanged, and the fallbacks stay `localhost` and the bind address. Evidence: `application/launch.php:788`, `engine/serve.php` `tlsChoice` call, `runtime/tls/tls.go:230-244,299-302`.
- D5. The Drupacked Demo's `composer.json` sets `"subdomain": "demo"` on demo main, and `build/demo-pin.sh` moves to the demo release that carries it. The demo's CI calls `build.yml@1.0.0-beta7`, whose `composerSettings()` decodes `composer.json` without strict keys, so that CI accepts the key. This replaces the earlier plan of a demo branch merged after beta8.
- D6. The conformance cases that build the expected Site host read `subdomain` from the harness's `site.json` when present, otherwise `name`. Evidence: `tests/conformance/tls_cases.py:142,152,158,165`.
- D7. Docs: `docs/cli.md` Site hosts section and `docs/build-your-site.md` beside `extra.drupack.mercure` name the key. `README.md` gives the demo URL as `demo.drupack.site`. ADR 0038 records the key and amends ADR 0037. Evidence: `docs/cli.md:108,121`, `docs/build-your-site.md:194`, `README.md:108`.

### Considered options

- Rename the demo to `demo`. It lost because `name` also names the executable, the release assets, the updater's match and the data directory.
- A `subdomain` key in `drupack.yml` for the Packaged site, and `composer.json` for the engine. It lost because a site that packs and runs on the engine would set it twice, and `mercure` already moved from `drupack.yml` to `composer.json` for that reason.
- A new `--subdomain` word on `tls-source`. It lost because `--name` and `--folder` already carry a label through `siteLabel()`, which leaves a valid one unchanged.

## Testing Decisions

A good test drives pack or the start from outside and checks the label a reader sees.

- Go, `launcher/internal/siteconfig`: `Read` sets `Subdomain` from `composer.json`; an invalid value and a `drupack.yml` `subdomain` key are refused with the key named; `site.json` omits an empty value. Prior art: the `mercure` cases in `siteconfig_test.go`.
- PHP, `application/tests/launch_test.php`: the fake runtime receives `--name demo` when `site.json` holds `subdomain`. Prior art: the tls-source argument test.
- Conformance, `tests/conformance/engine_cases.py`: a folder whose `composer.json` sets `subdomain` serves `http://<subdomain>.drupack.site:<port>` with `DRUPACK_DNS_ANSWER=127.0.0.1`, and an invalid value stops the start. Prior art: `test_a_folder_is_served_on_its_drupack_site_name`.

## Out of Scope

- Two sites on one machine that choose the same subdomain.
- A subdomain for a bind other than `127.0.0.1`.
- Moving other `drupack.yml` keys to `composer.json`.

## Further Notes

- The demo commit and the pin move need the owner's approval: both are pushes to a shared branch.
- The demo checkout's `mercure-display-builder` branch is unrelated work and stays untouched.
