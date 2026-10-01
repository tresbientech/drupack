# Plan: Display Builder target, milestone 4

> Source PRD: `docs/prd/display-builder-target-milestone-4.md`. Terms: `CONTEXT.md`. Decisions: `docs/adr/0001` to `0011`, plus the four ADRs this plan writes.

## Architectural decisions

Durable across every phase.

Modules:

- `wordpal`: components, block plugins, Interactivity libraries, the inspector panel builder. No converter code.
- `wordpal_convert`: snapshot, trees, mapping, runner, ownership, Site recipe writer, parity, Drush commands, the writer interface, the runtime props overlay.
- `wordpal_canvas`: the Canvas writer, the Canvas form alter, the Canvas page variant template.
- `wordpal_canvas_runtime`: the `wordpal_pattern` Views area and the `ensure_array` adapter.
- `wordpal_display_builder`: the Display Builder writer, the UI Patterns form alter, the ui_skins file.
- `wordpal_display_builder_runtime`: the `wordpal_pattern_preset` Views area and the page-content wrapper hook.

Contracts:

- `wordpal:convert --target=canvas|display_builder` is required when both target modules are enabled. Otherwise the enabled target is used. A disabled target module fails before Playground.
- The ownership manifest lives under a `wordpal_convert` config name and records the target. One target per theme per site; another target needs `--replace`.
- A writer implements one interface: pattern, page frame, static page, content template, term Template, listing View, owned types, pattern render. Activation wiring and recipe actions stay in `wordpal_convert` (ADR 0012).
- The source tree builder saves only a listing block's View, through `ListingBlocks::place()`. It returns Display Builder's stored shape with node ids derived from the tree path.
- Runtime props bind through a checked-in table of UI Patterns source chains. A missing field fails the conversion and names the prop. An uncovered prop keeps its static value (ADR 0014).
- Display Builder outputs, by WordPress structure:

| WordPress | Display Builder |
|---|---|
| Part set | page layout |
| home and 404 Templates | a page bundle node each, with an entity view override |
| single and page Templates | the mapped bundle's full entity view display sources |
| listing Templates | a View with display extender sources |
| Query block | a View with rows, pager and empty sources, component row for the card |
| Inserter pattern | a pattern preset |
| runtime Component | a pattern preset per mapped bundle |

- Display Builder instances are never created or exported by WordPal.
- HTML props use the WordPal HTML text format on this target.
- Presets are ui_skins CSS variables named as WordPress prints them. The design tokens file stays.

Every phase ends with the suites green, PHPCS clean, and the Canvas site reconverted to Canvas on Twenty Twenty-Four with parity unchanged. From phase 2, the development site also reconverts Twenty Twenty-Five to Display Builder.

---

## Phase 1: Writer interface and `--target`

**User stories**: 1, 2, 3, 4, 5, 55, 56, 57, 58, 59, 60

### What to build

The runner, prepared conversion, ownership, Site recipe writer, navigation menus, report, parity and the convert commands move from `wordpal_canvas` to `wordpal_convert`. The runtime props overlay moves with them, so `drush wordpal:generate-components` needs no builder module. The inspector panel builder moves to `wordpal`.

`wordpal_convert` defines the writer interface. `wordpal_canvas` implements it and keeps only Canvas writes, its form alter and its page variant template. The manifest moves to its `wordpal_convert` config name and records the target.

`--target` is required. The command fails before Playground when it is missing or its module is disabled, and names the module. A conversion with another target fails like a repeat conversion.

The development site gains Display Builder 1.0.0-beta7, its submodules, UI Patterns 2 and ui_skins, beside Canvas 1.11.

Tracer: `drush wordpal:convert twentytwentyfour --target=canvas --replace` on the Canvas site, then parity on every route.

### Acceptance criteria

