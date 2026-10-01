# WordPal

WordPal turns WordPress block themes into editable page-builder configuration for Drupal. It ships WordPress core blocks as Drupal components.

## Language

### WordPress source

**Block theme**:
A WordPress theme whose templates are built from blocks.
_Avoid_: FSE theme, WP theme

**Block**:
One named WordPress unit of content, such as `core/paragraph`.
_Avoid_: widget

**Static block**:
A **Block** whose HTML is saved into the content when the author edits it.

**Dynamic block**:
A **Block** whose HTML is built at request time from site data.

**Theme license**:
The license a **Block theme** states in its `style.css` `License` and `License URI` headers. A **Conversion** checks it for a zip or URL source and trusts WordPress.org for a slug (ADR 0019).

**Plugin license**:
The license a WordPress plugin states in its main file's `License` and `License URI` headers, or in its `readme.txt` when the main file states none. A **Conversion** reports it for each plugin whose files ship in the generated theme, and never refuses one.

**Legal files**:
The **Block theme** files holding its copyright and license text: its root `readme.txt`, and each `LICENSE`, `LICENCE`, `COPYING` or `OFL` file at its root or under `assets/`, such as a font's `OFL.txt`. A plugin whose files ship in the generated theme adds its root `readme.txt`, `LICENSE`, `LICENCE` and `COPYING` files.

### WordPress patterns

**Pattern**:
A named, reusable tree of **Blocks** that a **Block theme** ships or lists in its `theme.json`.

**Inserter pattern**:
A **Pattern** that WordPress offers to authors in the block inserter.

**Hidden pattern**:
A **Pattern** marked `Inserter: no`, used only inside **Templates** and other **Patterns**.
_Avoid_: private pattern, internal pattern

**Remote pattern**:
A **Pattern** that a **Block theme** lists in `theme.json` and WordPress fetches from the WordPress.org Pattern Directory.

### WordPress pages

**Template part**:
A shared region of a **Block theme**, such as a header or footer.

**Template**:
The page layout a **Block theme** uses for one kind of page.

**Part set**:
The set of **Template parts** one **Template** uses.

**Sample content**:
The text, links and images stored in the saved HTML of a **Pattern**'s **Static blocks**.
_Avoid_: placeholder content

**Global stylesheet**:
The CSS WordPress builds from a **Block theme**'s `theme.json`: presets, element styles and block defaults.
_Avoid_: theme CSS, global styles

**Style variation**:
An alternative `theme.json` in a **Block theme**'s `styles/` directory, changing colors, fonts or presets.
_Avoid_: skin, color scheme

**Reference render**:
The HTML WordPress produces for one **Template** or **Pattern**, the baseline for **Appearance equivalence** and **Markup parity**.
_Avoid_: snapshot, golden file

**Reference render name**:
The file name one **Reference render** is stored under, read by
`wordpal:parity`'s `name` argument:

- a **Template** reference uses the route it renders: `home`, `home-page-2`,
  `single`, `page`, `archive`, `category`, `search`, `search-empty` or
  `not_found`, whichever Template the hierarchy picks
- the fixture's query listing uses `home--posts-3-col`, plus the
  `home--posts-3-col-page-2` and `home--posts-3-col-empty` variants
- a **Pattern** reference sits under `references/patterns/`, named after the
  Pattern's slug with `/` replaced by `--`. `wordpal:parity` finds it from the
  target's own machine name: a Canvas `Pattern` or a Display Builder
  **pattern preset**.

### Drupal output

**Component**:
The Drupal counterpart of one **Block**.

**Component inputs**:
The `ComponentInputs` mapping from a **Block**'s own attributes onto its **Component**'s props, target-independent: the SDC's own prop schema decides which attribute lands on which prop. `wordpal_canvas` and `wordpal_display_builder`'s `SourceTreeBuilder` use it for every converted block; `QuerySettingsWriter` uses it for the fixed runtime components a Query's own View renders through on every target.

**Builder target**:
The Drupal page builder that receives a **Conversion**: Drupal Canvas or Display Builder.
_Avoid_: layout builder, editor

**Writer**:
The `WriterInterface` implementation behind one **Builder target**, chosen by
`--target`. `wordpal_canvas` and `wordpal_display_builder` each provide one.

**Runtime module**:
The module holding a **Builder target**'s request-time rendering: its Views area plugin and preprocess hooks. Production installs it without the converter (ADR 0008). Canvas's is `wordpal_canvas_runtime`, Display Builder's `wordpal_display_builder_runtime`.

