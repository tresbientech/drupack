# One writer per Builder target

Status: Accepted

A **Conversion** writes through one `WriterInterface` implementation per **Builder target**. `wordpal_canvas` and `wordpal_display_builder` each tag a writer service `wordpal_convert.writer` with their target, and `WriterSelector` returns the one `--target` names. `ConversionRunner` holds what does not vary by target: Query sharing, skip handling, Demo content, ownership and the Site recipe writer.

The runner, prepared conversion, ownership manifest, Site recipe writer, navigation menus, report and parity moved from `wordpal_canvas` into `wordpal_convert` for this change (PRD story 55). `wordpal_canvas` implements the interface now, and keeps only its own writes, its form alter and its page variant template.

## What the interface owns today

- Frozen blocks: which blocks a tree freezes, their HTML, and their keys (`frozenNodes()`, `frozenHtml()`, `frozenKeys()`).
- Entity types: what a pattern, a page and a page frame save as (`patternEntityType()`, `pageEntityType()`, `pageFrameEntityType()`).
- Full display: the config name a bundle's full display owns, or none (`fullDisplayConfigName()`).
- Target capability: whether Query blocks are supported, and which routes have no writer yet (`supportsQueryLoops()`, `unsupportedRoutes()`).
- Page mapping: whether the home and 404 Templates need a page bundle (`requiresPageMapping()`).
- Runtime presets: the ids and the write for a bundle's runtime-component presets (`runtimePresetIds()`, `writeRuntimePresets()`).

Further methods write the trees themselves:

- Patterns: a plain or Query-bearing pattern, and rendering a saved one back for parity (`writePattern()`, `writeQueryPattern()`, `renderPattern()`).
- Frames and pages: a page frame built from a Part set and the routes using it, a static page, and a page with Query outputs (`writePageVariant()`, whose `$routesByPartSet` carries the routes, `writeStaticPage()`, `writeQueryPage()`). `pageVariantNotes()` returns the report notes for a route whose frame could not be conditioned.
- Content templates: a bundle's full template, plain or with Query outputs (`writeContentTemplate()`, `writeQueryContentTemplate()`).
- Listing Templates: a Template's View and pattern, its term pattern, and their ids (`writeTemplateView()`, `writeTermTemplate()`, `templateOutputIds()`).
- Query and listing ids: the ids and entity types a Query's or a listing block's outputs save under (`queryOutputIds()`, `queryCardEntityTypes()`, `listingOutputIds()`, `listingOutputs()`). `listingComponentEntityType()` names the entity type a listing block's own Component saves as, or none.

## The recorded gap

Nine places in `wordpal_convert` still read the target string or a builder's own config, instead of asking the writer:

- `ConversionRunner::write()` sets the default frame in `canvas.settings` only when the target is `canvas`. Display Builder's default frame is its own condition-less page layout and needs no config write, but no interface method carries that choice.
- `ConversionOwnership`'s unwiring on `--replace` resets `canvas.settings.default_page_variant` for every target. The reset matches Canvas's owned `page_variant` ids and matches nothing on Display Builder's `page_layout` ids.
- `SiteRecipeWriter` reads `$manifest['target'] === 'display_builder'` to import Display Builder's default profile. Its `actions()` also reads a full display's `third_party_settings.display_builder` shape by hand. It forces `profile`, `override_field`, `override_profile` and `sources` onto a bundle's existing full display. A fresh site's pre-existing display is config the recipe's `strict: FALSE` otherwise leaves untouched.
- `SiteRecipeWriter::DEVELOPMENT_MODULES` names `wordpal_display_builder` itself, so `wordpal_convert` already knows the Display Builder writer's own module name instead of asking it.
- `SiteRecipeWriter::actions()` reads `canvas.settings:default_page_variant` on every target. On Display Builder it finds none.
- `SiteRecipeWriter::actions()` writes each `canvas.content_template.*` entity's properties by hand.
- `ConversionOwnership` clears `third_party_settings.display_builder` from a full display on `--replace`.
- `ConversionOwnership::fullDisplayBuilt()` reads `third_party_settings.display_builder.sources` to tell a built full display from a bare one.
- `SiteRecipeWriter::actions()` reads the target to enable the Design Tokens island in `display_builder.profile.default`, which Display Builder ships disabled.

The end state gives the interface an activation-wiring method and a recipe-actions method. Each writer would wire its own default frame and build its own recipe actions. A third target would then add one module, with no edit to `wordpal_convert` (PRD story 56).

## Considered Options

- Branch on `--target` throughout `wordpal_convert`, with no interface. Rejected: PRD story 56 asks a third target to add one module, not edit the shared one.
- Give each target its own runner, ownership manifest and Site recipe writer. Rejected: duplicates Query sharing, skip handling, Demo content and recipe writing, none of which vary by target.

## Consequences

- A third target adds one module and one tagged `wordpal_convert.writer` service. `WriterSelector::forTarget()` throws `LogicException` when none is tagged, so `ConvertCommands` checks the target's module first.
- The manifest's `target` key stays. `SiteRecipeWriter` and `ParityRenderer` read it to pick the writer, and the two missing methods do not change that.
- `wordpal_canvas`'s own behavior is unchanged by the move: the same entities save under the same ids (PRD story 60).
