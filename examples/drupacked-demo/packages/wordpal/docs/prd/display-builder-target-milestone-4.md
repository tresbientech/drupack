# PRD: Display Builder target, milestone 4

## Problem Statement

WordPal writes a Conversion into Drupal Canvas only. The glossary names two Builder targets, Canvas and Display Builder, and milestones 1 and 2 deferred the Display Builder writer. A site builder who runs Display Builder cannot convert a Block theme.

Display Builder is at 1.0.0-beta7 (September 2026), needs Drupal core 11.4, and builds on UI Patterns 2. Its component form reads the same SDC definitions the Canvas target uses. Three things still tie the Components to Canvas:

- runtime props, such as a post title or featured image, are filled by Canvas entity bindings
- the WordPress inspector layout and the preset selects live in a Canvas form alter
- the conversion runner, ownership manifest, Site recipe writer and parity command live in `wordpal_canvas`


## Solution

A `wordpal_display_builder` module writes a Conversion as Display Builder configuration. `drush wordpal:convert --target=display_builder` writes the same generated theme, Demo content and Site recipe as the Canvas target, and one Display Builder output per WordPress structure:

| WordPress | Display Builder |
|---|---|
| Part set | page layout, the page frame |
| home and 404 Templates | a node each, with an entity view override holding the Template tree |
| single and page Templates | the mapped bundle's full entity view display |
| archive, tag, category and search Templates | a View whose display holds the Template tree |
| Query block | a View display with rows, pager and empty sources |
| Inserter pattern | a pattern preset |
| runtime Component | a pattern preset per mapped bundle, with its props bound to fields |

Editors see a WordPress inspector layout in the Display Builder form. The theme's presets are ui_skins CSS variables, so Display Builder's Design tokens panel edits them.

The builder-agnostic conversion code moves to `wordpal_convert` behind a writer interface with two implementers. Acceptance: Twenty Twenty-Four, Twenty Twenty-Five and YITH Wonder convert to Display Builder and `wordpal:parity` passes on every route.

## User Stories

### Choosing the target

1. As a site builder, I want to name the Builder target on the convert command, so that one command serves both builders.
2. As a site builder, I want conversion to fail before Playground when no target is named, so that no run writes nothing.
3. As a site builder, I want conversion to fail before Playground when the target's module is disabled, so that the failure names it.
4. As a site builder, I want the report to name the target and its output types, so that I know what was written.
5. As a site builder, I want a second target on one theme to need `--replace`, so that two builders never fight over one page.

### Editing components

6. As a content editor, I want every WordPal component in the Display Builder component library, so that I can place any WordPress block.
7. As a content editor, I want a component's slots to accept the same children as WordPress does, so that Columns takes only Column.
8. As a content editor, I want HTML content props edited in a rich text field, so that a paragraph keeps its inline formatting.
9. As a content editor, I want enum props as selects with WordPress labels, so that alignment and layout read as in WordPress.
10. As a content editor, I want a component's fields in Content, Settings and Styles tabs, so that the form matches the WordPress block inspector.
11. As a content editor, I want style fields in WordPress's panels and order, so that I find typography, color and spacing where WordPress puts them.
12. As a content editor, I want content fields first, so that I edit text without scrolling past styles.
13. As a content editor, I want a select of the theme's presets for color, font and spacing props, so that I pick a design value.
14. As a content editor, I want a custom option beside the presets, so that I can still enter a CSS value.
15. As a content editor, I want the Display Builder preview to render a component as the front end does, so that I see the result.

### Runtime props

16. As a content editor, I want a Post Title preset bound to the routed node, so that I place it with no setup.
17. As a content editor, I want one preset per runtime component and mapped bundle, so that a post card and a page header both work.
18. As a content editor, I want a runtime preset's bindings visible in the form, so that I can rebind a prop to another field.
19. As a site visitor, I want dates, excerpts, authors, terms, featured images and read more links from Drupal fields, so that cards match WordPress.
20. As a site builder, I want conversion to fail and name a runtime prop with no field binding, so that no empty output ships.

### Pages and templates

21. As a site visitor, I want the home Template as the front page, so that the site opens on the theme's landing page.
22. As a site visitor, I want a missing page to show the 404 Template, so that errors match the design.
23. As a site visitor, I want posts and pages in the single and page Templates, so that every node matches the design.
24. As a site visitor, I want term pages and search results in their Templates, so that listings match the design.
25. As a site builder, I want each Part set written once as a page layout, so that Templates sharing a header share one frame.
26. As a site builder, I want a page layout's conditions to match the routes its Templates serve, so that the right frame wraps each page.
27. As a site builder, I want the home Template's frame as the default frame, so that unconverted pages keep the header and footer.
28. As a content editor, I want to open any converted layout, display, View or override in the builder, so that I change it without code.
29. As a site builder, I want a full display Display Builder already builds treated as Unmanaged, so that conversion never overwrites my display.
30. As a site builder, I want the nodes' override field created when missing, so that no manual field setup precedes conversion.

