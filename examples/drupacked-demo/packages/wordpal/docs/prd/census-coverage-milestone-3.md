# PRD: Census coverage, milestone 3

## Problem Statement

Milestone 2 converts Twenty Twenty-Four, Twenty Twenty-Five and YITH Wonder on Drupal CMS. The September 2026 theme census counts 1,966 active block themes on wordpress.org. It shows gaps those three themes did not expose:

- 47.3% of block themes use WooCommerce blocks, 34.8% outside WooCommerce's shop templates. These convert as Frozen blocks without the plugin's CSS, so carts and product grids print unstyled.
- 16.3% use blocks from another plugin, such as Contact Form 7 or Blockspare. They freeze unstyled too.
- A Navigation block holding `navigation-submenu` (187 themes) or `home-link` (68 themes) drops its own links and renders the mapped menu instead.
- `avatar` appears in 73.0% of block themes and freezes outside comments. `details` (18.5%), `latest-comments` (7.7%), `quote` (5.6%) and the four accordion blocks (4.7%) also freeze.
- 71.7% of block themes ship JavaScript. Convert exports theme stylesheets but no theme scripts.
- Milestone 2 accepted three themes. 767 themes share their template structure with another theme, in 203 groups, and none of the largest groups was tested.

## Solution

Convert exports the CSS each plugin block needs, and a Frozen block attaches it where the block appears. Plugin scripts stay out: a Frozen block holds conversion-time data and cannot call WordPress endpoints.

Convert exports the scripts the theme enqueues when every dependency has a Drupal library. It skips and reports the others.

The core block library adds `avatar`, `details`, `quote`, `latest-comments` and the accordion group. Navigation blocks keep their submenus and Home link.

Acceptance themes: one theme from each of the five largest census groups, and Twenty Twenty-Four as the `theme.json` version 2 theme.

## User Stories

### Plugin blocks

1. As a site builder, I want a Frozen plugin block to keep the plugin's styling, so that a WooCommerce header matches WordPress.
2. As a site builder, I want only the plugin CSS a page's Frozen blocks need, so that pages without plugin blocks stay light.
3. As a site builder, I want the plugin CSS to ship in the generated theme, so that production still needs only `wordpal` and the theme.
4. As a site builder, I want the images and fonts plugin CSS references copied with it, so that icons and backgrounds render.
5. As a site builder, I want the report to list the plugin stylesheets each Frozen block carries, so that I know what the theme ships.
6. As a site builder, I want the report to say plugin scripts are not exported, so that I expect a static cart or form.
7. As a site builder, I want plugin CSS exported through WordPress's own block asset loading, so that the files match what WordPress prints.
8. As a maintainer, I want every exported plugin file to be an Owned output, so that `--replace` removes it.

### Theme scripts

9. As a site builder, I want the scripts my theme enqueues to run on Drupal, so that sliders, toggles and scroll effects work.
10. As a site builder, I want a theme script that needs jQuery to use Drupal core's jQuery, so that the page loads one copy.
11. As a site builder, I want a script whose dependency has no Drupal library skipped and named in the report, so that nothing fails silently.
12. As a site builder, I want a script depending on a skipped script skipped too, so that no script runs without its dependency.
13. As a site builder, I want theme scripts attached on every page, as WordPress enqueues them, so that behavior matches.
14. As a maintainer, I want only files inside the theme directory exported, so that convert copies no core or plugin script.

### Core blocks

15. As a site builder, I want `avatar` to show the author's Drupal user picture, so that author cards keep their image.
16. As a site builder, I want `avatar` to print nothing for a user without a picture, so that no broken image appears.
17. As a site builder, I want `details` to convert with its summary and inner blocks, so that FAQ sections work without JavaScript.
18. As a site builder, I want `quote` to convert with its citation, so that testimonial patterns keep their text.
19. As a site builder, I want `latest-comments` to list recent comments when my mapping binds comments, so that sidebars match WordPress.
20. As a site builder, I want `latest-comments` dropped with a report line under the default mapping, so that it behaves like the other comment blocks.
21. As a site builder, I want the accordion blocks to open and close as on WordPress, so that FAQ patterns work.
22. As a Canvas editor, I want to edit the text of new static blocks in Canvas, so that I change FAQ and quote content.

### Navigation

28. As a site builder, I want a Navigation block's submenus converted into nested menu links, so that dropdowns keep their items.
29. As a site builder, I want submenus to open and close as on WordPress, so that the header works on hover, click and keyboard.
30. As a site builder, I want the mapped menu's nested links printed as submenus, so that my own Drupal menu gets WordPress's dropdowns.
31. As a site builder, I want a Home link converted into a menu link to the front page, so that the header keeps it.

### Custom HTML and shortcodes

23. As a Canvas editor, I want to edit a Custom HTML block through a filtered text format, so that I change it and no editor can enter script.
24. As a maintainer, I want `core/shortcode` to stay a Frozen block, so that convert adds no shortcode runtime.

### Acceptance

25. As a maintainer, I want each of the five largest theme groups converted, so that coverage reaches the most-copied theme structures.
26. As a maintainer, I want Twenty Twenty-Four rerun as the `theme.json` version 2 theme, so that milestone 2 results hold.
27. As a maintainer, I want the WooCommerce acceptance themes converted with `--plugin=woocommerce`, so that plugin CSS is tested on real headers.

## Implementation Decisions

### Modules

- `wordpal` gets the new block components and block plugins, and the accordion view module.
- `wordpal_convert` gets the snapshot changes and the script dependency resolver.
- `wordpal_canvas` gets the theme writer and report changes.

