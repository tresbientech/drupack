# A site serves its Tome export on a second port

## Context

One executable can create a site, export it to HTML and run only to edit it. Tome's `tome_static` renders every page through Drupal's kernel and writes files into `tome_static_directory`. Some site owners want a purely static site. Others want a form or an API on it.

## Decision

### The setting

- `composer.json` `extra.drupack.static`, an object, turns the static listener on for a Packaged site and for a project the engine serves.
- `dynamic`, an optional list of path patterns, names the paths the listener hands to Drupal. Each pattern opens with `/` and holds no whitespace, control character, double quote or brace. Without `dynamic` the listener runs no PHP.
- `drupack.yml` refuses a `static` field. The build writes the value to `site.json`.
- A build and an engine start refuse the setting when `composer.lock` names no `drupal/tome` release.

### The static listener

- The listener binds the address of `--listen` on the site's port plus one: 7226 for a site on 7225, 3788 for the engine on 3787. `--static-listen IP:PORT` overrides it and must name another port than the site's.
- The Listener record keeps the address for `export`.
- It takes the site's TLS source on its own port.
- A `dynamic` path reaches `php_server` on the Drupal docroot, any method, inside the same Caddy process, after the request guards of [0014](0014-request-guards-and-caddy-rules.md). Every other path is a file from the export, else the export's 404 page.
- A file path meets `refusals.caddy`, and any `.php` path answers 403. The static port runs no PHP, so a script would answer its source. The site port's redirects and one-year cache stay off it.
### Where the export lives

- On a Packaged site the export lives in `static` in Site data, and the generated `settings.php` sets `tome_static_directory` to it.
- On the engine the launcher reads the project's `tome_static_directory` through the `static-directory` operation of `application/operate.php`, which loads `settings.php` alone. Without a setting it serves `html` beside the docroot, Tome's default.
- A start refuses an engine static directory that is or holds the docroot or the folder's cache entry, compared through symlinks. It also refuses a path that holds a brace.

### The export

- The word `export` runs `drush tome:static --yes --uri URL`. The URL is the static listener's scheme, host and port. A `--uri` after `export` overrides it. Every other word reaches Drush unchanged.
- A probe first checks that the project's Drupal has the `tome_static` module.
- `drush` and `export` launch Drush through Drupack's own entry with the runtime's `php` shim first on `PATH`. Tome's child processes then start from a command line that names that entry. On Windows they start through the `drush.cmd` twin.
- The server runs no export of its own.
- On a Packaged site, `ExportedUploadSubscriber` answers a public file to a request Tome makes in process. Tome copies the file into the export, so the export holds each upload its pages show as it stood at that export. The engine needs none, because a project's uploads sit under its docroot.

## Reason

`drush tome:static` is the export command, because the Tome form exports in one process and Drupack keeps Drush's parallel path. Drush's children find `php` only when the shim leads `PATH`.

A method rule that sends every POST to Drupal fails. Forms in exported pages would post to their own URL, and the backend's login, password and register forms would answer on the static port. Path patterns name what stays dynamic.

`dynamic` does not map to `tome_static_path_exclude`, which matches exact paths while the patterns are prefixes. A route that serves `/sites/default/files/*` from the Files directory would change an upload on the static port before the next export. An export sent to another host would hold none.
