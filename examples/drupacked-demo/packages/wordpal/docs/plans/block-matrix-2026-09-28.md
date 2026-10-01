# Plan: block conformance matrix, then theme probes

> Source: session of 2026-09-28. Question: find render gaps from the WordPress block spec before converting themes.

## Why

The global stylesheet and block props already come from WordPress: ADR 0001 runs WordPress for `theme.json`, and `wordpal:generate-components` reads each core `block.json`. Rendered markup does not. Component templates are written by hand, and gaps show up only when a converted theme uses an attribute. `ComponentRenderTest` checks 13 components against three Twenty Twenty-Four patterns.

The matrix renders every core block over the attribute space its `block.json` declares, in WordPress and in Drupal, and compares the two. Theme probes then measure what the matrix cannot hold: older saved formats and theme render filters.

## Decisions

- The matrix covers the core blocks of WordPress 7.1.2 that have a generated component. Frozen blocks stay out.
- Cases vary one factor at a time from the `block.json` defaults:
  - an enum attribute gets one case per value
  - a boolean attribute gets its non-default value
  - a string or number attribute gets one sample value
  - a support gets one case with a Twenty Twenty-Five preset and one with a custom value
  - a layout support gets one case per layout type
- Static blocks get their saved markup from `wp.blocks.serialize()` in Node, with core blocks registered. The script loads the bundles in `wp-includes/js/dist` of the verified WordPress 7.1.2 zip into jsdom. WordPress 7.1.2 builds from a Gutenberg commit and pins no `@wordpress/*` npm versions, and npm resolves newer transitive packages. jsdom is the only npm dependency. The markup is the current save, matching the current-save-only rule.
- Dynamic blocks need no saved markup. Playground renders them from the block comment alone.
- Blocks that read post context render against the canonical single node, "A walk through the city".
- The Drupal side runs on a site converted from Twenty Twenty-Five with sample content and the Phase 7 mapping, so presets, context and mapped blocks resolve as they do in a theme. The command takes the mapping file with `--mapping`, since a conversion records none.
- `MarkupComparator` compares each case, with the ADR 0004 exceptions. A new difference gets fixed or added to ADR 0004. The comparator never grows a case-specific skip.
- The report lists each failing case with its block, the factor it varies and the difference lines. It is written outside the repository and never committed.

## Phase 1: tracer on two blocks

### What to build

One drush command runs the matrix for named blocks. It builds cases from `block.json` and serializes static cases in Node. It renders both sides and prints the report. The tracer covers `core/site-title` (dynamic) and `core/paragraph` (static).

### Acceptance criteria

- [x] The command runs both blocks end to end on the Twenty Twenty-Five site. `wordpal:block-matrix /node/4 paragraph site-title` renders 74 cases in 38 seconds.
- [x] The report gives the case count and the failing cases per block. Paragraph: 39 cases, 5 differ. Site Title: 35 cases, 9 differ.
- [x] A unit test covers case generation from a fixture `block.json` (`CaseGeneratorTest`).
- [x] Node serialization loads the WordPress 7.1.2 bundles from the verified release zip (`BlockSerializer`, `serialize.cjs`).

## Phase 2: all dynamic blocks

### What to build

Run the matrix over every dynamic core block with a component, context blocks included.

### Acceptance criteria

- [x] Every dynamic block has a report row, including zero-difference blocks.
- [x] Differences are grouped into named causes in this plan, each marked fix or ADR exception.

## Phase 3: all static blocks

### What to build

Run the matrix over every static core block with a component.

### Acceptance criteria

- [x] Every static block has a report row.
- [x] Differences are grouped into named causes in this plan, each marked fix or ADR exception.

## Findings of the first full run

`wordpal:block-matrix /node/4` over 53 blocks, the 44 components and the 9 block plugins: 1656 cases, 418 differ.

### Harness causes

These cases fail because of the matrix itself. Fixing them comes first, because they hide render gaps.