**Conversion**:
One run that turns a **Block theme** into editable configuration and pages for a **Builder target**.
_Avoid_: import, port

**Content mapping**:
The input to a **Conversion** that assigns each WordPress content concept to a Drupal bundle, vocabulary, field, menu or search page.
_Avoid_: field map, content model

**Default mapping**:
The built-in **Content mapping** that targets the Drupal CMS content model. `--mapping` replaces it.

**Demo content**:
Posts, one page, terms, menu links, a featured image with its file, a Demo author and, when the mapping binds `comments`, comments a **Conversion** seeds when the mapped post bundle has no nodes. They are **Owned outputs**.
_Avoid_: sample content, fixture content

**Page frame**:
The Drupal output of one **Part set**: the header and footer trees and Drupal's
status messages around a page's main content. The **Drupal-route frame** also
holds the page **Template**'s body. Canvas saves a frame as a page variant;
Display Builder saves it as a **page layout**.

**Page layout**:
Display Builder's own entity for a **page frame**.

**Drupal-owned route**:
A front-end route whose main content is not a converted output, such as `/user/login` or a 403 page. WordPress has no counterpart for it.

**Drupal-route frame**:
The **page frame** of **Drupal-owned routes**, built from the **Template** the page route resolves. Its Post Title shows the route's title, and its Post Content holds the main content.

**Default frame**:
The **page frame** that wraps a page with no converted **Template** of its
own: the **Drupal-route frame**. Canvas sets one sitewide setting; Display
Builder's default frame is the **page layout** with no route condition.

**Frame condition**:
The `wordpal_frame_routes` condition a Display Builder **page frame** carries for its routes. It matches the home, listing and 404 routes' paths, or the single and page routes' mapped bundles.

**Frame weight**:
The page layout weight Display Builder gives a conditioned **page frame**, lower sorting first: -30 for the home frame, -20 for one whose routes include a path, -10 for one conditioned on node bundles only.

**Pattern preset**:
Display Builder's own entity for a **Pattern**, holding a source tree. A
**runtime preset** and a listing Template's disabled tree are pattern
presets too.

**Runtime component**:
A **Component** whose props bind to the routed entity's own data, such as
Post Title or Post Date, instead of staying static.

**Bindings table**:
The checked-in table, keyed by a **runtime component**'s block name, naming
a **source chain** for each of its props.

**Runtime binding**:
One **bindings table** entry: the **source chain** for one prop of one
**runtime component**.

**Source chain**:
A field property on an entity, its own canonical link, or a referenced
entity's field property one hop away.

**Runtime preset**:
A **pattern preset** for one **runtime component** on one mapped bundle, its
props resolved by the **bindings table** against the routed entity.

**Override field**:
A `ui_patterns_source` field, labelled "Full display override", holding one
entity's own source tree. The home and 404 nodes use it to diverge from
their bundle's shared **full display**.

**Full display**:
The mapped bundle's `full` entity view display. The single and page
Templates become its Display Builder sources.

**Frozen block**:
A **Block** with no **Component** of its own, converted to one component holding the HTML WordPress rendered for it. Editors cannot change that HTML. A Query inside it renders as its View, in a hole slot: the theme file holds a hole marker at the Query's place, and the component's slot of that number fills it. A hole slot is an ordinary slot. A subtle mark shows it on public pages, in editing and in preview.
_Avoid_: fallback block, raw block

**Dropped block**:
A **Block** a **Conversion** omits instead of converting, because the **Content mapping** drops its content concept (`TreeConcepts::dropped()`), or, for Post Featured Image alone, because the bundle has no field a **runtime binding** needs (`RuntimeBindings::omission()`). Counted per concept under the report's "Dropped concepts" list, once per omission the tree walk meets. A Query card counts against its own bundle, and a reused View or empty state adds nothing.

**Site recipe**:
The Drupal recipe a **Conversion** writes, holding its owned config and exported content. Production applies it.
_Avoid_: theme package, export

**Appearance equivalence**:
Agreement between a Drupal render and its **Reference render** on the computed styles and box geometry of each visible element. The acceptance rule for conversion fidelity (ADR 0017).
_Avoid_: visual parity, pixel match

**Markup parity**:
Agreement between a Drupal page and its **Reference render** on tags, classes and inline styles, ignoring text and **Sample content**. A diagnostic for **Appearance equivalence**.
_Avoid_: visual parity, pixel match

