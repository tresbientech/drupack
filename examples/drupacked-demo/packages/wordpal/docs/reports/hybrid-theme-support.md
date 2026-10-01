# Hybrid theme support

Date: 2026-09-30. Data: `wp-theme-census` in the WordPress workspace, crawled on 2026-09-24, 15,168 wordpress.org themes.

Question: can a **Conversion** accept a hybrid theme, and what would it produce?

Decision: no. `ThemeSource` keeps rejecting a theme without `templates/index.html`.

## Definition

A hybrid theme has PHP templates and no `templates/index.html`. It ships a `theme.json`, block Template parts in `parts/`, Patterns in `patterns/`, or a mix. The census tiers themes the same way.

## What converts today

A Conversion reads these inputs from a theme:

| Input | Source | Hybrid theme |
|---|---|---|
| Global stylesheet, presets, Style variations | `WP_Theme_JSON_Resolver` | available with a `theme.json` |
| Patterns | the pattern registry | available |
| Template parts | `parts/*.html` | available when the theme ships them |
| Templates | `templates/*.html` | none |

Page frames, the home and 404 pages, the full content templates and the listing Views all come from Templates, through `TemplateResolver::HIERARCHY`. A hybrid theme builds its page layout in PHP. PHP output has no block tree.

## Census counts

Standalone themes only. Child themes of hybrid parents add 251 themes and 55K installs.

| Hybrid shape | Themes | Active installs |
|---|---|---|
| `theme.json` only | 166 | 3.29M |
| Parts or Patterns | 108 | 226K |
| of which PHP calls `block_template_part()` | 47 | 82.6K |

The `theme.json` only group holds Astra, Hello Elementor, Kadence and Blocksy. It has no block trees.

Sydney has 80K of the 82.6K installs in the last row. The remaining themes average under 100 installs each.

## How the parts are used

- Sydney and 43 themes from one author family (`manisha`, `asukla`, `charvaka` and others) keep route bodies in parts: `single.html`, `page.html`, `archive.html`, `404.html`, `search.html`, `index.html`.
- Their headers stay in PHP. The `manisha` family prints the site name and navigation menu from PHP; only the footer is a part.
- Sydney and `tainacan-interface` use their parts only when a theme option turns them on. Both options default to off, so a default render uses none of them.

## Options considered

- Route-named parts as Templates, with the PHP header and footer frozen. Rejected: an editable result reaches about 3K installs, plus Sydney sites that turned the option on.
- Styles and Patterns only, for any `theme.json` hybrid. Rejected: no frames or pages, and Astra's look comes mostly from its own PHP and CSS.
- Freeze each route's PHP render. Rejected: PHP bodies print `the_title()`, the loop and menus, so a frozen copy shows the Demo content on every node.
- Write default block Templates. Rejected: WordPal would invent a layout the theme does not have, and the result fails **Appearance equivalence**.
