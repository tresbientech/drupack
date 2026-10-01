# Plan: Census coverage, milestone 3

> Source PRD: `docs/prd/census-coverage-milestone-3.md`. Terms: `CONTEXT.md`. Decisions: `docs/adr/0001` to `0010`, and 0011 from phase 2.

## Architectural decisions

Durable across every phase.

Modules:

- `wordpal`: block components, block plugins, Interactivity libraries. No converter code.
- `wordpal_convert`: snapshot, script dependency resolver, theme writer, report.
- `wordpal_evaluate`: parity.
- `wordpal_canvas`: Canvas writer.

Contracts:

- Plugin CSS and theme scripts ship in the generated theme. Production needs only `wordpal` and the theme.
- Every exported plugin or theme file is an Owned output.
- Plugin scripts, view scripts and script modules are never exported.
- The snapshot exports only files that WordPress enqueues. Theme scripts come from the theme directory, and plugin styles from the plugin directory.
- A Frozen block finds its plugin style libraries by its block render index key. The key is not a Canvas prop.
- A theme script exports when each dependency is `jquery`, an exported theme script, or absent.
- `core/html` renders through the WordPal HTML text format. `core/shortcode` stays a Frozen block.

Every phase ends with the suites green, PHPCS clean, and the Canvas site reconverted on Twenty Twenty-Four with parity unchanged.

---

## Phase 1: Plugin CSS for Frozen blocks

**User stories**: 1, 2, 3, 4, 5, 6, 7, 8

### What to build

The snapshot records the style handles WordPress enqueues while it renders each Frozen block, keyed like the block's HTML in the render index. It exports each handle's file from the plugin directory, with its `url()` assets.

The generated theme declares one library per plugin stylesheet. A Frozen block attaches the libraries recorded for it. The report lists the plugin stylesheets per Frozen block, and states once that plugin scripts are not exported. ADR 0007 gains the no-scripts rule.

Tracer: the WooCommerce mini-cart in the `blockskit-base` header, converted with `--plugin=woocommerce`.

### Acceptance criteria

- [ ] The `blockskit-base` header mini-cart renders with WooCommerce's styling and matches WordPress by screenshot
- [x] A page without Frozen plugin blocks loads no plugin stylesheet (`FrozenBlocksTest::testFrozenBlockWithoutPluginStylesAttachesNothing`)
- [ ] Plugin CSS `url()` assets are copied and resolve
- [x] `--replace` removes every exported plugin file (`ConversionRunner::intendedOutputs()` lists plugin files in the manifest)
- [x] The report lists each Frozen block's plugin stylesheets (`ConversionRunner::write()` hands the plugin stylesheets to `ConversionReport`)
- [ ] YITH Wonder's mini-cart is styled, which closes the milestone 2 follow-up
- [x] A kernel test covers the per-block attachment (`FrozenBlocksTest::testFrozenBlockAttachesPluginStyles`)
- [ ] The generated theme's plugin-style libraries have no kernel test; only a unit test covers them (`ThemeGeneratorTest::testDeclaresPluginStyleLibrariesPerFrozenBlock`)

---

## Phase 2: Theme scripts

**User stories**: 9, 10, 11, 12, 13, 14

### What to build

After `wp_enqueue_scripts`, the snapshot lists queued scripts whose file lies in the theme directory, with their dependency handles. A script dependency resolver maps each dependency to a Drupal library or skips the script with a reason. A script whose dependency is skipped is skipped too.

The generated theme declares one library per exported script and attaches them on every page, in WordPress's print order. The report lists skipped scripts with the dependency that has no Drupal library. ADR 0011 records the rule.

### Acceptance criteria

- [ ] An acceptance theme's scripts run on Drupal, and the behavior they drive works
- [x] A script needing jQuery loads core's jQuery, and the page holds one jQuery copy (ThemeScriptsTest::testPagesLoadExportedScripts)
- [x] A script with an unmapped dependency is skipped and named in the report (`ScriptDependenciesTest::testUnmappedDependencySkips`)
- [x] No core or plugin script is exported (`snapshot.php` accepts only theme-URL script sources)
- [x] Unit tests cover the resolver: no dependency, `jquery`, a theme chain, an unmapped dependency, a broken chain (`ScriptDependenciesTest`, five cases)
- [x] A kernel test covers the theme script libraries (ThemeScriptsTest::testPagesLoadExportedScripts)

