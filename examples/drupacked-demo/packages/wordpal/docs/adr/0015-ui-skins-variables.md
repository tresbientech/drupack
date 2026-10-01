# ui_skins variables for theme presets

Status: Accepted

Each `theme.json` preset becomes one ui_skins CSS variable. `CssVariables::yaml()` reads the same DTCG tokens file `ThemeGenerator` already writes for the design token API, one entry per group and slug. Each variable's id is `wp--preset--<group>--<slug>`, so ui_skins prints it as `--wp--preset--<group>--<slug>`, the name WordPress itself prints. Its category is "WordPress presets", its label the token's own description, and its default the token's own value. `wordpal_display_builder_wordpal_theme_files_alter()` adds the resulting `<theme>.ui_skins.css_variables.yml` to the generated theme, beside the tokens file it reads.

Display Builder's Design Tokens panel overrides one of these variables on the selected component, as an inline `style`. Display Builder ships the panel disabled, and the Site recipe enables it. A site-wide override is a ui_skins theme setting, which ui_skins prints in `page_top`.

Amended 2026-09-29: this paragraph first said the panel edits the theme setting. Display Builder 1.0.0-beta8's panel edits the selected component only. The owner accepted the panel per component, with the site-wide override in ui_skins theme settings. The design tokens file itself stays, as [ADR 0010](0010-inspector-panels-and-preset-tokens.md) already decided: Drupal 11.5 will load it as a core design token, once released.

## Preset storage

A WordPal component's preset-typed prop offers a select of the theme's presets on both targets. The shared `InspectorPanelBuilder::presetSelect()` builds it.

Each target marks the prop's value field with `data-wordpal-preset` and attaches `wordpal/inspector_presets`. That script copies a chosen preset into the marked field, which the builder then stores. On Display Builder, a prop whose source is a token or an entity field has no value field, so its select stays unpaired.

## Considered Options

- Compute each CSS variable's name at request time from the tokens file. Rejected: ui_skins discovers its variable definitions from one static YAML file per theme, so they must exist on disk beside it.
- Wait for Drupal 11.5's design token API to supply ui_skins its own variables. Rejected, as ADR 0010 already found: nothing ships until 11.5.0 is released, and core still has no color type.

## Consequences

- A theme's presets appear in Display Builder's Design Tokens panel under one category, each labelled with its own `theme.json` description.
- The generated theme ships two files from one source, the design tokens file and the ui_skins variables file. Both derive from the same DTCG tokens.
- Both targets share the preset select and the script that stores its choice.
