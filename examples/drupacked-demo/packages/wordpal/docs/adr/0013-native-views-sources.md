# Native Views sources for Query loops and listings

Status: Accepted

On the Display Builder target, a Query block becomes a View using the Display Builder Views display extender. The Post Template becomes the row, through the View's own `ui_patterns` row plugin. Query Pagination becomes the pager, through the View's own pager. A `view_rows` source, and a `view_pager` source when the loop paginates, mark where each region sits in the builder canvas. Neither source holds a tree of its own. Each is a bare placement marker over Views' own plugin configuration.

Query No Results has no such native region. The empty state, and the whole tree of a listing Template (archive, tag, category or search), each becomes a separate, disabled pattern preset instead. A `wordpal_pattern_preset` Views area or empty plugin (`PatternPresetArea`) in the `wordpal_display_builder_runtime` module renders that preset through ui_patterns' own `ComponentElementBuilder`. Production installs the runtime module without the writer. The View's own `view_empty` or `view_header` source stays a bare marker too, over that area plugin instead of over a Views plugin.

The plan wanted the Template's tree inside the View's own display sources, the way a page layout's tree sits inside its sources. A `view_header` or `view_empty` source takes no children, so the tree could not move there as built. A disabled preset keeps the tree editable in the builder. The View's own sources gain a second entity they do not reference directly.

Query sharing keeps its `TreeHashing` rule. The View's id derives from `TreeHashing::queryViewKey()`, over the loop's bundle, paging, sort, sticky filter and subtree. Two Query blocks sharing all five share one View. The empty pattern's id derives from `TreeHashing::treeKey()`, over the no-results subtree alone. Two Queries with the same empty state share one preset even with different cards.

A listing block (Latest Posts, Page List or Latest Comments) is not a Query block. It places as a plain `block` source referencing the View's own `views_block` block plugin id (`ListingBlocks`). Both targets share that block-display View through `ListingViewConfig::viewId()`. It needs no display extender and no pattern preset of its own.

## Considered Options

- Build the Template's tree and the Query's empty state as `view_header`/`view_empty` source children. Rejected: neither source type takes children on the installed Display Builder.
- Keep the Query's row and pager as bare frozen HTML too, matching the empty state's own treatment. Rejected: Views already has a native row plugin and pager, and the plan gives them their own sources.

## Consequences

- The listing Template's page View shows no content of its own. Its base display only validates the route, and the header area's preset renders what a visitor sees.
- An editor opens the Query's card, or a listing Template's tree, as a pattern preset. Neither sits in the View's own canvas.
- Revisit the presets when Display Builder's `view_header` and `view_empty` sources take children. The trees can then move into the View's own sources.
- This differs from [ADR 0005](0005-query-props-in-view-config.md). Canvas keeps Query paging, sort and the empty state in the View's `wordpal.query` third-party setting, and renders the empty state as a `Pattern`, through `wordpal_pattern`. Display Builder's Query Views now carry `wordpal.query` too, written by the shared `QuerySettingsWriter`: its pager stays Views' own plugin, and its empty state is a `PatternPreset`, through `wordpal_pattern_preset` instead of a Canvas `Pattern`. Both targets build their Query and listing-template Views through the shared `QueryViewConfig`, passing their own row, area plugin and display extenders.