### Plugin CSS for Frozen blocks

- The Playground snapshot records the style handles WordPress enqueues while it renders each Frozen block. It records them in the block render index, keyed like the block's HTML.
- It exports each handle's file from the plugin directory, with the assets its `url()` references. Theme style export already does this for theme files.
- The generated theme declares one library per plugin stylesheet.
- A Frozen block attaches its libraries, looked up by its index key in the generated theme. The key is not a Canvas prop, since ADR 0002 keeps props flat and editable.
- Plugin scripts, view scripts and script modules are never exported. ADR 0007 gains this rule.
- Per-block capture was chosen over all plugin front-end styles. WooCommerce's site-wide styles add several hundred KB to every page.

### Theme scripts

- After `wp_enqueue_scripts`, the snapshot lists queued scripts whose file lies inside the theme directory, with their WordPress dependency handles.
- A script dependency resolver decides each script's fate. A dependency maps to a Drupal library when it is `jquery` (core's jQuery), another exported theme script, or absent.
- A script with any other dependency is skipped with its reason. A script depending on a skipped script is skipped too.
- The generated theme declares one library per exported script and attaches them on every page. The order follows WordPress's print order.
- A new ADR 0011 records the dependency rule. Vendoring WordPress handles such as `wp-dom-ready` was rejected: it ships WordPress code outside the pinned component set.

### Core block library

- `details` and `quote` are static components with Twig matching `save()`.
- The four accordion blocks are static components. The accordion view module is copied and declared per ADR 0009, and attached by the block that prints its directives.
- `avatar` is a component whose author id binds to the post's owner, as Post Author's does. A block plugin was rejected: a block plugin inside a Query layout fails that Query's tree, and author cards sit in Query loops. It prints the author's `user_picture` and no image when the picture is empty. Inside a comment it stays a marker of the comment runtime.
- `latest-comments` is a block plugin listing the newest published comments of the mapped comment field, access checked, when the mapping binds comments. Under a mapping that drops comments, it renders nothing and the report names the dropped concept.
- `html` is a component whose HTML renders through the WordPal HTML text format, which the `wordpal` module ships. The format's HTML filter keeps layout tags, classes, images, SVG and iframes, and drops `style`, `on*` attributes and script. Drupal CMS's `content_format` was rejected: it strips `div`, `span`, `img` and `iframe`, so typical Custom HTML renders empty.
- `shortcode` stays a Frozen block.

### Navigation

- `navigation-submenu` becomes a menu link with child links, in the menu that holds the block's own links. `home-link` becomes a menu link to the front page.
- A menu link written for a Home link carries a link option, so the Navigation block prints WordPress's Home link markup for it.
- The Navigation block prints nested menu links with the submenu markup and directives WordPress prints. The navigation view module copied per ADR 0009 already handles them.
- A Navigation block holding any other inner block still renders the mapped menu, and the report says so.

### Report

- The report adds the plugin stylesheets per Frozen block.
- The report adds each skipped theme script with the dependency that has no Drupal library.
- The report states once that plugin scripts are not exported.

### Parity exceptions

ADR 0004 gains one scoped bullet: `avatar` prints no Gravatar fallback, since Drupal core has no Gravatar service.

### Acceptance themes

One theme per census group, the highest-install member that is not a child theme:

| Group size | Theme | Plugin |
|---|---|---|
| 42 | `blockskit-base`, parent of every theme in the group | WooCommerce |
| 25 | `buildapp` | none |
| 22 | `charity-organization` | WooCommerce |
| 17 | `patterns-political` | none |
| 16 | `gutentools` | WooCommerce |

Twenty Twenty-Four reruns as the `theme.json` version 2 theme.

## Testing Decisions

A test checks behavior through the public interface: rendered markup, declared libraries, report lines. It does not assert on private methods or intermediate arrays.

- The script dependency resolver gets unit tests: no dependency, `jquery`, a theme script chain, an unmapped dependency, and a chain broken by a skipped script.
- The theme writer gets kernel tests for plugin style libraries and theme script libraries.
- A kernel test renders a Frozen block and checks that it attaches its plugin style libraries, and that a page without it does not.
- Each new block gets one kernel render test. Prior art: the milestone 2 per-block render tests.
- Menu collection gets tests for submenus and the Home link. The Navigation block gets a render test for nested links. Prior art: `NavigationLinkBlockTest`.
- The report test extends to the new lines. Prior art: `ConversionReportTest`.
- Snapshot changes are verified by live `--refresh` runs. PHPUnit cannot run Playground.
- Parity and screenshots cover each acceptance theme within ADR 0004.

## Out of Scope

- Child themes, including the 346 active block child themes
- A first tagged release and the QA command
- Plugin scripts and view modules for Frozen blocks
- Vendoring WordPress script handles for theme scripts
- A `core/shortcode` runtime
- `render_block` filters from themes, which stay ADR 0004 exceptions
- A Gravatar fallback for `avatar`
- The image lightbox, deferred from milestone 2

## Further Notes

- Terms follow `CONTEXT.md`. Decisions: ADR 0004, 0007, 0009 and the new 0011.
- Census data: `wp-theme-census/out/` in the WordPress workspace, crawled on 2026-09-24. Figures cover the 1,966 active block themes.
- Three of the five acceptance themes use WooCommerce blocks. They test plugin CSS on the header and footer Template parts that milestone 2 froze unstyled.

Risks:

- A plugin stylesheet may select on body classes or markup that Drupal pages do not print.
- A theme script may read WordPress globals such as `wp` or a localized object. It then fails in the browser, although its dependencies resolved.
