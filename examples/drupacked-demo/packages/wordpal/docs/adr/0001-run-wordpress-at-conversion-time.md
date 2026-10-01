# Run WordPress at conversion time

A **Conversion** runs WordPress through WordPress Playground CLI. WordPress builds the **Global stylesheet**, executes pattern PHP and produces the **Reference render** for each **Template**. The output is written into the generated Drupal theme, which never needs WordPress or Node at runtime.

WordPress owns the `theme.json` to CSS logic: `WP_Theme_JSON`, its resolver, the style engine and block supports total 15,180 lines in WordPress 7.1. Running WordPress gives exact output and follows WordPress releases without a port. The same run provides the baseline for **Markup parity** checks.

## Considered Options

- Vendor `WP_Theme_JSON` and the style engine into the module with stubbed WordPress functions. Rejected: the stub surface is large and needs a manual re-sync on each WordPress release.
- Write a `theme.json` compiler in Drupal. Rejected: its output drifts from WordPress as themes use more features.

## Consequences

- Converting a theme needs Node on the developer machine.
- Per-block styling that editors change in Canvas renders in Drupal Twig, outside this decision.
