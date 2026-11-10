# composer.json holds what an engine start reads

Accepted on 2026-11-10.

## Context

A site's Drupack settings sit in two files. `drupack.yml` holds twelve keys, such as `name`, `platforms` and `node`. `composer.json` `extra.drupack` holds three: `mercure`, `subdomain` and `static`.

Three decisions placed those three keys one at a time: [ADR 0035](0035-composer-json-turns-on-the-mercure-hub.md), [ADR 0038](0038-composer-json-names-the-site-subdomain.md) and the static export. Each gave the same reasons. The engine never reads `drupack.yml`. A plain Composer project has none. A site should behave the same served by the engine and packed.

No document stated the rule those decisions share. Each new key reopened the question of its file.

## Decision

- `composer.json` `extra.drupack` holds what an engine start reads.
- `drupack.yml` holds what only packing needs. The build copies what a packaged start needs into `site.json`.
- A choice made for one start is an option of that start, in neither file. `--listen`, `--static-listen`, `--access-log` and `--error-log` are such choices.
- A new key takes its file from one question: does an engine start need it?
- `drupack.yml` refuses a key that belongs in `composer.json` and names its place there, as it does for the three keys.
- No key moves.

## Consequences

- `port`, `writable`, `settings` and `node` stay in `drupack.yml`. They shape a packaged site, and no engine start reads them: the engine has its own default port, a writable folder, the folder's own `settings.php` and no Node.
- A project turns on a hub, a subdomain or a static export in one place, and gets it in development and once packed.
- A setting that changes a running packaged site can sit in either file. The key's reader decides its file, whatever the moment the key takes effect.
- Composer hashes `extra` into the lock, so a change to an `extra.drupack` key needs `composer update --lock`. A change of build target does not.
- One file for everything was considered both ways. Moving all keys to `composer.json` breaks every site that packs today. Moving all keys to `drupack.yml` makes a plain Composer project carry a second file, and reverses ADRs 0035 and 0038.
