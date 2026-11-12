# A site with writable directories gets its own Application root

## Context

A converter site, such as WordPal, runs a Drush command that writes a Drupal theme under the docroot's `themes/custom` and a recipe beside the docroot. Drupal finds a theme only under its root. [The cache](0003-the-cache.md) gives every site of a release one shared Application root that no site writes to.

## Decision

### The own root

- `drupack.yml` has a `writable` field: a list of directories, relative to the project root, that the site writes at runtime.
- Each entry is a clean relative path inside the project. Two entries must not overlap.
- A site with a non-empty list gets its own Application root, `app` in Site data. The launcher extracts it from the executable on the first start.
- `DRUPACK_RUNTIME_APP_DIR` and the Caddy docroot point at that copy. Drupal and Caddy read one root, and a written theme serves with no extra route.
- A site without the field keeps the shared root.
- `.release` in the copy holds the application checksum and is written last. A start whose checksum matches uses the copy as it stands.

### Upgrade

- A start by a newer release extracts the new application into a staging directory. It copies in every entry of a writable directory that the release does not ship.
- An entry the release ships takes the release's version. The staging directory then swaps with the old copy.
- `clean` leaves Site data alone.
- `deployment_identifier` hashes the application path and, for a site's own copy, its `.release` marker. The path stays the same across releases and the marker changes on upgrade.
- A converter site that needs Node carries it as [Bundled Node](0010-packaging-a-site.md), so the host needs none.

## Reason

Drupal resolves its root from the kernel file's own location, and `DRUPAL_ROOT` resolves symlinks. A link farm in Site data, with real directories for the writable paths, would send `index.php` and Drush's kernel back to the shared copy. Windows would also need junctions or a privilege.

A write into the shared root shows in every Site data of that release. `clean` deletes it, and the next release loses it. A boolean in place of a list cannot tell the site's writes from the release's files at upgrade.

The cost is one extraction per Site data: about 200 MB. Two Site data directories of such a site share no application files.