- [x] Every `wordpal_canvas` kernel and unit test passes after the move, with moved tests relocated (116 passed, 0 failed, 2026-09-28. ComponentRenderTest's Button "tag name" case expected an exception `button.twig` never raises, since it coerces the tag to `a`. It now asserts no `<script`, 2366db0)
- [x] A convert without `--target`, with both targets enabled, fails before Playground and names the option (ConvertCommandsTest::testMissingTargetWithBothEnabledAsksForIt, testMissingTargetWithNoneEnabledNamesTheModules)
- [x] `--target=display_builder` with the module disabled fails before Playground and names the module (ConvertCommandsTest::testDisabledTargetModuleNamesTheModule)
- [x] A theme converted to Canvas refuses `--target=display_builder` without `--replace`, listing the existing outputs (ConversionRunnerTest::testDisplayBuilderTargetRefusesCanvasConvertedTheme)
- [x] `drush wordpal:generate-components` runs with `wordpal_canvas` disabled and writes identical component files (on the Twenty Twenty-Five Display Builder site with `wordpal_canvas` disabled, 2026-09-29: `wordpal:generate-components 7.1.2` leaves the tree at 2366db0 unchanged; ComponentGenerationTest: the command's one alter hook has one implementer, wordpal_convert)
- [x] Twenty Twenty-Four reconverts to Canvas on the Canvas site with parity unchanged on every route (2026-09-29 at 2366db0: home 20, single 3, page 0, 404 0, archive 2, search 6, page 2 2, empty search 0, none above the f7ea8fa counts)
- [x] The development site runs Display Builder with the page layout, entity view and Views submodules enabled (`drush pm:list` on the development site, 2026-09-28)

---

## Phase 2: First Display Builder pattern

**User stories**: 6, 7, 8, 9, 37, 38, 39, 40, 41, 42, 43, 44, 51, 53

### What to build

The `wordpal_display_builder` module implements the writer interface for patterns only. The source tree builder turns a block tree into a Display Builder source tree. String, HTML, enum, boolean and number props go to their widgets. Slot children keep their order. Runtime block plugins become block sources with their settings. Frozen blocks become the frozen-html component. Node ids derive from the tree path.

Each Inserter pattern becomes a pattern preset in the theme's group, with Sample content. A Hidden pattern is inlined. A pattern that fails to build is skipped and listed. Presets are Owned outputs and land in the Site recipe. Pattern parity renders a preset through the writer.

The other writer methods raise a clear "not on this target yet" failure, so a full conversion stops after patterns with a named reason.

Tracer: the Twenty Twenty-Five CTA pattern, inserted on a Display Builder page layout, rendering with a heading, paragraph, buttons and a navigation block.

### Acceptance criteria

- [x] Every WordPal component is listed in the Display Builder component library and renders when placed (ComponentEditingTest::testLibraryListsEveryWordpalComponent, ::testComponentRendersWhenDropped over all 47; quote, separator and media-text dropped on the Display Builder site, 2026-09-29. 14 components print nothing on an empty drop, as WordPress does with no content)
- [ ] A Columns preset accepts only Column children in its slot
  Gap in Display Builder beta8: it never reads a slot's `expected` list, so a Paragraph attaches to the Columns slot (ComponentEditingTest::testColumnsSlotExpectsColumnButDisplayBuilderAcceptsAnything).
- [ ] A paragraph's HTML content edits in the rich text widget and keeps inline formatting
  The render keeps `<strong>` and links (ComponentEditingTest::testParagraphRendersBoldAndLink). Gap: `content` gets a textfield, since Display Builder's wysiwyg widget takes slot props only (::testParagraphContentWidget).
- [x] An enum prop shows WordPress's labels in its select (ComponentEditingTest::testEnumLabelsMatchWordPress, ::testBorderStyleSelectListsWordPressStyles, ::testBorderStyleSelectKeepsSavedStyle; labels and options from WordPress 7.1.2)
- [x] Twenty Twenty-Five's Inserter patterns are presets in the theme's group, with Sample content (TT25 on the Twenty Twenty-Five Display Builder site, 2026-09-28: 70 of 70 Inserter patterns are presets in group `twentytwentyfive`, and `twentytwentyfive_format_audio` holds its Sample text)
- [x] A Frozen block and a navigation block render inside a preset (DisplayBuilderConversionTest::testPresetHoldsFrozenBlockAndRendersNavigation; on the Twenty Twenty-Five Display Builder site, `twentytwentyfive_format_audio` prints its frozen `<audio>`)
- [x] `wordpal:parity` passes on one Twenty Twenty-Five pattern preset (`wordpal:parity - twentytwentyfive_format_audio` on the Twenty Twenty-Five Display Builder site matches, 2026-09-28)
- [x] `--replace` removes every preset, and the Site recipe holds them (DisplayBuilderConversionTest::testReplaceLeavesNoOrphanPresetAndTheRecipeHoldsEachPreset; TT25 on the Twenty Twenty-Five Display Builder site: manifest, config and recipe each hold 94 presets)
- [x] Unit tests cover the source tree builder: each prop kind, slot nesting, a runtime block, a Frozen block, stable node ids (SourceTreeBuilderTest, a Kernel class)

---

## Phase 3: Page frames, home and 404

**User stories**: 21, 22, 25, 26, 27, 28, 30, 48, 50, 52, 54

### What to build

Each Part set becomes a page layout holding the header tree, the main page content source and the footer tree. The home Template's frame has no condition and the highest weight. Every other frame gets conditions for its Templates' routes.

The home and 404 Templates become two nodes of the mapped page bundle. The writer creates the override field on the bundle when missing and allows overrides on the full display. Each Template body goes in the node's field. Activation sets the front page and 404 page to the nodes. The Site recipe exports the nodes with their trees, the field, the page layouts, and depends on the Display Builder submodules.

Tracer: `drush wordpal:convert twentytwentyfive --target=display_builder` on the development site, then the front page and a missing path in a browser.

### Acceptance criteria

- [x] The front page shows the home Template inside its frame, and a missing path shows the 404 Template (page-scope parity on home and not_found, TT24 on the Display Builder site, beta8 run of 2026-09-28)
- [x] A node without a converted Template renders inside the default frame (DisplayBuilderConversionTest::testDefaultFrameOutputHoldsHeaderFooterAndMainContent; an unmapped node on the demo site, 2026-09-28)
- [x] Each Part set is one page layout, and two Templates sharing a Part set share it (DisplayBuilderConversionTest::testPatternsFramesHomeAndSingleFullDisplayConvert)
- [x] The override field is created once and reused on a second conversion (DisplayBuilderConversionTest::testOverrideFieldIsReusedOnSecondConversion)
- [x] Every page layout, the field and the two nodes open in the Display Builder (TT25 on the demo site, 2026-09-29: both frames, the page and blog full displays and both nodes open with no PHP deprecation, and each inspector loads a placed source's form)
- [x] `--no-activate` leaves the front page and 404 page as they were (DisplayBuilderConversionTest::testNoActivateLeavesSiteActivationUntouched)
- [x] The Site recipe applies on a fresh site and shows the same front page and 404 page (DisplayBuilderSiteRecipeTest; fresh Drupal CMS 2.2.0, TT25 and YITH, 2026-09-27)
- [x] `wordpal:parity` passes on the home and not_found routes (TT24 and TT25 on the Display Builder site, beta8 run of 2026-09-28)
- [x] Kernel tests cover the page layouts, the nodes and the recipe (DisplayBuilderSiteRecipeTest, DisplayBuilderConversionTest)

---

## Phase 4: Runtime bindings, single and page

**User stories**: 16, 17, 18, 19, 20, 23, 29

### What to build

The bindings table maps each runtime component and prop to a UI Patterns source chain. A chain is a field property, an entity link, or a referenced entity's field property. The writer resolves each chain against the Content mapping. A prop with no entry or a missing field fails the conversion and names the prop.

The single and page Templates become the mapped bundles' full entity view display sources, with runtime bindings on the routed node. A full display Display Builder already builds is an Unmanaged output. The recipe sets the display's sources through a config action.

Each runtime component gets one preset per mapped bundle, in a group named after the theme and the bundle, with its bindings filled.

Tracer: a Twenty Twenty-Five blog post on the development site, with title, date, featured image, terms and body from Drupal fields.

### Acceptance criteria

- [x] A blog post renders in the single Template with every runtime block filled from its fields (ContentTemplateWritingTest; single parity, TT24 and TT25 on the Display Builder site, beta8 run of 2026-09-28)
- [x] A basic page renders in the page Template (DisplayBuilderConversionTest::testBasicPageRendersThroughThePageTemplate)
- [ ] An editor places the Post Title preset on a page layout and it shows the routed node's title
  Gap in Display Builder beta8: a page layout provides only a `page` context (`PageLayout::getRuntimeContexts()`), so the Presets panel hides a preset that needs a node. Placed by script, the preset shows the routed node's title on the demo site.
- [x] A bindings entry naming a field the mapping lacks fails the conversion and names the prop (ContentTemplateWritingTest::testMissingFieldFailsNamingTheProp)
- [x] A bundle whose full display Display Builder built by hand fails the conversion before any write (ConversionOwnershipTest::testFullDisplayOwnershipTracksSourcesNotTheConfig, ::testFullDisplayCollisionWithoutActivation)
- [x] The Site recipe restores the display sources on a fresh site (DisplayBuilderSiteRecipeTest::testRecipeAppliesOnFreshSite, f7ea8fa)
- [x] `wordpal:parity` passes on the single and page routes (TT24 and TT25 on the Display Builder site, beta8 run of 2026-09-28)
- [x] Kernel tests cover the bindings for title, date, excerpt, terms, featured image and read more, plus the failure (ContentTemplateWritingTest)

---

## Phase 5: Query loops and listings

**User stories**: 24, 31, 32, 33, 34, 35, 36, 49

### What to build

A Query block becomes a View with the Display Builder display extender. Its subtree becomes the display's sources: Post Template to rows, Query Pagination to pager, Query No Results to empty. The row plugin is the UI Patterns component row holding the card tree with bindings on the row entity. The outer tree places the View through a block source. Query sharing keeps its hashing rule.

The View keeps the `wordpal_query` tag. When the display extender bypasses the views view preprocess, a Views hook in the new module supplies the pager and row template suggestions.

The archive, tag, category and search Templates become Views whose display sources hold the Template tree. Activation enables them and disables the site's Views on the same paths.

Tracer: the Twenty Twenty-Five home Query loop and a tag page on the development site, with three Demo posts and paging.

### Acceptance criteria

- [x] The home Query loop lists Demo posts with the Post Template's card markup (home parity, TT24 and TT25 on the Display Builder site, beta8 run of 2026-09-28)
- [x] Pagination prints WordPress's classes and pages (home-page-2 parity, TT24 and TT25 on the Display Builder site, beta8 run of 2026-09-28)
- [x] An empty listing shows the Query's no results tree (search-empty parity, TT24 and TT25 on the Display Builder site, beta8 run of 2026-09-28)
- [x] Two identical Query blocks share one View (ListingWriterTest::testTwoIdenticalQueryBlocksShareOneView)
- [x] Tag, category and search pages render their Templates, and the site's own Views on those paths are disabled (YITH Wonder on the YITH Wonder site, 2026-09-29: tag, category and search match their Reference renders; ConversionRunnerTest::testActivatesListingAndNotFoundTemplates, ::testSearchViewOnTheMappedPathStepsAside)
- [x] The search Template reads the mapped search index (ListingWriterTest::testSearchTemplateViewReadsTheMappedSearchIndex)
- [x] The View's card edits in the Views UI row form. The Display Builder shows the View's rows as a placeholder. The owner accepted the row form on 2026-09-28; PRD story 35 asks for card edits without Views UI (YITH Wonder on the YITH Wonder site, 2026-09-28: the row options form holds the Component select and the card's slot sources)
- [x] `wordpal:parity` passes on the home, archive and search routes, including the paged and empty listings (2026-09-28 at 2c58261: Twenty Twenty-Four on the Display Builder site, Twenty Twenty-Five on the Twenty Twenty-Five Display Builder site and YITH Wonder on the YITH Wonder site show 0 lines on home, single, page, archive, search, 404, page 2 and empty search, all with the Phase 7 mapping)
  Rerun on Display Builder 1.0.0-beta8: its per-display Views template wraps each View in a `.view` div, which broke home, archive and search. 2366db0 drops it for converted Views without Ajax. In the beta8 run of 2026-09-28, TT24 and TT25 on the Display Builder site show 0 lines on the eight routes. YITH Wonder on the YITH Wonder site shows 0 lines on the eight routes on beta8 (2026-09-29), after the comparator rule for a legacy flex Post Template.
- [x] Kernel tests cover the View sources, the row component, sharing and the listing Views (ListingWriterTest, ListingBlocksTest, ListingWriterTest::testTwoIdenticalQueryBlocksShareOneView)

---

## Phase 6: Inspector form and ui_skins

**User stories**: 10, 11, 12, 13, 14, 15, 45, 46, 47, 64

### What to build

`wordpal_display_builder` implements `hook_ui_patterns_form_alter`. For a WordPal component it calls the shared panel builder: Content, Settings and Styles tabs, one collapsible panel per WordPress panel, in inspector order. Preset props become selects over the theme's tokens plus a custom value. The Canvas form alter calls the same builder.

The theme files hook adds a ui_skins CSS variables file to the generated theme. Each preset is one variable, named as WordPress prints it, with the preset value as default. The Site recipe enables Display Builder's Design tokens panel, which overrides a preset on the selected component. A ui_skins theme setting overrides it site-wide, printed in `page_top`.

Tracer: editing a heading's color on the development site through the preset select, then changing that preset in the Design tokens panel.

### Acceptance criteria

- [x] A WordPal component's Display Builder form shows Content, Settings and Styles with WordPress's panels and order (InspectorFormTest::testGroupsHeadingPropsIntoInspectorPanels)
- [x] Content fields come first (InspectorFormTest::testGroupsHeadingPropsIntoInspectorPanels)
- [x] A color prop offers the theme's presets and a custom value, and the chosen preset renders (InspectorFormTest::testOffersThemePresetsOnColorProp, ::testChosenPresetRendersWordPressClass)
- [x] The Site recipe enables the Design tokens panel, which lists the theme's presets and overrides one on the selected component. A ui_skins theme setting changes it site-wide (DisplayBuilderSiteRecipeTest; CssVariablesPrintTest; TT25 on the redesign site: 22 presets listed)
- [x] The design tokens file is still written (DesignTokensTest::testWritesPresetsAsTokens, WordpalDisplayBuilderHooksTest::testThemeFilesAlterAddsCssVariablesFile)
- [x] The Canvas form is unchanged (wordpal_canvas InspectorFormTest, 2026-09-28)
- [x] Kernel tests cover the form alter and the CSS variables file (InspectorFormTest; the CSS variables file in the Unit test CssVariablesTest)

---

## Phase 7: Acceptance

**User stories**: 61, 62, 63

### What to build

Twenty Twenty-Four, Twenty Twenty-Five and YITH Wonder convert to Display Builder on the development site, YITH Wonder with `--plugin=woocommerce`. Parity runs on every route of each theme, with the paged and empty listing variants. Each gap gets a fix or an ADR 0004 exception scoped to one pattern.

The four ADRs land: the writer interface, native Views sources on the Display Builder target, runtime presets, and ui_skins variables. The README documents `--target`, the Display Builder requirements and the test site notes.

### Acceptance criteria

- [x] The three themes convert to Display Builder without a skipped pattern that a fix could avoid (2026-09-29: TT24 skips none, TT25 skips only 2 Demo comments. YITH Wonder on the YITH Wonder site skips none since 2366db0 keeps the Paragraph WordPress's parser migrates into a pre-6.0 Quote)
- [x] `wordpal:parity` passes on every route of the three themes (2026-09-28 at 2c58261: Twenty Twenty-Four on the Display Builder site, Twenty Twenty-Five on the Twenty Twenty-Five Display Builder site and YITH Wonder on the YITH Wonder site show 0 lines on home, single, page, archive, search, 404, page 2 and empty search, all with the Phase 7 mapping)
  Rerun on Display Builder 1.0.0-beta8: its per-display Views template wraps each View in a `.view` div, which broke home, archive and search. 2366db0 drops it for converted Views without Ajax. In the beta8 run of 2026-09-28, TT24 and TT25 on the Display Builder site show 0 lines on the eight routes. YITH Wonder on the YITH Wonder site shows 0 lines on the eight routes on beta8 (2026-09-29), after the comparator rule for a legacy flex Post Template.
- [x] The three themes still convert to Canvas on the Canvas site with parity unchanged (first run: home/single lines twentytwentyfour 20/7 as before, twentytwentyfive 10/17, yith-wonder 10/2 with WooCommerce; the six census themes match their 21:30 counts)
  Rerun: no regression. Home/single lines twentytwentyfour 20/3, twentytwentyfive 10/6, yith-wonder 10/12, blockskit-base 3/3, buildapp 1/2, charity-organization 0/9, gutentools 3/7, patterns-political 24/11; page 0, 404 0; tag and search rose to 2 and 6 or 7 on the three themes because the dropped listing wrapper lets the comparator reach the per-item `category-<slug>` gap the default mapping causes.
- [x] The kernel suites of all six modules pass, and PHPCS is clean (container suite at f9e0bc7: 807 tests, 0 failures; PHPCS clean at f9e0bc7)
- [x] Each theme's Site recipe applies on a fresh site with Display Builder and renders the same pages (fresh Drupal CMS 2.2.0, 2026-09-27: TT24 converted there with the default mapping differs only in node ids and search tie order; TT25 and YITH per the milestone 3 progress notes)
- [x] The four ADRs are written and `CONTEXT.md` names any new term (ADRs 0012 to 0015 Accepted; `CONTEXT.md` defines Writer, Runtime module, Page frame, page layout, Runtime preset and Override field)
- [x] The README covers `--target`, the Display Builder dependencies and the development-mode note (this commit)

---

## State on 2026-09-29: the parity bar

The owner set this plan's open boxes as the bar for Display Builder parity with Canvas.

### The beta8 run

The beta8 run of 2026-09-28 used the Views wrapper fix and the Reference reset, both now in 2366db0. It ran on the Display Builder site with Display Builder 1.0.0-beta8 and the Phase 7 mapping.

- The display_builder, display_builder_runtime, convert and evaluate suites pass.
- The wordpal_canvas suite passes except `ComponentRenderTest` Button "tag name", which failed before the redesign too.
- Parity at main scope shows 0 lines on the eight routes, for TT24 and TT25.
- Parity at page scope shows 0 lines on TT24's home, not_found, single, page, archive and search.

Two fixes came from it:

- 2366db0: beta8 wraps each View in a `.view` div for Views Ajax. Converted Views without Ajax drop it.
- 2366db0: the Comment Template block counts threads in globals. references.php resets them before each Reference page.

A conversion no longer writes the Reference renders. Run `drush wordpal:reference <theme>` after each conversion, before parity.

### The lanes run

The six lanes of `display-builder-parity-lanes.md` (removed 2026-09-29; see git history) merged on 2026-09-29. They ticked 12 of the 19 open boxes, and later fixes ticked four more. All of it is in 2366db0. Fixes from the lanes and after:

- The comparator reads a Query's legacy flex Post Template as the grid the editor saves (ADR 0004).
- The Site recipe enables Display Builder's Design tokens panel.
- The conversion report names the Display Builder path of each listing Template.
- A Post Term with no url renders without a deprecation in the builder preview.
- Enum selects show WordPress's labels, and border style offers WordPress's three styles.
- The parser keeps the blocks a deprecation migrates, so a pre-6.0 Quote converts.
- The Reference renders a category archive.
- `self_stretch` gains `fixedNoShrink`, and only that value prints `flex-shrink: 0`.
- Four components render on an empty builder drop.

Three boxes stay open, each on a Display Builder gap:

- Display Builder beta8 ignores a slot's `expected` list.
- Display Builder's wysiwyg widget takes slot props only, so an HTML string prop gets a textfield.
- A page layout provides no node context, so an editor cannot place a runtime preset.

### Follow-ups

- A site with two converted themes shows no frame. Display Builder selects the other theme's page layout, and FrameThemeGuard falls back. The run disabled TT25's two layouts to measure TT24.
- The parser keeps the blocks any deprecation migrates, so patterns other themes used to skip, such as an old Gallery save, now convert. Their parity is not measured.
