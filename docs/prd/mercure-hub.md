# PRD: A Mercure hub a Packaged site turns on

Date: 2026-10-17

## Problem Statement

A site author who wants live updates in the browser has no hub to publish to.
The engine already links the Mercure hub v0.24.2 through FrankenPHP, and
`drupack list-modules` names `http.handlers.mercure`. No Caddyfile mounts it, so
`/.well-known/mercure` reaches Drupal and answers 404. Running a separate hub
breaks the single-file promise of a Packaged site.

## Solution

A site author writes `mercure: true` in `drupack.yml`. Every start of that site
serves a hub at `/.well-known/mercure` on the site's own port. It also keeps
the hub's history in Site data and gives PHP the hub address and two secrets
through the environment. PHP code running in a request publishes with
FrankenPHP's `mercure_publish()`. Drush and cron publish over HTTP with a token
signed by the publisher secret. A browser subscribes with a token signed by the
subscriber secret. A site without the key serves as it does today.

## User Stories

1. As a site author, I want to turn the hub on with one key in `drupack.yml`, so that I configure no server.
2. As a site author, I want the hub off unless I ask for it, so that a site that does not use it opens no SSE endpoint.
3. As a site author, I want the build to refuse a `mercure` value that is not a boolean, so that a typo fails at build time.
4. As a site author, I want PHP in a request to publish with `mercure_publish()`, so that publishing needs no HTTP call and no token.
5. As a site author, I want Drush commands and cron to publish over HTTP, so that background work can send updates.
6. As a site author, I want the hub address in `DRUPACK_MERCURE_URL`, so that my settings file never hard-codes a port.
7. As a site author, I want both secrets in the environment, so that my settings file maps them to whatever module I use.
8. As a site author, I want subscribers to need a signed token, so that private updates stay private on a site bound beyond localhost.
9. As a site operator, I want each Site data to get its own secrets, so that two sites never share a key.
10. As a site operator, I want the secrets created by the first start that needs them, so that turning the key on in a later release works on existing Site data.
11. As a site operator, I want the secrets kept across starts, so that tokens signed before a restart stay valid.
12. As a site operator, I want the secret files readable by my account alone, as `hash_salt` is.
13. As a site operator, I want the hub history in Site data, so that a backup of Site data holds it.
14. As a site operator, I want the history capped, so that the file stays bounded.
15. As a browser user, I want a dropped connection to replay what I missed, so that a reconnect loses no update.
16. As a browser user, I want updates to reach me while a page stays open, so that the stream is not held back by compression.
17. As a Drush user, I want `drush` to export the hub variables for a site whose server already created them, so that a command publishes to the running hub.
18. As a Drush user, I want `drush` to keep creating nothing in Site data, so that it still runs while the server holds the Serving lease.
19. As an engine user, I want the Engine executable unchanged, so that serving a Project folder behaves as before.
20. As a maintainer, I want one Caddyfile for sites with and without the hub, so that a guard or route change lands once.
21. As a maintainer, I want a conformance case that publishes and subscribes on every platform the variant fixture runs on.
22. As a maintainer, I want the hub tested without another full build in QA.

## Implementation Decisions

