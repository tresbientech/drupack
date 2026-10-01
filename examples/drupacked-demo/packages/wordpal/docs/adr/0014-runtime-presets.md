# Runtime presets bound to the routed entity

Status: Accepted

Each runtime WordPress block gets one pattern preset per mapped bundle. `DisplayBuilderWriter::writeRuntimePresets()` saves one preset per bundle for each of ten runtime blocks, from Post Title to Read More. It skips a block the Content mapping drops. Each preset lives in a group named after the theme and the bundle. An editor places Post Title on any page layout and sees the routed node's own title, with no field selection step.

A binding is one entry of the `RuntimeBindings` table, keyed by a block's name. It names a chain for each of the block's props. A chain is a field property on the routed entity, the entity's own canonical link, or a referenced entity's field property one hop away. `RuntimeBindings::props()` resolves each chain against the Content mapping's bundle and fields, and returns UI Patterns source configuration. The same table also binds a bundle's full display and a Query row's card, so a chain is written once.

## Open point

The PRD's rule fails the conversion when a prop has no bindings entry, or names a field the bundle lacks, naming the prop (story 20). As built, an uncovered prop keeps the tree's own static value instead of failing. Post Terms fills its `content` slot with one Post Term link per term, through the `ui_patterns_component_per_item` formatter. Post Date binds `timestamp` and `url` only. Its `date` prop stays static: UI Patterns' own date validator rejects a raw timestamp with no adapter, and the component formats `timestamp` itself.

A missing image field on the bundle is a second exception: Post Featured Image drops from the tree instead of failing or binding to nothing. `SourceTreeBuilder` reports it under the report's "Dropped concepts" list when it omits the block, from `RuntimeBindings::omission()`. `omits()` records nothing, so preset eligibility checks count no drop. A missing Post Terms field omits the block without a report. WordPress itself prints nothing for a post with no thumbnail either.

## Considered Options

- Bind each runtime component's props by hand, once per consumer and bundle. Rejected: duplicates the same chain across the display, the Query row and the preset.
- Store bindings in the Content mapping file, one row per runtime prop. Rejected: the mapping names Drupal targets for WordPress concepts; a chain crossing a reference is UI Patterns' own source shape.

## Consequences

- Closing the open point needs a decision on whether an uncovered prop should fail everywhere the PRD's rule reaches, or only where a binding exists today.
- A block `RuntimeBindings::props()` does not match at all, such as Query Title inside a real Template tree, keeps every one of its props static. The block is not a runtime component, so its tree already carries its own authored value.