---

## Phase 3: Details, quote and Custom HTML

**User stories**: 17, 18, 22, 23, 24

### What to build

Static components for `details` and `quote`, with Twig matching `save()` and text editable in Canvas. A `core/html` component renders its HTML through the WordPal HTML text format, which the `wordpal` module ships. `core/shortcode` keeps converting as a Frozen block.

### Acceptance criteria

- [ ] `details` opens and closes without JavaScript and passes parity
- [ ] `quote` with a citation passes parity
- [ ] Canvas accepts both definitions, and their text edits in the Content tab
- [x] `core/html` converts to an editable component, and its output drops script, `on*` and `style` (`CoreBlockLibraryTest::testCustomHtml`)
- [x] A theme using `core/shortcode` still converts it as a Frozen block (`FrozenBlocksTest::testShortcodeFreezesAndHtmlConverts`)
- [x] One kernel render test per block (`CoreBlockLibraryTest::testDetails`, `testQuote`, `testCustomHtml`)

---

## Phase 4: Accordion

**User stories**: 21, 22

### What to build

Components for `accordion`, `accordion-item`, `accordion-heading` and `accordion-panel`. `drush wordpal:generate-components` copies the accordion view module per ADR 0009. `wordpal` declares it as a library, and the block that prints its directives attaches it.

### Acceptance criteria

- [ ] An accordion opens and closes on Drupal as on WordPress
- [x] A page without an accordion loads no accordion script (`CoreBlockLibraryTest::testNoAccordionNoScript`)
- [ ] Each block passes parity against its Reference render
- [x] One kernel render test per block, and one for the library attachment (`CoreBlockLibraryTest::testAccordion`, `InteractivityTest`)

---

## Phase 5: Avatar and latest comments

**User stories**: 15, 16, 19, 20

### What to build

`avatar` is a component whose author id binds to the post's owner, as Post Author's does. It prints the author's `user_picture`, and no image when the picture is empty. The ADR 0004 avatar bullet extends to it.

`latest-comments` is a block plugin listing the newest published comments of the mapped comment field. Under the default mapping it renders nothing, and the report names comments as the dropped concept.

### Acceptance criteria

- [ ] An author card with a user picture passes parity within ADR 0004
- [x] A user without a picture prints no image (`CoreBlockLibraryTest::testAvatar`)
- [x] `latest-comments` lists recent comments under a mapping that binds comments (`ListingViewTest::testLatestCommentsViewListsNewestPublishedComments`)
- [x] Under the default mapping, `latest-comments` renders nothing and the report names comments (`CoreBlockLibraryWriterTest::testLatestCommentsDropsWithoutComments`)
- [x] One kernel render test per block (`CoreBlockLibraryTest::testAvatar`, `ListingViewTest::testLatestCommentsViewListsNewestPublishedComments`)

---

## Phase 6: Navigation submenus and Home link

**User stories**: 28, 29, 30, 31

### What to build

Menu collection accepts `navigation-submenu` and `home-link` among a Navigation block's own links. A submenu writes a parent menu link with its children. A Home link writes a link to the front page with a link option marking it.

The Navigation block prints nested menu links with WordPress's submenu markup and directives, for its own menus and the mapped menu alike. It prints the Home link markup for a link carrying the option.

### Acceptance criteria

- [ ] A theme header with a submenu opens it on hover, click and keyboard as on WordPress
- [ ] The Navigation block passes parity with a submenu and a Home link
- [x] A nested link in the mapped menu prints as a submenu (`NavigationMenusTest::testRendersSubmenusAndHomeLink`)
- [x] Tests cover submenu and Home link collection, and nested rendering (`NavigationMenusTest`)

---

## Phase 7: Acceptance

State: in progress.

**User stories**: 25, 26, 27

### What to build