| Cause | Cases | Blocks | Fix |
|---|---|---|---|
| The fixture path writes a plain content template, which turns no Query loop into a View. | 126 | Post Template, Query No Results, Query Pagination and its three children | Render cases under a Query through the query content template path. |
| A free string gets "Matrix text" where WordPress expects a preset slug, a CSS length or a keyword. | about 40 | Button, Cover, Image, Featured Image, Navigation, Post Date, Tag Cloud, Search and others | Sample by attribute role: a color or gradient attribute takes a preset slug, a length takes `200px`. Skip and report a free string with no known role. |
| The matrix site maps no category vocabulary and no author biography. | 70 | Categories, Post Author Biography | Run the matrix on a site set up with the Phase 7 mapping. |
| A Navigation with no `ref` makes WordPress create a fallback menu on each render, with a counter in its label. | 44 | Navigation | Give Navigation cases a `ref` to the fixture navigation. |

### Render gaps

| Gap | Blocks | Census themes of the widest block |
|---|---|---|
| `textColumns`, `textIndent`, `fitText` and the `direction` attribute | Paragraph, Heading, Post Excerpt | 3446 |
| The `shadow` support prints no `box-shadow` | Group, Column, Columns, Button, Cover, Image, Featured Image, Accordion blocks | 3230 |
| `color.button` prints no `wp-elements` class | Group, Column, Columns | 3230 |
| Button details: `type="button"`, the anchor on the wrapper, `dimensions.width`, `writingMode` on the wrapper | Button | 2869 |
| Border and shadow belong on the `img`, with `has-custom-border` on the figure | Image, Featured Image | 2739 |
| The `filter.duotone` support prints no `wp-duotone` class | Avatar, Cover, Post Author, Featured Image, Image | 2562 |
| Block supports missing from block plugins: anchor, align, border, spacing, typography, color | Navigation, Site Title, Search, Categories, Tag Cloud, Archives | 2311 |
| Search typography on the label, input and button | Search | 2246 |
| The `background` support on blocks other than Group and Cover | Post Content, Quote, Accordion | 2439 |
| One-case gaps: Buttons `has-custom-font-size`, List `start` and `reversed`, Video `width` and `height`, Cover `aspectRatio`, Group sticky position, Media & Text featured image, Separator `tagName` and gradient, Columns flex container class, Accordion `ariaLabel`, Image `isDecorative`, Featured Image overlay | various | 1843 or fewer |

No finding needs an ADR 0004 exception yet.

## Phase 4: matrix fixes

### What to build

Fix the causes from phases 2 and 3 in batches. A batch is ordered by the census block count of the blocks it clears. The harness batch comes first, because harness failures hide render gaps.

1. The harness causes. Then run the matrix again and update the gap table.
2. Paragraph, Heading and Post Excerpt typography.
3. Shadow and `color.button` on the layout blocks.
4. Button details.
5. Image and Featured Image border, shadow and duotone.
6. Block supports on the block plugins, Search typography included.
7. The `background` support and the one-case gaps.

### State on 2026-09-28

Branch `block-matrix`. The matrix site is the Display Builder site, which mounts the worktree. The run command:

```
ddev drush wordpal:block-matrix /node/<nid> <blocks> --mapping=<mapping file>
```

`<nid>` is the node of "A walk through the city", 28 since the batch 7 reconvert. Post Author Biography stays out, because the Phase 7 mapping leaves `author_biography` unmapped. Run `drush cr` after a Twig edit, and never edit the mount while a run is going.

| Batch | Commits | State |
|---|---|---|
| 1. Harness | `2c58261` | done |
| 2. Typography | `2c58261` | done |
| 3 and 4. Shadow, `color.button`, Button | `2c58261` | done |
| 5. Image, Featured Image, pagination, Post Terms suffix | `2c58261` | done |
| 6. Block plugins | `2c58261` | done |
| 7. `background`, position and duotone supports, one-case gaps | `2c58261`, lanes `bm-a`, `bm-b`, `bm-c` | done |

Full runs of 1613 cases:

| After | Cases differing |
|---|---|
| batch 4 | 311 |
| batch 6 | 41 |
| batch 7 lanes merged | 21 |
| batch 7 | 2 |