- D1. `drupack.yml` gains `mercure`, a boolean, false when absent. The strict YAML parse refuses any other type. Evidence: `Parse` and `Site` in `launcher/internal/siteconfig/siteconfig.go` (`yaml.UnmarshalStrict`).
- D2. `site.json` always carries `mercure` as `true` or `false`. `launch.php` reads `siteSettings()['mercure']` with no fallback, since the build writes `site.json` beside the `launch.php` it ships. Evidence: `siteSettings()` in `application/launch.php:112`.
- D3. `SiteData` names two files: `mercure_publisher_secret` and `mercure_subscriber_secret`. A start of a site with the key creates each missing file with 32 random bytes in hex, under the start's `umask(0077)`. An existing file is kept. Evidence: the `hash_salt` creation in `runStep`, `application/launch.php:674`.
- D4. `drush` creates no secret. When the key is set and both files exist, it exports the same variables as a start. Otherwise it exports none. Evidence: "`drush` initializes nothing and takes no lease", `application/launch.php:735`.
- D5. With the key set, start and `drush` export three variables: `DRUPACK_MERCURE_URL`, `DRUPACK_MERCURE_PUBLISHER_SECRET` and `DRUPACK_MERCURE_SUBSCRIBER_SECRET`. The URL is `http://`, the probe host of the bind address, the port and `/.well-known/mercure`. The server passes its environment to the PHP it runs. Evidence: `probeHost` in `application/process.php:285`; the comment above `putenv('DRUPACK_ADMIN_PASSWORD')` in `launch.php`.
- D6. `application/Caddyfile` imports `"{$DRUPACK_RUNTIME_MERCURE_SNIPPET}"` inside its route block, after `import "guards.caddy"` and before the files route. A start exports `mercure.caddy` or `none.caddy`, two files beside the Caddyfile. Caddy expands `{$…}` before it parses, so the import path follows the start. Evidence: the header comment of `application/Caddyfile`; `exportServerEnvironment` in `application/process.php:74`.
- D7. `mercure.caddy` mounts the hub with `publisher_jwt` and `subscriber_jwt` read from the two secret variables, HS256. Its transport is `bolt` at `{$DRUPACK_RUNTIME_DATA_DIR}/mercure.db` with `size 1000`. Anonymous subscribers, `ui`, `demo`, `subscriptions` and `cors_origins` stay off. Evidence: the directive switch in `dunglas/mercure` v0.24.2 `caddy/mercure.go:399-540` and `caddy/bolt.go:93-119`.
- D8. The hub sits in the same route block as `php_server` and ahead of it. The hub registers under its enclosing subroute, and FrankenPHP's `assignMercureHub` finds it through the same ancestry for `mercure_publish()`. Evidence: `FindHub` and its registration, `dunglas/mercure` v0.24.2 `caddy/mercure.go:62,349`; `frankenphp` v1.12.7 `caddy/mercure.go:25`.
- D9. The Engine executable's Caddyfile and `engine/serve.php` do not change.
- D10. `build/node-fixture.sh` becomes `build/variant-fixture.sh`. It names the site `variant-demo`, adds `mercure: true` and writes a probe PHP file at the docroot that publishes one private update with `mercure_publish()`. `build/qa.sh` and `.github/workflows/release.yml` follow the rename, and every run on that fixture adds `-k MercureHub`. Evidence: `git grep -n node-fixture` (`build/qa.sh:39`, `release.yml:333,639,714,824`).
- D11. ADR 0034 records the opt-in hub. `docs/build-your-site.md` documents the key. `docs/cli.md` documents the three variables.

### Considered options

- Hub on for every site. It opens an SSE endpoint and holds connections on sites that publish nothing.
- Anonymous subscribers with no subscriber secret. Every update becomes readable by anyone who reaches the port.
- The `local` transport. A reconnect or a restart loses every update published in between, with no signal to the client.
- A second full Caddyfile with the hub. Every route and guard change then lands twice.
- The engine also serving a hub behind a `--mercure` option. It needs a second secret store in the folder cache entry, and no Project folder asks for it.
- Drupack writing the `drupal/mercure` module's hub parameters. The engine would track a contrib module's parameter shape, and the secrets would reach the compiled container in the database.
- A separate Mercure fixture. A third full `drupack-build` in every QA run and CI job.
- `mercure: true` in Drupacked Demo. It changes the demo repository and its pin for a feature the demo does not use.

## Testing Decisions

A test drives the packed executable from outside: it starts a site, sends HTTP requests and reads Site data. It never reads the generated Caddyfile.

- `launcher/internal/siteconfig`: `mercure` parses as true, false and absent; a string is refused; `site.json` carries the value. Prior art: the `node` cases in `siteconfig_test.go:276`.
- `tests/conformance/mercure_cases.py`, class `MercureHub`, skipped when `site.json` has `mercure` false, as `WRITABLE` is in `harness.py:640`:
  - a subscriber that accepts gzip and holds a token for the probe's topic receives the update the probe publishes, unbuffered
  - a subscriber reconnecting with `Last-Event-ID` receives the update it missed
  - a subscriber with no token, or a token signed by another key, gets 401
  - a publish over HTTP with a token signed by the publisher secret reaches a subscriber
  - the secret files exist with mode 0600 and survive a restart unchanged
  - `drush php:eval` sees the three variables once a start created the secrets
- A `MercureHub` case on Drupacked Demo, which lacks the key, checks that `/.well-known/mercure` answers 404 and that no secret file exists.
- Prior art for SSE reading and token signing: none in the suite. The case signs HS256 with Python's `hmac` and reads the stream with `http.client`.

## Out of Scope

- Any consumer module: AG-UI, `drupal/mercure` or others.
- A hub for the Engine executable.
- Anonymous subscribers, CORS origins, the subscriptions API and the hub UI.
- A history cap the site chooses.
- Secret rotation.

## Further Notes

- FrankenPHP sets `AllowNoPublish`, so the hub would start without a publisher key and disable HTTP publishing. D7 keeps the key because Drush and cron cannot call `mercure_publish()`: it is undefined in `php-cli`.
- The bolt file needs one process to open it. The Serving lease already gives a Site data directory one server.
- Windows coverage arrives with a tag run, as Windows jobs run only on tags.
