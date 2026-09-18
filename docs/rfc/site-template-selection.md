# Site template selection

Proposed on 2026-09-18. Not implemented.

## Question

Every first start installs Byte, the only Site template Drupack carries. Drupal CMS [lists 16 site templates](https://git.drupalcode.org/api/v4/projects/204857/repository/files/site-templates.yml/raw?ref=HEAD), and 15 of them are free. How does a site owner choose one on first start, without the executable carrying all 15?

Project terms are defined in [CONTEXT.md](../../CONTEXT.md).

## Template set

The executable carries five templates. Mercury Demo is the default.

| Key | Name in `recipe.yml` | Version | Packages it adds to the lock | Archive size |
|---|---|---|---|---|
| `mercury_demo` | Mercury Demo | 1.0.0 | `mercury` 1.0.5 | 13.2 + 2.4 MiB |
| `byte` | Byte | 1.0.3 | none, bundled today | 8.8 MiB |
| `everbright` | Everbright | 1.0.0 | `everbright_theme` 1.0.0 | 4.9 + 4.6 MiB |
| `archimedes` | Archimedes | 1.0.0 | `eureka` 1.0.0, `emulsify_tools` 2.2.2 | 1.8 + 1.5 MiB |
| `local` | Local Council | 1.0.3 | `paragraphs` 8.x-1.23, `entity_reference_revisions` 8.x-1.14, `consistent` 1.1.0 | 1.8 + 0.8 MiB |

The sizes are git.drupalcode.org archives of each tag, measured on 2026-09-18. The added package versions are their latest tags on that date. Composer resolves the final versions.

## Templates left out

- Meridian Charter School has a `purchase` block: it costs $899.
- Nuxt Starter needs a separate Nuxt frontend.
- Convivial Gov 1.3.3 requires Canvas ^1.11.0, and the lock pins Canvas 1.10.1.
- Provus EDU and CareSphere require the AI modules and the OpenAI provider, an external service.
- Haven, Healthcare and Convene add 43 to 75 MiB of recipe each.
- Forma and Pulse add 17.3 and 13.9 MiB of recipe.
- Summit adds 29 modules to the lock.

The last three groups can join later, under [the inclusion rules](#adding-a-template).

## Executable growth

The five templates add 31.1 MiB of package archives. The Seed site moves from Byte to Mercury Demo, whose recipe content is 14 MiB against Byte's 9 MiB. The seed copies those images into its files, so it grows by about 5 MiB.

The estimate is about 36 MiB on the 103.6 MiB Linux executable built on 2026-09-15. Most of it is images, which compression does not shrink. `packaging/embed.sh` excludes `tests` directories and source maps from the archives, and keeps screenshots. The implementation records the measured growth.

## Installation paths

A SQLite first start of Mercury Demo copies the Seed site, as a Byte start does today. Its steps stay `seed`, `settings` and `administrator`. The Dockerfile builds the Seed site from `recipes/mercury_demo` instead of `recipes/byte`.

Every other first start runs `settings`, `install` and `modules`, the steps MySQL and PostgreSQL use today. The `install` step runs `drush site:install recipes/<key>`. SQLite gains this path for the four other templates.

The `modules` step runs for every template. It uninstalls `automatic_updates` and `package_manager` when the recipe enabled them, then enables Local MCP Tools.

Byte loses its seed. A SQLite first start of Byte then takes as long as a `site:install`, which nobody has measured yet.

## Choosing a template

- `--template KEY` and `DRUPACK_TEMPLATE` choose the template. Keys match the upstream catalog.
- A start without either installs Mercury Demo. Drupack shows no prompt.
- An unknown key stops the start, and the message lists the valid keys.
- `drupack --help` lists the keys and marks the default.

A start without options installs Byte today and Mercury Demo after this change. A script that needs Byte passes `--template byte`. The [Windows tray app](windows-tray-app.md) passes no options, so its first start installs Mercury Demo.

## Site name

Drush names a site `Drush Site-Install` unless `site:install` receives `--site-name`. Neither Byte 1.0.3 nor Mercury Demo 1.0.0 sets `system.site:name`. Every Drupack site carries that name today, whether it was seeded or installed.

- `--site-name NAME` and `DRUPACK_SITE_NAME` set the name. Drupack shows no prompt.
- The default is `Drupal` followed by the recipe name: `Drupal Mercury Demo`, `Drupal Local Council`.
- The `install` step passes the name to `site:install`.
- The `administrator` step sets `system.site:name` on a seeded site.
- A later start never renames a site. Site data from earlier releases keeps its name.

## Recorded template

A first start writes the chosen key to `<data>/site-template`, under the startup lock, before its first step. The record has two uses.

A resumed first start reads its key from the record. An interrupted `--template byte` start, resumed by a plain `drupack`, finishes Byte.

A later start compares `--template` or `DRUPACK_TEMPLATE` with the record. On a mismatch it prints `This site was installed from Byte. --template applies to a first start only.` and serves.

Drupack did not install an adopted database, so the `install` step removes the record when it adopts one. Site data from earlier releases has no record either. Drupack writes none for it and checks nothing. Every earlier release installed Byte, but an adopted site could hold anything, and the files do not tell the two apart.

## Declaring the set

- `composer.json` requires each template at an exact version, as it pins Byte 1.0.3 today.
- `extra.drupack.site-templates` in `composer.json` lists the offered keys, default first.
- The launcher, the help text, the Seed site build and the tests read that list.
- Names and descriptions come from each recipe's `recipe.yml`.
- `packaging/site-templates.php` keeps returning an empty list. The Drupal CMS installer's remote catalog stays unused.

The Go entrypoint prints `--help` on every platform. It reads the list from the application's `composer.json`, so the help text and the launcher share one source.

## Adding a template

A template joins the set when it meets every criterion:

- it is free: its upstream catalog entry has no `purchase` block
- it has a tagged stable release
- it installs with network access disabled
- it resolves against the lock without raising a pinned version, such as Drupal core or Canvas
- it needs no external service and no separate frontend
- it installs on SQLite, MySQL and PostgreSQL, checked once when it is added

The change that adds it reports the measured growth of the Linux amd64 executable. The set has no size cap.

## Retiring a template

[ADR 0003](../adr/0003-retired-site-templates-keep-their-code.md) records the rule. A retired template leaves the list and `--template`, and its recipe leaves `composer.json`. `composer.json` keeps requiring the modules and theme it enabled, so sites installed from it keep running.

## Tests

- On Linux amd64, each of the five templates installs on SQLite with network access disabled.
- `tests/server-database.sh` installs Mercury Demo on MySQL and PostgreSQL.
- Linux arm64, macOS and Windows keep their current suites, which install the default template.
- `tests/replacement.sh` serves Site data made by the previous release, a seeded Byte site, with the new executable.

## Acceptance checks

- A first start with no options on an empty directory copies the Seed site and serves Mercury Demo, named `Drupal Mercury Demo`.
- `--template byte`, `everbright`, `archimedes` and `local` each install on SQLite with network access disabled.
- MySQL and PostgreSQL install Mercury Demo.
- An unknown key stops the start and lists the five keys.
- `--site-name` sets the name on the seeded path and on the install path.
- An interrupted `--template byte` start, resumed without options, finishes a Byte site.
- A later start with a different `--template` prints the warning and serves.
- Site data from the previous release serves, keeps its name, and gains no record.
- Every template ends with Local MCP Tools enabled, and without `automatic_updates` and `package_manager`.
- `drupack --help` lists the five keys and marks Mercury Demo as the default.
- The README documents `--template` and `--site-name`, and names the five templates.
- The release notes state the executable growth.

## Related documents

- [RFC dependency-updates-and-compatibility](dependency-updates-and-compatibility.md) plans `Drupal CMS with Byte` in `drupack --version`. That string names the offered templates instead.
- [ADR 0002](../adr/0002-shared-application-extraction.md) extracts the application once per release. A larger application lengthens that extraction.

## Open questions

- How long `site:install` takes for each template on SQLite. That time sets Byte's new first start and the length of the release test job.
- Whether Everbright, Archimedes and Local resolve against the lock and install on SQLite. Their direct constraints allow Drupal 11.4.7 and Canvas 1.10.1. Their transitive constraints are unchecked.
- How much of the 36 MiB estimate remains after trimming.

## Follow-up

Drush sets the site email and the administrator email to `admin@example.com` on both paths. This RFC leaves them as they are.