The batch 7 run reported 5: three single cases in Accordion, Social Icons and Site Title returned the whole node, and all three pass on a rerun. The 2 left are the accepted differences below.

Each block plugin keeps its support props in one `supports` setting. `wordpal:generate-components` writes their names per block to `components/block-supports.json`, and `BlockPluginInputs` reads that file.

Open items:

- Search with the button inside: the wrapper radius `calc(radius + padding)`.

Accepted differences:

- Pagination Numbers `midSize`: ADR 0004, Drupal's pager window.
- Categories `showEmpty`: WordPress's demo content has an empty category the Drupal site lacks. The case compares site data, not rendering.

Found during batch 7, outside the matrix:

- `ComponentRenderTest`'s unsafe Button tag case fails on main too: the kernel test gets no component validation error. The template still falls back to `a`.
- `TreeConcepts::concept()` maps every Categories block to the `category` concept, whatever its `taxonomy`.
- A second theme cannot convert to Canvas on a site that holds another theme's conversion. The Blog and Page content templates belong to the first theme, and `--replace` refuses them as unmanaged. `ConversionOwnership::takeOverFullDisplays()` hands over full displays only.

### Acceptance criteria

- [ ] The matrix reports zero differences outside ADR 0004.
- [ ] Each fix has a test at the component level.
- [ ] Display Builder parity stays at 0 on Twenty Twenty-Five, YITH Wonder and Twenty Twenty-Four.

## Phase 5: theme probes after the matrix

### What to build

Convert the 15 unseen themes of the 2026-09-28 probe again to Canvas with the Phase 7 mapping. Compare the result with the baseline taken before the matrix. Group what remains into three kinds:

- older saved formats, which go to the comparator's current-save step
- theme render filters, which become ADR 0004 exceptions
- render gaps the matrix missed, which become new matrix factors

### Acceptance criteria

- [x] Every theme has its parity lines before and after the matrix.
- [x] Every remaining line has a named cause and a fix or exception decision.
- [x] A render gap the matrix missed adds a factor to the matrix before it gets fixed.

### State on branch `probe-phase5`, 2026-09-30

Owner rulings:

- Markup parity keeps reporting an element Drupal adds. It stops reporting an extra class or an extra attribute. A visual check of five themes found visible defects from Drupal-only elements (a pager, the messages container, the Frozen outline) and none from an extra class.
- The Frozen mark's dashed outline shows to every visitor (ADR 0007).
- Test and probe sites grant anonymous visitors `post comments`, so the comment form and reply links compare. A conversion never grants it.
- Canvas's inline format dropping `<mark>`, `<span>` and inline styles stays a reported difference.
- Gutenverse prints its block CSS inline, built in `wp_head` from the whole template. Frozen blocks record plugin CSS files only. Recording inline plugin CSS is a separate change, with an ADR 0007 amendment.

The probe site's comment type had no form widget, and anonymous visitors could not leave contact details. The site setup now matches a WordPress default: name and email required.

### After the matrix

Canvas target, Phase 7 mapping, branch at `2cd0a59`. Each cell is the count of difference lines on one route. Every theme converts. Saaslauncher has no baseline lines: its conversion failed before the matrix, as the baseline section records.

| Theme | Frozen | home | single | page | archive | search | 404 | page 2 | empty search |
|---|---|---|---|---|---|---|---|---|---|
| twentytwentythree | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| variations | 21 | 0 | 7 | 0 | 6 | 3 | 0 | 0 | 1 |
| unibiz | 41 | 7 | 10 | 0 | 12 | 1 | 0 | 5 | 9 |
| jadro | 0 | 4 | 1 | 1 | 1 | 1 | 1 | 4 | 1 |
| greenshift | 44 | 16 | 2 | 2 | 2 | 2 | 2 | 16 | 2 |
| bizboost | 6 | 3 | 10 | 2 | 3 | 2 | 2 | 3 | 2 |
| zeever | 52 | 7 | 0 | 1 | 12 | 4 | 0 | 6 | 8 |
| saaslauncher | 12 | 22 | 7 | 2 | 3 | 3 | 4 | 22 | 3 |
| idea-flow | 0 | 2 | 2 | 0 | 2 | 5 | 1 | 2 | 3 |
| tourze-lite | 32 | 8 | 9 | 2 | 13 | 5 | 0 | 9 | 9 |
| simple-nova | 0 | 0 | 3 | 0 | 0 | 5 | 0 | 0 | 5 |
| frost | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| riverbank | 0 | 0 | 2 | 0 | 0 | 0 | 0 | 0 | 0 |
| bjork | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| zino | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |

### Causes and decisions

Fixed on this branch:

| Cause | Themes |
|---|---|
| The comment body renders unescaped plain text | tt23, variations, bizboost, idea-flow |
| A Frozen block accepts a lone UTF-8 `<meta charset>` | zeever |
| Drupal routes get no frame when Post Content sits in a Frozen block | tourze-lite |
| An inheriting Query ignores its own paging, filters and order | bjork, variations |
| A template of parts alone takes its uncategorized part as content | zino |
| Comments print nothing when closed with none, Post Terms nothing without terms | tt23, simple-nova, idea-flow, bizboost |
| Contract: a class or attribute only Drupal prints drops out, a video poster compares for presence | all |
| Older save: a Cover overlay named by its gradient class | bizboost |
| ADR 0004 exception: loading hints on a Frozen block's images | zeever, unibiz, tourze-lite |
| ADR 0004 exception: cozy-addons' Navigation list class and custom properties | saaslauncher |
| Search prints its button when `buttonPosition` is `no-button` | variations |
| Contract: a Frozen block's older save compares as the current save | greenshift |
| The Global stylesheet keeps theme.json styles of a core block without a front-end style, which WordPress never prints | twentytwentythree |
| Core's off-canvas page wrapper and the skip-link target change the layout of `.wp-site-blocks` | riverbank |
| Conversion reads the theme's template files, where a plugin serves its own content under the theme's slugs | unibiz, zeever, tourze-lite |
| Post Navigation Link drops its typography and color supports | bjork, greenshift, saaslauncher, unibiz, variations |
| Cover over the featured image ignores its focal point | saaslauncher |
| Legacy Comments blocks, which the parser now names `core/comments`, no longer match their component | bizboost |
| Drupal's messages placeholder and skip-link anchor sit between the header part and the content. A theme's adjacent-sibling rule such as `header:has(+ .wp-block-cover)` fails, and the header no longer overlays the hero | variations |
| The matrix varies no flex child size, so an Image's `selfStretch: "fixedNoShrink"` stopped the conversion | saaslauncher |

Open, each a fix unless it says otherwise:

| Cause | Themes | Matrix factor |
|---|---|---|
| The background gradient support (`style.background.gradient`) is dropped | saaslauncher | `background.gradient custom`, `background.gradient preset` |
| An empty `blockGap` prints no layout container class | variations | `spacing.blockGap empty` |
| A template's content Group sits next to an uncategorized part holding the Query. The part goes to the frame, and its Post Template prints sample rows without `<li>` | idea-flow | none: template frame, a `ThemeTrees` test |
| A Query holding two Pagination blocks is rejected | simple-nova | none: conversion rule, a `QueryLoop` test |
| A Frozen block with several root elements marks only the first, so the outline and the Frozen exceptions miss the rest | tourze-lite | none: Frozen HTML, a Twig filter test |
| A spacing side the support does not list, such as Group and Columns margin left and right | jadro, greenshift | `spacing.<box> undeclared sides` |
| Block visibility prints no `wp-block-hidden-*` class | saaslauncher | `visibility hidden`, `visibility viewport mobile` |
| Duotone `unset` prints no class | variations | `filter.duotone unset` |
| Image `focalPoint` with `scale` prints no `object-position` | saaslauncher | `focalPoint with scale="cover"` |
| A hover-only link color is dropped | saaslauncher | `color.link hover` |
| A static save prints `has-link-color` for a link color the block has no prop for: an empty color object, or Button, whose color support has no `link` key | greenshift, idea-flow | `color.link empty` |
| A number saved as a numeric string: the editor's parser drops it, WordPress's PHP render keeps it (Query Title `level`) | bizboost | `<name>="<n>"` for number attributes |
| Button's top-level `textAlign`, deprecated in WordPress 7.0, is lost | greenshift | none: the editor migrates it, conversion should too |
| Comment child blocks lose their own typography and spacing | variations, idea-flow | the full matrix now lists these blocks |
| Query No Results loses its font size on an empty search listing | variations | fix: add a case that renders Query No Results inside an empty listing |
| A legacy `core/post-comments` list prints the current comment markup; the visual check shows it degraded | bizboost | none: legacy block |
| A saved image `border-style:none` is dropped | saaslauncher | none: no computed-style change |
| Search with the button inside: wrapper radius `calc()` | idea-flow, saaslauncher | fix, carried from Phase 4 |

