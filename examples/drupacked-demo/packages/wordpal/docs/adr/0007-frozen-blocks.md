# Frozen blocks

A **Block** with no **Component** becomes a **Frozen block**: one `wordpal:frozen-html` component holding the HTML WordPress rendered for that block. Its Template, Template part or Pattern still converts. The report lists every Frozen block.

In milestone 1 one unknown block skipped its whole tree. The February 2025 crawl of 517 block themes found 5 whose core blocks the library fully covers. Plugin blocks appear in 44% of block themes, WooCommerce in 162 of them. Skipping would drop the header or footer of most themes.

A Frozen block keeps the plugin CSS WordPress enqueued while rendering it. The snapshot records the plugin stylesheets each block and its inner blocks enqueue or their block types name, and the inline CSS added to them. Plugin inline CSS queued for the whole page, such as Gutenverse's generated CSS, ships whole with each Frozen block of that plugin. The records are keyed by the hash of the block's HTML. Convert copies each stylesheet and its `url()` assets into the generated theme and declares it as a library. A Frozen block attaches its own libraries only, so a page without one loads no plugin CSS. Loading every plugin front-end style instead adds WooCommerce's site-wide styles, several hundred KB, to every page.

Plugin scripts, view scripts and script modules are never exported. A Frozen block holds conversion-time data and cannot call the plugin's WordPress endpoints, so its scripts would fail or act on stale data.

The generated theme prints a Frozen block's HTML unfiltered, so Convert checks it before writing. HTML holding a `<script>`, `<iframe>`, `<object>` or `<embed>`, an `on*` attribute, a `javascript:`, `vbscript:` or non-image `data:` URL, a CSS `expression()`, or an unclosed tag or comment fails its tree, and the report lists the block as skipped. In CSS, a `data:` URL passes only as a `url()` image: PNG, JPEG, GIF, WebP, AVIF or SVG. A browser never runs script from an image a stylesheet loads.

WordPress renders an unregistered dynamic block as nothing. `--plugin` installs the plugins a site builder names, so the frozen HTML holds the plugin's real markup. Convert never guesses a plugin from a block namespace: namespaces do not match plugin slugs, and the wordpress.org block search covers single-block plugins only.

A Frozen block carries a subtle mark on public pages, in editing and in preview, so a site owner sees what did not convert. The mark leaves the surrounding layout unchanged. Its first element that draws a box gets a `data-wordpal-frozen` attribute holding the block name. The frozen-html component's stylesheet draws a dashed outline on it, and an outline takes no layout space. Frozen blocks are declared exceptions to **Appearance equivalence** (ADR 0017).

`core/shortcode` stays a Frozen block: a shortcode calls a plugin's PHP, which Drupal does not run. `core/html` converts to an editable component instead, filtered by the WordPal HTML text format (ADR 0004).

## Considered Options

- Skip the tree and report it. Rejected: most themes lose their frame.
- Generate a Component per `block.json`. Rejected: works for simple static blocks only, and breaks on nested or dynamic blocks.
- An extension point for modules to supply Components per block name. Deferred to a later PRD.

## Consequences

- Editors cannot change a Frozen block's HTML or styling in Canvas. A hole slot inside it is an ordinary slot.
- A dynamic Frozen block shows conversion-time data, such as a Playground cart or a plugin's post list. A Query inside it is the exception below.
- Amended 2026-10-01: a Query inside a Frozen block shows live posts. Its View fills a hole slot at the Query's place in the HTML, `hole_1` to `hole_4`, and a left-out Query is cut out. The file still sits under the hash of the HTML WordPress rendered, so the block's plugin styles keep their key.
- A form in a Frozen block renders but does not submit.
- A cart, form or slider in a Frozen block keeps its plugin styling and has no behavior.
- A plugin stylesheet may select on body classes or markup Drupal pages do not print.