### Query loops

31. As a site visitor, I want a Query block to list the mapped bundle's nodes with the Post Template's markup, so that listings match WordPress.
32. As a site visitor, I want Query pagination with WordPress's classes, so that the theme's pager styles apply.
33. As a site visitor, I want the Query's no results tree shown when the View is empty, so that empty listings match WordPress.
34. As a site builder, I want two identical Query blocks to share one View, so that Query sharing holds on both targets.
35. As a content editor, I want to open a Query's View in the Display Builder, so that I change the card without Views UI.
36. As a site builder, I want the search Template's Query to read the mapped search index, so that search results match the Canvas target.

### Patterns

37. As a content editor, I want each Inserter pattern as a pattern preset in the theme's group, so that I insert designed sections.
38. As a content editor, I want an inserted pattern to bring the theme's Sample content, so that I start from the designed section.
39. As a site builder, I want a Hidden pattern inlined where a Template uses it, so that it never shows in the preset library.
40. As a site builder, I want a pattern whose tree cannot be written skipped and listed, so that one bad pattern never stops the conversion.

### Frozen blocks and runtime blocks

41. As a site visitor, I want a Frozen block to show the markup WordPress rendered, so that plugin blocks keep their look.
42. As a content editor, I want a Frozen block shown as one unit in the Display Builder, so that I can move or remove it.
43. As a site visitor, I want the navigation, site title, logo, search, comments and listing blocks rendered from Drupal data, so that dynamic blocks work.
44. As a site visitor, I want the mobile navigation overlay and accordions to work, so that Interactivity scripts load on the Display Builder target too.

### Presets and tokens

45. As a site builder, I want the theme's presets declared as ui_skins CSS variables, so that Display Builder's Design tokens panel lists them.
46. As a content editor, I want a token edited in the Design tokens panel to change the selected component, so that I can tune one block's palette.
47. As a site builder, I want the design tokens file kept, so that Drupal 11.5 still loads the presets as core tokens.

### Activating

48. As a site builder, I want the home and 404 nodes as the site's front and 404 pages, so that the theme shows right away.
49. As a site builder, I want the listing Views on and the site's Views on those paths off, so that listings show the theme.
50. As a site builder, I want to skip activation, so that I can review the output before switching.

### Deploying

51. As a site builder, I want the Site recipe to hold every layout, preset, View, display binding and override field, so that production matches local.
52. As a site builder, I want the Site recipe to carry the home and 404 nodes, so that production shows the same pages.
53. As a site builder, I want production to run without the converter modules, so that converter code never runs there.
54. As a site builder, I want the Site recipe to depend on the Display Builder submodules it needs, so that applying it enables them.

### Maintaining

55. As a maintainer, I want the conversion runner, ownership and recipe writer in `wordpal_convert`, so that both writers share them.
56. As a maintainer, I want a writer interface with one implementer per target, so that a third target needs one module.
57. As a maintainer, I want the runtime props overlay in `wordpal_convert`, so that component generation needs no builder module.
58. As a maintainer, I want the inspector panel builder in `wordpal`, so that both form alters share it.
59. As a maintainer, I want `wordpal:parity` to render a pattern on either target, so that one command checks both.
60. As a maintainer, I want the Canvas target's behavior unchanged by the move, so that milestone 3 acceptance still holds.

### Testing

61. As a maintainer, I want the three acceptance themes converted to Display Builder on the dev site, so that real themes exercise the target.
62. As a maintainer, I want `wordpal:parity` green on every route of the three themes, so that Markup parity holds on both targets.
63. As a maintainer, I want kernel tests that write fixture conversions to Display Builder, so that regressions surface without Playground.

### Site-wide tokens

64. As a site builder, I want a ui_skins theme setting to change a token site-wide, so that I can tune the palette on every page.

## Implementation Decisions

### Modules

The project ships six modules:

- `wordpal`: unchanged role, plus the inspector panel builder both form alters call.
- `wordpal_convert`: gains the conversion runner, prepared conversion, ownership manifest, Site recipe writer, navigation menus, report, parity command, convert command and the runtime props overlay. It defines the writer interface.
- `wordpal_canvas`: the Canvas writer, the Canvas form alter and the Canvas page variant template. It implements the interface.
- `wordpal_canvas_runtime`: the Pattern Views area plugin, its `hook_views_data()` entry and the ensure-array adapter. Production installs it without the converter (ADR 0008).
- `wordpal_display_builder`: the Display Builder writer, the UI Patterns form alter and the ui_skins file. It implements the interface.
- `wordpal_display_builder_runtime`: the pattern preset Views area plugin, its `hook_views_data()` entry and the page-content wrapper hook. Production installs it without the converter (ADR 0008).

