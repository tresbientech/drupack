# Query props in View config

A WordPress Query block splits into two parts. The card, the Post Template's inner tree, becomes items on a generated view mode's `ContentTemplate`, which Canvas edits like any other content template.

Everything else has no Canvas-editable surface: bundle, paging, sort, the sticky filter, and the empty-state tree. ADR 0002 says a prop needs a field type and widget; none of these has one. They live in the View's own display options and its `wordpal.query` third-party setting instead.

The empty state stays a disabled `Pattern`, rendered into the View through a `wordpal_pattern` Views area plugin. A Canvas `Pattern` is already a self-contained item tree Canvas can hold and edit.

The View, view mode, template, and empty Pattern are each keyed by a short hash of their own content. ADR 0003 uses the same scheme for a **Part set**'s `PageVariant`. Two Query blocks with the same card share one view mode and template. Two Query blocks with the same bundle, paging, sort, sticky filter, and Query subtree share one View and one empty Pattern. They also share the views_block `Component` derived from that View.

## Considered Options

- One View, view mode, and template per Query block, as phases 6 to 9 built it. Rejected: every occurrence of the same card and settings pays for its own copies. Twenty Twenty-Four's query patterns produced 16 Views, 17 view modes, and 17 templates for a handful of distinct shapes.
- An editable wrapper component holding paging, sort, and the empty-state tree as its own props. Rejected: those values have no Canvas field type and widget (ADR 0002). A Views pager's position in the page can't round-trip through one component instance's props either.

## Consequences

- A Query block's paging, sort, and empty state are not visible to a Canvas editor. Changing them means editing the source WordPress theme and reconverting.
- Editing a shared card in Canvas changes every Query block that rendered the same card at conversion time.
- A `--replace` **Conversion** deletes each shared View, view mode, template, and Pattern once, from `ConversionOwnership`'s deduplicated manifest.
