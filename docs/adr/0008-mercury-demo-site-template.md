# Mercury Demo is the bundled site template

Accepted on 2026-09-18.

## Context

Byte was the bundled template. It held back three contributed packages and
required eighteen that only it used.

## Decision

- `drupal/mercury_demo` replaces `drupal/byte`. The lock drops from 185 packages
  to 169.
- A SQLite first start copies the seed site built at image build time. A MySQL
  or PostgreSQL first start runs `drush site:install recipes/mercury_demo`.
- Both paths uninstall `automatic_updates` and `package_manager`, then enable
  Local MCP Tools.
- A site is named `Drupal Mercury Demo` unless `--site-name` or
  `DRUPACK_SITE_NAME` says otherwise. A later start never renames a site.