Run the milestone on the five group themes and Twenty Twenty-Four. Each run uses a reinstalled Canvas site, then convert, then the Site recipe. The WooCommerce themes convert with `--plugin=woocommerce`.

| Group size | Theme | Plugin |
|---|---|---|
| 42 | `blockskit-base` | WooCommerce |
| 25 | `buildapp` | none |
| 22 | `charity-organization` | WooCommerce |
| 17 | `patterns-political` | none |
| 16 | `gutentools` | WooCommerce |

### Acceptance criteria

- [ ] Home and single match WordPress by screenshot on each theme
- [ ] Parity passes on every converted Template within ADR 0004
- [ ] Each report lists every Frozen block, plugin stylesheet, skipped script and dropped concept
- [ ] Each Site recipe applies in one run on a fresh Drupal CMS site
- [ ] Twenty Twenty-Four results match milestone 2

### Acceptance run, 2026-09-25

Twenty Twenty-Four and the five group themes, each converted on the Canvas site
from a fresh `drupal_cms_starter` install with `wordpal:convert --refresh`,
then `wordpal:parity` on `home` and `single` (`--scope=page`).

| Theme | Home diff lines | Single diff lines |
|---|---|---|
| twentytwentyfour | 20 | 7 |
| blockskit-base | 3 | 12 |
| buildapp | 2 | 3 |
| charity-organization | 0 | 17 |
| patterns-political | conversion failed | conversion failed |
| gutentools | 3 | 7 |

Twenty Twenty-Four's 20 home lines and gutentools' and blockskit-base's 3 are
the dropped `category` concept (`category-journal` class, unmapped in the
default Drupal CMS mapping). The other lines are unreviewed structural and
class differences, not triaged into named causes here.

Browser pass on each converted home page (Playwright, the Canvas site): no
console errors on any of the five converted themes. None of the five carries
an accordion or a Navigation submenu on its home page under the default
mapping and demo content, so the accordion and submenu boxes above stay
unproven by this run; nothing failed, there was nothing to exercise.

Follow-ups:

- `patterns-political` never converted: `wordpal:convert patterns-political
  --target=canvas --refresh` failed with `Owned config
  canvas.component.block.views_block.wordpal_patter_26b246f4_listing_3b62631d992b-block_1
  does not exist`. It then threw a `Twig\Error\RuntimeError` rendering the
  Home and Page-not-found canvas pages: `Theme 'mercury' has no WordPal
  settings.` The second error was the first error's rollback:
  `ConversionRunner::write()` activates the generated theme before writing,
  and the recipe-write failure rolled the site back to its prior default
  theme, `mercury`, which has no WordPal settings.

  Cause: `ConversionRunner` calls `CanvasWriter::listingOutputs()` to predict
  the View and Component ids a tree's listing blocks (Latest Posts, Page
  List, Latest Comments) will own. It did not check whether the block's
  content concept is dropped. `CanvasWriter::addNodes()`, the real write
  path, drops such a block before it reaches `saveListingInfrastructure()`
  and creates nothing for it. `patterns-political` places a Latest Comments
  block, and the default Drupal CMS mapping drops `comments`, so the
  ownership manifest named a Component id nothing wrote. Reading it back,
  `SiteRecipeWriter::ownedConfig()` failed.

  Fixed in `modules/wordpal_canvas/src/CanvasWriter.php` (commit
  f7ea8fa), with a failing-first kernel test:
  `CoreBlockLibraryWriterTest::testListingOutputsDropsWithoutComments`.
  Converting `patterns-political` live now succeeds: home has 25 diff lines,
  single (node 4) has 12.

### Single refresh, 2026-09-26

The 2026-09-25 single counts above were measured on the seeder's neighbor
stub, a different node per theme. The run scripts pick the
same canonical node on every theme (`title='A walk through the city'`), so
this run replaces those counts with one measured on the same node throughout.

| Theme | Old single diff lines | New single diff lines |
|---|---|---|
| twentytwentyfour | 7 | 3 |
| blockskit-base | 12 | 3 |
| buildapp | 3 | 3 |
| charity-organization | 17 | 11 |
| patterns-political | 12 | 12 |
| gutentools | 7 | 8 |

