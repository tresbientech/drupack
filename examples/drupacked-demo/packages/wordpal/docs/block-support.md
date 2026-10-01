# Block support

This table lists every core block of WordPress 7.1.2 and its Drupal target. `drush wordpal:block-table` writes it from `components/block-coverage.json`, `components/block-notes.yml` and `components/block-usage.json`. Edit those, not this page.

A block gets one of six targets:

- Expanded: the conversion replaces the reference with the blocks it points to.
- Component: an SDC component in `components/`.
- Block plugin: a WordPal Drupal block plugin.
- View: a Drupal View built from the block's listing settings.
- Part of: the parent block's Drupal target renders it.
- Frozen: the HTML WordPress rendered, fixed. Editors cannot change it. A Query inside it renders as its View, in a hole slot.

Usage is the share of the 1,966 active block themes whose markup uses the block, from the theme census of 2026-09-24. Rows run from the most used block.

The concept column names the content a block binds to. A block drops from the conversion when the content mapping sets its concept to null.

| Block | Usage | Target | Concept | Notes |
|---|---|---|---|---|
| `core/group` | 99.9% | Component `group` |  | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/paragraph` | 99.8% | Component `paragraph` |  |  |
| `core/template-part` | 99.8% | Expanded |  |  |
| `core/post-content` | 99.7% | Component `post-content` | `post_body` | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/post-title` | 99.6% | Component `post-title` | `post` | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/post-template` | 99.2% | Component `post-template` |  |  |
| `core/query` | 99.2% | Component `query` |  | Not in the component: attribute `query`.<br>Enhanced pagination reloads the page. Its script is not ported. ([ADR 0009](adr/0009-reuse-interactivity-scripts.md)) |
| `core/heading` | 99.1% | Component `heading` |  |  |
| `core/post-date` | 98.4% | Component `post-date` | `post` | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/navigation` | 98.2% | Block plugin `wordpal_navigation` | `navigation` | A block bound to a Drupal menu prints the menu label as its `aria-label`. ([ADR 0004](adr/0004-parity-exceptions.md))<br>A block with its own links renders a Drupal menu the conversion writes from them. It binds no mapped menu.<br>Trailing Social Links render as a nested component after the links. |
| `core/post-featured-image` | 97.9% | Component `post-featured-image` | `featured_image` | A custom duotone is left out. Preset duotones convert. ([ADR 0004](adr/0004-parity-exceptions.md))<br>(Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/column` | 97.7% | Component `column` |  |  |
| `core/columns` | 97.7% | Component `columns` |  |  |
| `core/pattern` | 97.6% | Expanded |  |  |
| `core/query-pagination` | 97.2% | Component `query-pagination` |  |  |
| `core/query-pagination-next` | 96.6% | Component `query-pagination-next` |  | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/query-pagination-previous` | 96.5% | Component `query-pagination-previous` |  | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/site-title` | 95.4% | Block plugin `wordpal_site_title` |  |  |
| `core/post-terms` | 94.5% | Component `post-terms` | `category` | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/search` | 93.3% | Block plugin `wordpal_search` | `search` | The form submits the mapped search parameter to the mapped search path. ([ADR 0004](adr/0004-parity-exceptions.md))<br>Results list in the Search API index's relevance order. ([ADR 0004](adr/0004-parity-exceptions.md))<br>With the button inside, the wrapper radius is not the `calc()` of radius and padding WordPress prints. ([block-matrix-2026-09-28](plans/block-matrix-2026-09-28.md)) |
| `core/post-excerpt` | 93.1% | Component `post-excerpt` | `excerpt` | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/query-title` | 92.2% | Component `query-title` |  |  |
| `core/button` | 91.2% | Component `button` |  | (Canvas) An image in the button text drops. ([ADR 0004](adr/0004-parity-exceptions.md))<br>(Canvas) A bare `#` link prints no `href`, because the link prop rejects it. ([ADR 0004](adr/0004-parity-exceptions.md))<br>(Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/buttons` | 91.1% | Component `buttons` |  |  |
| `core/image` | 90.0% | Component `image` |  | Not in the component: attribute `lightbox`, attribute `focalPoint`.<br>The lightbox does not open. Its markup and script are left out. ([ADR 0009](adr/0009-reuse-interactivity-scripts.md))<br>An empty custom link prints no link. ([ADR 0004](adr/0004-parity-exceptions.md))<br>A custom duotone is left out. Preset duotones convert. ([ADR 0004](adr/0004-parity-exceptions.md))<br>(Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/query-pagination-numbers` | 87.8% | Component `query-pagination-numbers` |  | Drupal's pager centers a fixed window of pages in place of `midSize`. ([ADR 0004](adr/0004-parity-exceptions.md)) |
| `core/social-links` | 86.9% | Component `social-links` |  | On conversion: icon options are copied to each Social Icon, where editors change them. |
| `core/social-link` | 86.9% | Component `social-link` |  |  |
| `core/cover` | 83.8% | Component `cover` |  | Not in the component: attribute `focalPoint`, attribute `allowedVideoProviders`.<br>A custom duotone is left out. Preset duotones convert. ([ADR 0004](adr/0004-parity-exceptions.md))<br>(Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/comments` | 81.6% | Block plugin `wordpal_comments` | `comments` | The comment form is the Drupal comment form. Its field names, hidden inputs and wrappers differ, and it has no cookie-consent checkbox. ([ADR 0004](adr/0004-parity-exceptions.md))<br>The comment form and reply links show only to visitors with the `post comments` permission.<br>A comment block whose values no component or Frozen block accepts stops the conversion. |
| `core/spacer` | 81.3% | Component `spacer` |  |  |
| `core/post-comments-form` | 79.7% | Block plugin `wordpal_comments` | `comments` | Shows only to visitors with the `post comments` permission. |
| `core/comments-title` | 77.6% | Part of `core/comments` |  |  |
| `core/avatar` | 73.0% | Component `avatar` | `post` | Prints an image only when the author has a Drupal user picture. WordPress falls back to Gravatar. ([ADR 0004](adr/0004-parity-exceptions.md))<br>A custom duotone is left out. Preset duotones convert. ([ADR 0004](adr/0004-parity-exceptions.md)) |
| `core/comment-author-name` | 72.3% | Part of `core/comments` |  |  |
| `core/comment-content` | 72.3% | Part of `core/comments` |  |  |
| `core/comment-template` | 72.3% | Part of `core/comments` |  |  |
| `core/comment-reply-link` | 72.2% | Part of `core/comments` |  |  |
| `core/comment-date` | 72.1% | Part of `core/comments` |  |  |
| `core/query-no-results` | 72.0% | Component `query-no-results` |  |  |
| `core/comments-pagination` | 70.2% | Part of `core/comments` |  |  |
| `core/comment-edit-link` | 69.6% | Part of `core/comments` |  |  |
| `core/comments-pagination-next` | 69.6% | Part of `core/comments` |  |  |
| `core/comments-pagination-previous` | 69.6% | Part of `core/comments` |  |  |
| `core/categories` | 65.7% | Block plugin `wordpal_categories` | `category` | Each dropdown option links to its Drupal URL. ([ADR 0004](adr/0004-parity-exceptions.md))<br>`showEmpty` lists the empty terms of the Drupal vocabulary, which can differ from the WordPress site. ([block-matrix-2026-09-28](plans/block-matrix-2026-09-28.md)) |
| `core/separator` | 65.7% | Component `separator` |  |  |
| `core/comments-pagination-numbers` | 63.3% | Part of `core/comments` |  |  |
| `core/post-author` | 59.6% | Component `post-author` | `post` | Prints an avatar image only when the author has a Drupal user picture. ([ADR 0004](adr/0004-parity-exceptions.md))<br>Links the author name only for visitors with the `access user profiles` permission. ([ADR 0004](adr/0004-parity-exceptions.md))<br>A custom duotone is left out. Preset duotones convert. ([ADR 0004](adr/0004-parity-exceptions.md)) |
| `core/navigation-link` | 56.0% | Part of `core/navigation` |  |  |
| `core/tag-cloud` | 50.3% | Block plugin `wordpal_tag_cloud` | `tag` |  |
| `core/site-logo` | 50.0% | Block plugin `wordpal_site_logo` |  |  |
| `core/post-author-name` | 49.9% | Component `post-author-name` | `post` | Links to the author page only for visitors with the `access user profiles` permission. ([ADR 0004](adr/0004-parity-exceptions.md)) |
| `core/post-navigation-link` | 45.6% | Block plugin `wordpal_adjacent_post` | `post` |  |
| `core/archives` | 43.1% | Block plugin `wordpal_archives` |  | Each dropdown option links to its Drupal URL. ([ADR 0004](adr/0004-parity-exceptions.md)) |
| `core/latest-posts` | 42.9% | View, `latest_posts` listing | `post` | On conversion: full post content, rendered as the excerpt. |
| `core/list` | 42.2% | Component `list` |  |  |
| `core/list-item` | 40.7% | Component `list-item` |  |  |
| `core/term-description` | 35.4% | Component `term-description` |  |  |
| `core/read-more` | 27.2% | Component `read-more` | `post` | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/page-list` | 25.8% | View, `page_list` listing | `page` |  |
| `core/html` | 20.8% | Component `html` |  | The HTML text format removes `style` and `on*` attributes, `<script>` tags and tags outside its allow-list. ([ADR 0004](adr/0004-parity-exceptions.md)) |
| `core/site-tagline` | 20.0% | Block plugin `wordpal_site_tagline` |  |  |
| `core/gallery` | 18.9% | Component `gallery` |  | Not in the component: attribute `images`, attribute `ids`, attribute `dynamicContent`, attribute `shortCodeTransforms`, support `units`.<br>The gallery has no `wp-block-gallery-<n>` class. Its gap is layout CSS. ([ADR 0004](adr/0004-parity-exceptions.md)) |
| `core/details` | 18.5% | Component `details` |  |  |
| `core/shortcode` | 16.8% | Frozen |  |  |
| `core/navigation-submenu` | 10.4% | Part of `core/navigation` |  |  |
| `core/media-text` | 8.1% | Component `media-text` |  | Not in the component: attribute `focalPoint`. |
| `core/latest-comments` | 7.7% | View, `latest_comments` listing | `comments` | Drops the avatar when the author has no Drupal user picture. ([ADR 0004](adr/0004-parity-exceptions.md)) |
| `core/post-author-biography` | 5.6% | Block plugin `wordpal_post_author_biography` | `author_biography` |  |
| `core/quote` | 5.6% | Component `quote` |  | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/accordion` | 4.7% | Component `accordion` |  | (Canvas) A link or image URL that holds a `data:` URI freezes the block, because Canvas stores a URL prop as a link field. |
| `core/accordion-heading` | 4.7% | Component `accordion-heading` |  |  |
| `core/accordion-panel` | 4.7% | Component `accordion-panel` |  |  |
| `core/accordion-item` | 4.6% | Component `accordion-item` |  |  |
| `core/home-link` | 4.2% | Part of `core/navigation` |  |  |
| `core/post-time-to-read` | 3.3% | Frozen |  |  |
| `core/loginout` | 2.4% | Frozen |  |  |
| `core/calendar` | 2.3% | Frozen |  |  |
| `core/block` | 1.4% | Frozen |  |  |
| `core/navigation-overlay-close` | 1.4% | Frozen |  |  |
| `core/embed` | 1.3% | Frozen |  |  |
| `core/table` | 1.2% | Frozen |  |  |
| `core/video` | 1.0% | Component `video` |  | Not in the component: attribute `tracks`. |
| `core/icon` | 0.9% | Frozen |  |  |
| `core/preformatted` | 0.7% | Frozen |  |  |
| `core/pullquote` | 0.7% | Frozen |  |  |
| `core/audio` | 0.6% | Frozen |  |  |
| `core/breadcrumbs` | 0.6% | Frozen |  |  |
| `core/post-comments-count` | 0.5% | Frozen |  |  |
| `core/post-comments-link` | 0.5% | Frozen |  |  |
| `core/code` | 0.4% | Frozen |  |  |
| `core/file` | 0.2% | Frozen |  |  |
| `core/term-name` | 0.2% | Frozen |  |  |
| `core/term-template` | 0.2% | Frozen |  |  |
| `core/terms-query` | 0.2% | Frozen |  |  |
| `core/term-count` | 0.1% | Frozen |  |  |
| `core/verse` | 0.1% | Frozen |  |  |
| `core/query-total` | 0.1% | Frozen |  |  |
| `core/tab-list` | 0.1% | Frozen |  |  |
| `core/tab-panel` | 0.1% | Frozen |  |  |
| `core/tab-panels` | 0.1% | Frozen |  |  |
| `core/tabs` | 0.1% | Frozen |  |  |
| `core/math` | 0.1% | Frozen |  |  |
| `core/more` | 0.1% | Frozen |  |  |
| `core/nextpage` | 0.1% | Frozen |  |  |
| `core/playlist` | 0.1% | Frozen |  |  |
| `core/playlist-track` | 0.1% | Frozen |  |  |
| `core/rss` | 0.1% | Frozen |  |  |
| `core/footnotes` | 0.0% | Frozen |  |  |
| `core/freeform` | 0.0% | Frozen |  |  |
| `core/legacy-widget` | 0.0% | Frozen |  |  |
| `core/missing` | 0.0% | Frozen |  |  |
| `core/page-list-item` | 0.0% | Frozen |  | WordPress never saves this block. Page List renders its items. |
| `core/text-columns` | 0.0% | Frozen |  |  |
| `core/widget-group` | 0.0% | Frozen |  |  |

## Differences on every page

- WordPress and Drupal number posts, terms and comments on their own. ID-based classes and attributes differ. ([ADR 0004](adr/0004-parity-exceptions.md))
- Classes and ids WordPress numbers per request, such as `wp-elements-N`, carry other numbers. ([ADR 0004](adr/0004-parity-exceptions.md))
- Dates render in each site's own time zone. ([ADR 0004](adr/0004-parity-exceptions.md))
- WordPress plugin and theme filters on core block output do not run. ([ADR 0004](adr/0004-parity-exceptions.md))
- (Canvas) Inline links in rich text keep only `href`. A saved `rel`, `target` or class drops. ([ADR 0004](adr/0004-parity-exceptions.md))