Open, not fixes:

| Cause | Themes | Decision |
|---|---|---|
| Canvas's inline format drops `<span>`, `<mark>`, and inline styles on `img` and `a` | jadro, bizboost, greenshift, variations | owner ruling: stays reported |
| Gutenverse inline CSS is missing from Frozen blocks | unibiz, zeever, tourze-lite | owner ruling: separate change |
| A Frozen query or post block prints what WordPress listed at capture: Gutenverse post lists and single-post fields, WooCommerce's product collection | zeever, unibiz, tourze-lite, bizboost | ADR 0007: Frozen HTML is static |
| Search results list in another order | every theme with a search listing | ADR 0004 exception |
| Per-block `@mobile`, `@tablet` and `:hover` styles (the 7.1 states support) are dropped | none: source review | feature, not built |
| Viewport layout overrides (`style["@mobile"].layout`) are dropped | none: source review | feature, not built |
| Per-block custom CSS (`style.css`) is dropped | none: source review | feature, not built |
| Grid child placement (`columnStart`, `columnSpan`, `rowStart`, `rowSpan`) is dropped | none: source review | feature, not built |
| A grid ignores `autoFit` and `rowCount` | none: source review | feature, not built |
| Element colors past link text, heading text and button text and background are dropped: heading background and gradient, `h1` to `h6`, button gradient | none: source review | feature, not built |
| Group `minWidth` is dropped | none: source review | feature, not built |
| Video text tracks are dropped | none: source review | feature, not built |
| Gallery `randomOrder` keeps the saved order | none: source review | feature, needs a per-request cache rule |
| Post Date's `human-diff` format prints PHP date letters | none: source review | feature, needs a cache max-age |
| Post Featured Image ignores `useFirstImageFromPost` | none: source review | feature, not built |
| A `dynamicContent` Gallery prints an empty wrapper | none: source review | out of policy: attachment query |
| Video prints no width, height or aspect ratio from its attachment | none: source review | out of policy: media library |

### Visual check

Markup parity cannot see CSS. A screenshot run captures each probe route on both sides at 1280px, with the computed styles of the frame elements and post titles. It found two CSS gaps at 0 parity lines:

- Twenty Twenty-Three's Post Template titles rendered at 36px against 52px. The Global stylesheet held a `core/query` h2 rule that WordPress never prints.
- Riverbank's `main` sat 24px lower, and a short page's footer lost its `margin-block-start: auto`. Drupal's page chrome caused both.

Both are fixed. The run over all 15 themes found a third, now fixed: Variations' header no longer overlaid its hero (the Drupal chrome row above). The remaining pixel differences have a named cause:

- Search order moves a featured image to another column. The ADR 0004 exception holds, and the owner can revisit it.
- A legacy flex Post Template renders as a grid, which the comparator rewrites as an older save. Rows drift 2 to 5px.
- The screenshot run's WordPress side prints no Post Content on Riverbank's home, where the probe's Reference render does. The two WordPress renders differ, so this is a harness difference.
- The site title differs: the probe site keeps Drupal's site name.
- Greenshift's hero sits about 50px lower, and its icon boxes show an outline. No parity line covers either, and neither is traced yet.