**Evaluation**:
A check of a **Conversion** against its **Reference renders**, for **Appearance equivalence** or **Markup parity**. Development tooling: the release leaves it out, and a **Conversion** never runs one (ADR 0018).
_Avoid_: parity check, test harness

**Owned output**:
A Drupal entity, config object or file one **Conversion** created and lists in
its ownership manifest. `--replace` deletes and recreates only a theme's own owned outputs.

**Unmanaged output**:
A Drupal entity, config object or file that collides with a **Conversion**'s intended output
but is not in that theme's ownership manifest. A **Conversion** fails
before writing rather than overwrite one, except two cases activation takes
over and lists as an **Owned output** (ADR 0008): a disabled content template
on the converted theme's own bundle, and a **full display** another theme's
manifest owns.

**Listing block**:
A WordPress block whose Drupal output is its own View: Latest Posts, Page
List or Latest Comments.

**Listing kind**:
Which **listing block** a listing View renders: `latest_posts`, `page_list`
or `latest_comments`.

**Listing View**:
A View a **listing block** becomes, tagged `wordpal_listing`. Two
occurrences of the same **listing kind** and settings share one.

**Query sharing**:
Two Query blocks with an identical Post Template share one card. Two Query
blocks whose bundle, paging, sort, sticky filter and Query subtree all match
also share one View and one empty state. On Canvas the card is a view mode
and content template, the empty state a `Pattern`, and the View's block a
`views_block` **Component**. On Display Builder the card is the View's own
row, the empty state a **pattern preset**, and neither is a separate entity.

**Query settings**:
The `wordpal.query` third-party setting a Query's own View carries: the Post Template's, pagination's and no-results inputs, each listed bundle's category, tag and image field, and the pagination child order. Both writers store it through `QuerySettingsWriter`; `QuerySettings` reads it back.

**Attribution notice**:
The `ATTRIBUTION.md` a **Conversion** writes into the generated theme. It names the original **Block theme**, its version, author and **Theme license**, links the original, and states that WordPal converted and modified it. It also credits each plugin whose files ship in the generated theme, with its **Plugin license**.
_Avoid_: credits file

**Platform credit**:
Text in a **Block theme** that credits WordPress as the site's platform, such as "Designed with WordPress". A **Conversion** strikes WordPress through and links Drupal after it. A credit to a WordPress theme is not a platform credit.
_Avoid_: footer credit, powered-by line

## Relationships

- A **Block theme** has many **Templates**, **Template parts** and **Patterns**
- A **Template**, **Template part** or **Pattern** is a tree of **Blocks**
- Each **Block** maps to one **Component**
- A **Conversion** writes to one **Builder target**
- A **Conversion** takes one **Content mapping**
- A **Content mapping** covers posts, pages, categories, tags, featured images, excerpts, author biographies, comments, the navigation menu and search
- A **Conversion** offers each **Inserter pattern** to editors; a **Hidden pattern** appears only inside the trees that use it
- A **Conversion** keeps the block tree, styling and **Sample content**
- A **Conversion** turns each **Block** without a **Component** into a **Frozen block**
- A **Conversion** writes one **Site recipe**
- A **Conversion** copies the **Legal files** into the generated theme beside one **Attribution notice**
- **Templates** with the same **Part set** share one copy of those **Template parts** after a **Conversion**
- A **Conversion** produces one **Global stylesheet** per **Style variation**, the theme's own styles included
- An **Evaluation** produces one **Reference render** per **Template** it checks

## Example dialogue

> **Dev:** "The footer **Template part** holds a site title **Dynamic block**. Does the **Conversion** copy its HTML?"
> **Domain expert:** "No. A **Dynamic block** has no saved HTML. Its **Component** renders the site title from Drupal data."

> **Dev:** "The hero **Pattern** has a heading with marketing copy. Does that text reach Canvas?"
> **Domain expert:** "Yes. It is **Sample content**, kept so the page looks like the theme. Editors replace it. **Markup parity** ignores the text."

## Flagged ambiguities

- "Match WordPress visually" first meant screenshot comparison, then **Markup parity**. Resolved: fidelity means **Appearance equivalence** (ADR 0017).
- "Post" names a WordPress content concept. On the Drupal side it means a node of the bundle the **Content mapping** assigns to posts.
- "Snapshot" is an **Avoid** term for **Reference render** prose, not for code. The `Snapshot` class and `snapshot.php` name the Playground export a **Reference render** comes from.
- "Fixture" is an **Avoid** term for **Demo content** prose, not for code. `ContentFixtureLoader` and its `--fixture` option name the loader that seeds **Demo content**.
