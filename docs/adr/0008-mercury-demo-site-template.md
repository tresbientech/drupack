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

## Amendment, 2026-10-01

The demo is Drupacked Demo. Its Site template is `recipes/drupacked_demo_site`,
which applies `mercury_demo` and `agent_access`, then installs `wordpal_canvas`.
A MySQL or PostgreSQL first start installs that recipe. A site is named
`Drupacked Demo` unless `--site-name` or `DRUPACK_SITE_NAME` says otherwise.
No first start enables Local MCP Tools, and the demo carries no `mcp_tools`.