Recommendation: a full-page computed-style comparison would catch this class of gap in the probe. It is a follow-up.

The ADR 0004 bullet on legacy comment items has no comparator code. It goes when the legacy fix lands.

### Full matrix before merge

Twenty Twenty-Four, P7 mapping, anonymous `post comments` granted, default block list. 2513 cases on 70 blocks, 266 differ.

The 52 blocks of the Phase 3 run show no regression. 13 of their cases differ in this run and not in Phase 3; each passes when run alone with `--case`.

The rest falls into these groups. The counts overlap: a `visibility hidden` case also counts in its block's group.

- New factors, each an open fix in the tables above: `visibility hidden` (35 cases), `visibility viewport` (3), `spacing.margin undeclared sides` (9), `background.gradient` (8), `spacing.blockGap empty` (4), `color.link hover` and `color.link empty` (6), flex child sizes (11), numeric-string attributes.
- Comment child blocks lose their own supports: Comment Author Name, Comment Date, Comment Content, Comment Template, Comments Title (51 cases). This is the open "comment child blocks" row.
- Comment Reply Link and the Comments Form print a `<drupal-render-placeholder>` in the matrix render, so every case differs (68 cases). The matrix renders a case without replacing Drupal's placeholders. Harness fix.
- Comments ignores `tagName="section"`. New open fix.
- Post Author Biography prints nothing on Drupal (36 cases). Not traced yet.
- Home Link, Navigation Link and Navigation Submenu: the Navigation's height changes with the item's font size and label (27 cases). Not traced yet.

Phase 4's first acceptance box stays open until these groups are fixed or excepted.

## Baseline: the 15-theme probe before the matrix

Canvas target, Phase 7 mapping, `main` before the block matrix branch. Each cell is the count of difference lines on one route.

| Theme | Plugins | Frozen | home | single | page | archive | search | 404 | page 2 | empty search |
|---|---|---|---|---|---|---|---|---|---|---|
| twentytwentythree | | 0 | 0 | 3 | 1 | 0 | 0 | 0 | 0 | 0 |
| variations | woocommerce, wpforms-lite | 5 | 4 | 13 | 4 | 5 | 13 | 9 | 4 | 11 |
| unibiz | gutenverse | 20 | 37 | 13 | 9 | 13 | 11 | 13 | 37 | 11 |
| jadro | | 0 | 4 | 2 | 1 | 1 | 1 | 1 | 4 | 1 |
| greenshift | greenshift, woocommerce | 44 | 147 | 14 | 2 | 5 | 9 | 2 | 147 | 3 |
| bizboost | woocommerce | 6 | 12 | 12 | 4 | 3 | 5 | 3 | 12 | 3 |
| zeever | gutenverse | 17 | 42 | 4 | 7 | 4 | 11 | 3 | 42 | 11 |
| saaslauncher | woocommerce, contact-form-7, cozy-addons | - | failed | | | | | | | |
| idea-flow | | 0 | 2 | 5 | 3 | 4 | 9 | 5 | 2 | 7 |
| tourze-lite | gutenverse | 14 | 41 | 8 | 15 | 15 | 15 | 5 | 41 | 15 |
| simple-nova | | 0 | 3 | 3 | 3 | 2 | 7 | 2 | 3 | 7 |
| frost | | 0 | 1 | 1 | 1 | 0 | 1 | 1 | 1 | 1 |
| riverbank | | 0 | 2 | 2 | 0 | 0 | 0 | 0 | 2 | 0 |
| bjork | | 0 | 5 | 4 | 2 | 5 | 5 | 2 | 5 | 5 |
| zino | | 0 | 2 | 0 | 0 | 0 | 1 | 1 | 2 | 1 |

The saaslauncher conversion stops on its footer. An Image saves the flex child value `selfStretch: "fixedNoShrink"`, which the component's enum lacks. The matrix varies no flex child style yet, so Phase 5 adds that factor.
