# Inspector panels and preset tokens

Each generated prop carries `x-wordpal-panel`, derived from `block.json`: content-role attributes, block supports and other attributes. `wordpal_canvas` alters Canvas's component instance form into Content, Settings and Styles tabs with WordPress's panels, in the WordPress 7.1 inspector's order. Preset props become selects over the theme's presets, read from a generated `<theme>.tokens.yml` in the DTCG format of Drupal 11.5's design token API.

Flat props (ADR 0002) give a component 30 to 45 fields, sorted alphabetically before this decision, so a heading's text sat between border widths and the CSS class. Canvas renders `details` and `vertical_tabs` form elements but reads no prop grouping metadata. Core's design tokens ship in 11.5.0, unreleased while sites run 11.4, and have no color type.

## Considered Options

- A prop-to-panel table in `wordpal_canvas`. Rejected: it duplicates `block.json` and drifts on each WordPress release.
- Presets in `wordpal.theme_settings.<theme>`. Rejected: a WordPal format that core's token API replaces.
- Require Drupal 11.5 and read tokens through core's plugin manager. Rejected: nothing ships until 11.5.0 is released.

## Consequences

- Component definitions change on regeneration: prop order and one key per prop.
- Runtime props carry no panel, and the form puts them in Content. Canvas's PropShape finds no storable shape for an array prop with an extra key.
- On Drupal 11.5 core also loads the token file; WordPal keeps reading it for colors, which core does not type.
- Selects list the presets of the site's default theme, the theme Canvas renders.
