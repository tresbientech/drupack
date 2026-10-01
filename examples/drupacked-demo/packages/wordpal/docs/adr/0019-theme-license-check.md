# Check the theme license before converting

A Conversion copies a Block theme's CSS, fonts and images into a generated Drupal theme, which ships to production beside the Site recipe. `wordpal:convert` checks the **Theme license** before it runs WordPress. The generated theme keeps the theme's **Legal files** and an **Attribution notice**.

The check covers the theme alone:

- A WordPress.org slug passes on provenance. The directory accepts GPL-compatible themes only.
- A zip or URL source passes when its `style.css` `License` or `License URI` maps to a license compatible with a GPL-2.0-or-later Drupal site.
- Any other zip or URL source stops the conversion. `--accept-license` overrides it for one run, and the command output records the override.

`ThemeSource` reads the two headers from the zip, and `wordpal:convert` checks them before Playground runs. A refused theme costs no WordPress run. `ThemeSource::resolve()` does not check, so Evaluation commands accept any zip.

## Considered options

- Report only. Rejected: a proprietary theme would reach a deployed site with one output line as the only trace.
- Refuse without an override. Rejected: it blocks commercial themes a builder holds a license for.
- Check every source's headers, WordPress.org included. Rejected: an unusual `License` phrasing on a directory theme would need the override.
- Check after the snapshot. Rejected: a refused theme would cost a full WordPress run.
- Check inside `ThemeSource::resolve()`. Rejected: Evaluation commands resolve through it and have no override.
- Check plugins too. Deferred: plugin files ship only for Frozen blocks.

## Consequences

- Plugin CSS, JS and assets copied for Frozen blocks are unchecked and unattributed.
- The allowlist is a phrasing table in code. A theme using an unlisted phrasing of an allowed license needs the override until the table gains it.
- Snapshots from before this change lack the license metadata and fail until refreshed.

Amended 2026-09-30: the owner ruled that plugins are credited and never refused, and `--accept-license` stays theme-only. Each plugin whose stylesheets or assets ship for Frozen blocks gets an entry in the **Attribution notice** and a line in the command output. The entry states the **Plugin license** and links the WordPress.org page of a slug plugin and its Plugin URI. A Notices line lists the plugin's root `readme.txt`, `LICENSE`, `LICENCE` and `COPYING` files, which the generated theme keeps. License files deeper in a plugin stay out. Snapshots from before this amendment lack the plugin headers and fail until refreshed.