`wordpal_display_builder` depends on `display_builder`, `display_builder_entity_view`, `display_builder_page_layout`, `display_builder_views`, `ui_patterns_views` and `ui_skins`. `display_builder_ui` is suggested, not required: conversion writes profiles' consumers, never profiles.

The move changes no Canvas behavior. The ownership manifest moves to a `wordpal_convert` config name and records the target. Local sites reconvert; no update hook ships before release.

### Target option

`--target` takes `canvas` or `display_builder`. Without it, the conversion uses the target whose module is enabled, and fails when both are. The command fails before Playground when the named target's module is disabled, or when neither module is. The failure names the modules to enable.

A theme's manifest records its target. A conversion with another target fails like a repeat conversion, and `--replace` removes the existing outputs first.

### Writer interface

A deep module boundary in `wordpal_convert`. The runner prepares a conversion, writes the theme, then hands each tree to the writer. The interface covers:

- writing a pattern, a page frame, a static Template page, a content template, a term Template and a listing Template View
- the entity types and config names the target owns, in deletion order
- rendering one written pattern for parity
- the activation wiring: front page, 404 page, default frame, listing Views
- the recipe actions the target's outputs need

The runner keeps the Query sharing rule, the skip handling, Demo content, ownership and recipe writing. Each writer returns created output lines the report prints.

### Source tree builder

A deep module in `wordpal_display_builder`. Input: a block tree and the Content mapping. Output: a UI Patterns source tree in Display Builder's stored shape, node ids included. It saves nothing.

| Block tree input | Source |
|---|---|
| string prop | textfield |
| HTML string prop | wysiwyg, the WordPal HTML text format |
| enum prop | select |
| boolean prop | checkbox |
| number prop | number |
| slot children | slot sources, in order |
| runtime block plugin | block source with the plugin's settings |
| Frozen block | the frozen-html component with its key |
| runtime prop | a binding from the runtime bindings table |

Inline HTML props use the WordPal HTML text format too, so a link's `rel` and `target` survive on this target.

Node ids derive from the tree path, so a reconversion writes the same ids and a diff stays readable. The builder reads the same attribute flattening the Canvas writer reads.

### Runtime bindings

A checked-in table in `wordpal_display_builder`, keyed by runtime component and prop. Each entry names the UI Patterns source chain for a mapped concept: a field property, an entity link, or a referenced entity's field property. The writer resolves the chain against the Content mapping's bundle and fields.

A prop with no entry, or an entry whose mapped field is missing, fails the conversion and names the prop. Nothing writes an empty binding.

The same table serves three consumers: entity view display sources, View row components and the runtime presets. Bindings inside a View row bind to the row entity.

### Templates

Each Part set becomes one page layout holding the header tree, the main page content source and the footer tree. The home Template's Part set is the default frame: no condition, highest weight. Every other frame gets conditions for the routes of the Templates using it. The routes are the front page path, the node bundle, the term route, the search path and the 404 path.

The home and 404 Templates become two nodes of the mapped page bundle. Each node's override field holds the Template body tree. The writer creates the override field storage and instance on the bundle when missing, and allows overrides on the bundle's full display. The field and its instance are Owned outputs.

The single and page Templates become the mapped bundle's full entity view display sources, with runtime bindings on the routed node. A full display Display Builder already builds is an Unmanaged output and fails the conversion before any write.

The archive, tag, category and search Templates become Views. The Template tree is the View display's sources, with the Query block's subtree in place.

### Query loops

A Query block converts to a View with the Display Builder display extender. The Query's subtree becomes the display's sources:

| WordPress block | Source |
|---|---|
| Post Template | View rows |
| Query Pagination | View pager |
| Query No Results | View empty |

The View's row plugin is the UI Patterns component row. Its component tree is the Post Template's card tree with runtime bindings on the row entity. The outer tree places the View through a block source.

The Display Builder target writes no view mode and no card content template. The View keeps the `wordpal_query` tag so the row classes and the WordPress pager markup apply. The pager and row templates get their suggestions from a Views hook when the display extender bypasses the views view preprocess.

Query sharing keeps its hashing rule: one View per identical bundle, paging, sort, sticky filter and subtree.

### Patterns and presets

Each Inserter pattern becomes a pattern preset in a group named after the theme. Its sources are the pattern tree with Sample content. A Hidden pattern is inlined. A pattern whose tree fails to build is skipped and listed, as on Canvas.

