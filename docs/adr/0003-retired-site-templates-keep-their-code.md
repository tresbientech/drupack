# Retired site templates keep their modules and theme

Proposed on 2026-09-18. Not implemented.

## Context

[RFC site-template-selection](../rfc/site-template-selection.md) lets a first start install one of five Site templates. A Site template is a recipe. Installing it enables modules and a theme, and creates content from the recipe's files. The site never reads the recipe again.

A Drupal site stops working when an installed module or its default theme leaves the codebase. Every site installed from a template therefore depends on that template's modules and theme in every later release.

The recipe holds most of a template's weight. Mercury Demo 1.0.0's recipe unpacks to 16 MiB, of which 14 MiB is content. Its theme, `mercury` 1.0.5, unpacks to 2.7 MiB.

## Decision

- A template leaves the set by retirement.
- A retired template leaves `extra.drupack.site-templates` and the keys `--template` accepts.
- Its recipe package leaves `composer.json`.
- `composer.json` requires the modules and theme the recipe enabled, directly, so they stay after the recipe goes.
- `extra.drupack.retired-site-templates` names each retired template and the packages kept for it.
- Site data that records a retired template starts and serves.

## Considered options

- Never remove a template. Every recipe stays, with its content images, for as long as Drupack ships.
- Remove a template with its modules and theme. Sites installed from it stay on the last release that carried it, and a newer executable refuses them by their recorded template. Those sites stop receiving security releases.

## Consequences

- The executable keeps the modules and theme of every template it has offered. The content images leave with the recipe.
- `composer.json` requires packages that no offered template needs. `extra.drupack.retired-site-templates` says why each one is there.
- Security releases of those modules and themes still reach sites installed from a retired template.
- Adopted sites and Site data from before template selection carry no record. The rule cannot tell which of them use a retired template's code.
- Dropping a module a retired template enabled needs a migration that uninstalls it from existing sites. This ADR does not provide one.
