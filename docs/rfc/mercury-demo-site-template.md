# Mercury Demo replaces Byte

Proposed on 2026-09-18. Not implemented.

## Question

Drupack bundles one Site template, Byte, a SaaS product site. Which template should it bundle, and what should the site it produces be called?

Project terms are defined in [CONTEXT.md](../../CONTEXT.md).

## The template

Mercury Demo 1.0.0 replaces Byte 1.0.3 as the bundled Site template. `composer.json` requires `drupal/mercury_demo` at that exact version, as it pinned Byte. The Seed site builds from `recipes/mercury_demo`.

Drupack keeps carrying one template. Nothing selects between templates, and no option names one.

## What changes in the lock

Composer resolved the swap against the existing lock on 2026-09-18, as a removal of `drupal/byte` followed by a requirement of `drupal/mercury_demo`. The lock goes from 185 packages to 169.

Two packages arrive: `drupal/mercury_demo` 1.0.0 and its theme `drupal/mercury` 1.0.5.

Eighteen leave, because only Byte required them:

`drupal/byte`, `drupal/byte_theme`, `drupal/better_exposed_filters`, `drupal/checklistapi`, `drupal/field_group`, `drupal/metatag`, `drupal/nouislider_js`, `drupal/search_api`, `drupal/search_api_exclude`, `drupal/selective_better_exposed_filters`, `drupal/seo_checklist`, `drupal/simple_sitemap`, `drupal/token_or`, `drupal/ui_icons`, `drupal/views_infinite_scroll`, `drupal/webform`, `drupal/yoast_seo`, `goalgorilla/rtseo.js`.

Both templates share the other 50 or so contributed packages, including `gin`, `pathauto`, `eca`, `dashboard` and `project_browser`. Those stay.

Three packages move to a newer patch release, because Byte held them back: `drupal/cva` 1.0.0 to 1.0.1, `drupal/linkit` 7.0.16 to 7.0.17, and `drupal/trash` 3.0.33 to 3.1.0. Drupal core stays 11.4.7 and Canvas stays 1.10.1.

## Installation paths

Both paths keep their current steps. A SQLite first start copies the Seed site, now a Mercury Demo site. A MySQL or PostgreSQL first start runs `drush site:install recipes/mercury_demo`.

The `modules` step still uninstalls `automatic_updates` and `package_manager`, which Mercury Demo enables as Byte did, then enables Local MCP Tools.

## Site name

Drush names a site `Drush Site-Install` unless `site:install` receives `--site-name`. Mercury Demo 1.0.0 sets no `system.site:name`, and neither did Byte. Every Drupack site carries that name today, whether it was seeded or installed.

- `--site-name NAME` and `DRUPACK_SITE_NAME` set the name. Drupack shows no prompt.
- The default is `Drupal Mercury Demo`.
- The `install` step passes the name to `site:install`.
- The `administrator` step sets `system.site:name` on a seeded site, because the seed is installed at build time.
- A later start never renames a site. Site data from earlier releases keeps its name.

## Site data from an earlier release

An existing site is a Byte site, and it keeps running. Drupack serves whatever Site data it is given, and a template is a recipe that ran once at install time. The site never reads it again.

Nothing records which template a site came from, and nothing checks.

## Executable size

The executable grows, although the lock loses 16 packages. The published 0.1.1 Linux amd64 executable is 123,117,730 bytes. The build after this change measures 130,461,858 bytes, so the download grows by 7,344,128 bytes.

Removing more bytes than it adds still costs size, because of what the bytes are. The 18 departing packages measure 31,139,840 unpacked and 12,648,141 compressed, a ratio near 2.5, because they are mostly code. The 2 arriving packages measure 19,824,640 unpacked and 15,310,555 compressed, a ratio near 1.3, because they are mostly images. The payload is compressed, so the comparison that decides the download is the compressed one.

The Seed site changes with the template, and its copied content images change size with it.

## Tests

The existing suites cover this change, because one template installs through the paths they already drive.

- `tests/initialization.sh` checks that a first start names the site `Drupal Mercury Demo`, and that `--site-name` overrides it on both paths.
- `tests/server-database.sh` installs Mercury Demo on MySQL and PostgreSQL.
- `tests/offline.sh` installs it with network access disabled.
- `tests/replacement.sh` serves Site data across an executable replacement.

## Acceptance checks

- A first start with no options on an empty directory serves a Mercury Demo site.
- That site is named `Drupal Mercury Demo`.
- `--site-name` and `DRUPACK_SITE_NAME` set the name on the seeded path and on the install path.
- A later start with a different `--site-name` leaves the name unchanged.
- MySQL and PostgreSQL install Mercury Demo.
- The site ends with Local MCP Tools enabled, and without `automatic_updates` and `package_manager`.
- `drupack --help` documents `--site-name`.
- The README names Mercury Demo and documents `--site-name`.
- No reference to Byte remains in the code, the tests, the README or CONTEXT.md. The documents that record past releases keep theirs.

## Related documents

- [RFC dependency-updates-and-compatibility](dependency-updates-and-compatibility.md) plans `Drupal CMS with Byte` in `drupack --version`. That string names Mercury Demo instead.
- [ADR 0002](../adr/0002-shared-application-extraction.md) extracts the application once per release.

## Follow-up

Drush sets the site email and the administrator email to `admin@example.com` on both paths. This RFC leaves them as they are.