Each runtime component gets one pattern preset per mapped bundle, in a group named after the theme and the bundle. Its runtime props carry the bindings table's sources. Editors placing a Post Title pick the preset and get a working block.

Presets are Owned outputs.

### Inspector form and presets

`wordpal_display_builder` implements `hook_ui_patterns_form_alter`. For a WordPal component it groups the prop fields into Content, Settings and Styles, one collapsible panel per WordPress panel, from the `x-wordpal-panel` key. Preset props become selects over the theme's tokens plus a custom value. The panel builder moves from `wordpal_canvas` to `wordpal`, and both form alters call it.

The component definition keys WordPal writes pass UI Patterns 2 unchanged. It reads `meta:enum` for labels and `contentMediaType` for the HTML widget, and ignores `x-wordpal-panel`.

### ui_skins variables

The theme files hook in `wordpal_display_builder` adds a ui_skins CSS variables file to the generated theme. Each preset becomes one variable named as WordPress prints it, such as `--wp--preset--color--primary`, with the preset value as default. Display Builder's Design tokens panel overrides a preset on the selected component, as an inline style. A ui_skins theme setting overrides it site-wide. The design tokens file stays for Drupal 11.5.

### Parity

`wordpal:parity` moves to `wordpal_convert`. Route parity is unchanged. Pattern parity asks the theme's writer to render the named pattern: a Canvas Pattern or a Display Builder pattern preset.

### Activation

The Display Builder target sets the front page and 404 page to the two nodes. It enables the listing Views and disables the site's Views on the same paths. The default frame is the page layout with no condition. Canvas settings are untouched.

### Ownership and recipe

The Display Builder writer owns page layouts, pattern presets, Views, the override field storage and instance, the two nodes and the menus. It also owns the mapped bundle's full display bindings. The display itself stays the site's config; the recipe sets its third-party sources through a config action, as Canvas content templates use actions today.

Display Builder instances are content entities derived from the stored sources. The writer never creates them and the recipe never exports them. Display Builder creates one when an editor opens a builder.

The recipe installs the Display Builder submodules the target needs, and exports the two nodes and the Demo content with core's content exporter.

### Test site

The development site runs core 11.4.7 and Canvas 1.11. It gains Display Builder 1.0.0-beta7 with its submodules, UI Patterns 2 and ui_skins. Display Builder's README asks for JS aggregation off in development.

## Testing Decisions

A good test checks behavior from outside: a fixture in, a saved config or rendered HTML out. It reads no private state and asserts no call order.

Tested modules:

- Source tree builder, unit: block tree fixtures in, expected source trees out, one fixture per prop kind, slot nesting, runtime block and Frozen block.
- Runtime bindings, kernel: a mapped bundle with one node renders title, date, excerpt, terms, featured image and read more through the bindings. A missing field fails with the prop's name.
- Display Builder writer, kernel: a fixture conversion writes page layouts, the two nodes, the display sources, presets and Views. Each passes config validation and every route renders.
- Inspector form and ui_skins, kernel: the form for a WordPal component shows the three tabs and panels. Preset selects list the theme's tokens. The generated theme declares the CSS variables.

Prior art: the `wordpal_canvas` kernel tests and their fixture conversion trait. Closest are the template writer, query page and inspector form tests, and the runtime props overlay unit test.

`display_builder` and its submodules are test dependencies of `wordpal_display_builder`. The Canvas kernel tests keep passing after the move.

## Out of Scope

- Both targets on one site
- Converting a Canvas conversion to Display Builder in place; sites reconvert with `--replace`
- Display Builder profiles, collaboration and per-site builder settings; conversion uses the default profile
- Native Views sources on the Canvas target; Canvas keeps its Views block Components
- Drupal core below 11.4, the floor Display Builder needs
- Author and date archive Templates, custom Templates and classic themes
- The image lightbox
- A migration of existing production sites; no update hook ships

## Further Notes

- Terms follow `CONTEXT.md`. ADRs 0012 to 0015 record the writer interface, native Views sources on the Display Builder target, runtime presets and ui_skins variables.
- Versions: Display Builder 1.0.0-beta7 requires UI Patterns 2.0.21 or later and core 11.4.
- The Display Builder README states it works in development mode only for now.

Risks:

- Display Builder is in beta. Its stored source shape and buildable plugins can change before 1.0.0.
- A page layout takes over every front-end page once one matches. The default frame must render a page Display Builder never converted.
- The display extender replaces the view's output, so the WordPress pager and row markup must come through the components or a Views hook.
- The override field adds a field to the mapped page bundle. A site with its own override field on that bundle needs the writer to reuse it.
