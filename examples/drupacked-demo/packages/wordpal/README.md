# WordPal

WordPal converts a WordPress block theme into a Drupal CMS site that editors change in Drupal Canvas or Display Builder. The project holds seven modules:

- `wordpal`: the components, block plugins, Twig extension and comment runtime a converted site runs on.
- `wordpal_convert`: exports a theme through WordPress Playground, builds its block trees and mappings, and writes the theme, the Demo content and the Site recipe.
- `wordpal_evaluate`: checks a Conversion against WordPress, with the comparators, the block matrix and Reference renders. Development only; the release leaves it out.
- `wordpal_canvas`: writes the Canvas configuration.
- `wordpal_canvas_runtime`: renders a Canvas-converted site's Views and field bindings.
- `wordpal_display_builder`: writes the Display Builder configuration.
- `wordpal_display_builder_runtime`: renders a Display Builder-converted site's Views and pages.

Production installs `wordpal` and the target's runtime module: `wordpal_canvas_runtime` or `wordpal_display_builder_runtime`.

WordPal converts Twenty Twenty-Four, Twenty Twenty-Five and YITH Wonder onto a fresh Drupal CMS site, and deploys each through its Site recipe.

## Requirements

- Drupal CMS on Drupal 11.4 or later.
- PHP 8.4 or later, with the `zip` extension.
- Node.js with `npm` and `npx` (22.22.2 or a later 22, 24.15.0 or a later 24, or 26 and later) on the machine that converts, with network access to wordpress.org and the npm registry. Conversion runs WordPress's block parser in jsdom 30, which requires these versions.
- WordPress, pinned in [`components/wordpress-version.json`](components/wordpress-version.json), which the component definitions and Reference renders follow. A theme or plugin that requires a newer WordPress fails before Playground starts.

### Tested versions

The setup below was run on a fresh site with:

- Drupal CMS 2.2.0 on Drupal core 11.4.8, PHP 8.4.20 and Node.js 24.15.0;
- Canvas 1.12.0 and Twig 3.30.0;
- for Display Builder: Display Builder 1.0.0-beta8, UI Patterns `2.0.x` at commit `e47d09e4`, ui_skins 1.2.1 and `cweagans/composer-patches` 2.0.0, with the three patches in [`patches`](patches).

## Set up the conversion site

Conversion runs in local development, on either Builder target. Production never runs it.

1. Create the Drupal CMS codebase, then open the site in a browser to run the Drupal CMS installer, or run `drush site:install`:

   ```shell
   composer create-project drupal/cms mysite
   ```

2. Require the two Drupal CMS recipes the WordPal recipe builds on. Drupal CMS 2.2 does not ship them.

   ```shell
   composer require drupal/drupal_cms_content_type_base drupal/drupal_cms_search
   ```

3. Add WordPal under `web/modules/contrib/wordpal`. Composer installs a drupal.org release there. While only pre-releases exist, add their stability flag, such as `drupal/wordpal:@alpha`:

   ```shell
   composer require drupal/wordpal
   ```

   A git clone of the project also holds `wordpal_evaluate`, which the release leaves out and the parity and matrix commands need:

   ```shell
   git clone https://git.drupalcode.org/project/wordpal.git web/modules/contrib/wordpal
   ```

4. Link the WordPal recipe beside the Drupal CMS recipes, from the project root. Conversion reads the recipe from the package, but the Site recipe names `wordpal` as a sibling, and core finds a recipe's dependencies in the same directory.

   ```shell
   ln -s ../web/modules/contrib/wordpal/recipes/wordpal recipes/wordpal
   ```

5. For the Display Builder target, install its dependencies first, as [Display Builder dependencies](#display-builder-dependencies) describes.

6. Enable the converter of the target, `wordpal_canvas` or `wordpal_display_builder`:

   ```shell
   drush en wordpal_canvas
   ```

`wordpal_canvas` pulls in `wordpal`, `wordpal_convert`, `wordpal_canvas_runtime`, Canvas, Views and Menu link content. `wordpal` needs Comment, Node, Taxonomy, Text, Views and Menu link content.

## Convert a theme

```shell
drush wordpal:convert twentytwentyfour
```

The argument is a WordPress.org slug, a zip path or an https URL to a zip. A zip holds one directory named after the theme slug, with `templates/index.html`. A classic or hybrid theme fails before Playground starts: [hybrid theme support](docs/reports/hybrid-theme-support.md) gives the reasons.

Conversion runs WordPress in Playground, seeds the Demo content fixture there, and caches the snapshot. It then writes these outputs:

- a Drupal theme under `themes/custom/<theme_id>`;
- one Canvas Pattern per Inserter pattern;
- one Canvas page variant per set of header and footer Template parts;
- the home and 404 Templates as Canvas pages;
- the single and page Templates as full content templates;
- the archive, tag, category and search Templates as Views pages rendering a Canvas Pattern;
- Demo content, when the mapped post bundle has no nodes;
- the Site recipe at `recipes/wordpal_<theme_id>`.

`<theme_id>` is the slug with hyphens turned into underscores.

### Generated theme

The theme holds:

- the Global stylesheet and block styles;
- the stylesheets the WordPress theme enqueues;
- the scripts the WordPress theme enqueues, when each dependency has a Drupal library;
- fonts, images and SVG assets, with the image files the theme references outside `assets/` under `assets/_theme/`;
- the Frozen block HTML, in `frozen/`;
- the plugin stylesheets and inline CSS the Frozen blocks need, one library each;
- the templates and the preprocess that prints WordPress's body classes.

### Activation

Conversion then activates the outputs:

- installs the theme and makes it the default theme;
- makes the converted home the front page and the converted 404 page the 404 page;
- sets the Canvas default page variant;
- enables the listing Template Views and disables the site's Views on the same paths;
- takes over a disabled full content template on the post or page bundle;
- grants anonymous visitors `access comments` when the theme places a bound comments block. The Site recipe grants no permission.

### Options

| Option | Effect |
|---|---|
| `--plugin=<ref>` | Installs and activates one plugin in WordPress before it renders. Takes a slug, zip path or URL. Repeat it for each plugin. |
| `--mapping=<file>` | Replaces the default Content mapping whole. |
| `--refresh` | Runs WordPress again instead of reusing the cached snapshot. |
| `--replace` | Deletes the previous conversion's owned outputs, then writes new ones. |
| `--no-activate` | Skips every activation step above. |
| `--no-demo-content` | Seeds no Demo content. |
| `--accept-license` | Converts a zip or URL theme whose license is missing or not GPL-compatible. |

```shell
drush wordpal:convert yith-wonder --plugin=woocommerce --plugin=wpforms-lite
```

Conversion installs only the plugins named.

### Theme license

A zip or URL theme must state a GPL-compatible license in its `style.css` `License` or `License URI` header. Without one, conversion stops before WordPress runs. `--accept-license` converts it anyway, and the output says so. A WordPress.org theme passes: the directory accepts GPL-compatible themes only.

The generated theme keeps the theme's `readme.txt`, its license files and its screenshot. `ATTRIBUTION.md` names the original theme, author and license, links the original, and states that WordPal modified it. The Appearance page shows the same in the theme description.

A Frozen block's plugin stylesheets, inline CSS and the assets they reference ship in the generated theme too. `ATTRIBUTION.md` credits each such plugin, and the theme keeps the plugin's root readme and license files. The output lists each plugin with its license. A plugin's license never stops a conversion.

A credit to WordPress as the platform, such as "Designed with WordPress" or "Proudly powered by WordPress", becomes a credit to Drupal: WordPress is struck through and "Drupal" links to drupal.org. A credit to a WordPress theme, such as "WordPress Theme by SuperbThemes", stays as it is. Editors change or remove the credit like any other text. On the Canvas target, the conversion and its Site recipe let Canvas's inline text format and editor keep struck-through text. They also keep a classed `<span>`, such as a footer's separator.

### Cached snapshot

The snapshot lives in the Drupal temporary directory, under `wordpal/<slug>`. It records the theme and plugin sources, with each zip's SHA-256 hash. A later conversion reuses it without the network. Changing `--plugin`, or pointing at a zip whose contents changed, fails until you add `--refresh`.

### Default mapping

Without `--mapping`, conversion targets the Drupal CMS content model:

| Concept | Target |
|---|---|
| post | `blog` bundle |
| post_body | `field_content` |
| page | `page` bundle |
| page_body | `field_content` |
| page_excerpt | `field_description` |
| tag | vocabulary `tags`, field `field_tags` |
| featured_image | `field_featured_image` |
| excerpt | `field_description` |
| navigation | `main` menu |
| search | path `/search`, parameter `keywords`, Search API index `content` |
| category, comments, author_biography | `~` (dropped) |

When a default target is missing, conversion applies the `recipes/wordpal` recipe of the WordPal package first. The recipe creates the `blog` bundle and applies `drupal_cms_content_type_base` and `drupal_cms_search`. Existing config wins, so a customized `blog` bundle stays as it is. Targets still missing after the recipe fail the conversion, all listed together.

### Override mapping

`--mapping` names a YAML file with a target for every concept the theme's trees use. `~` drops a concept, and an unknown concept name fails. The [fixture mapping](modules/wordpal_convert/tests/fixtures/content/content-mapping.yml) shows its shape. An override applies no recipe: missing targets fail the conversion before any write.

```shell
drush wordpal:convert twentytwentyfour --mapping=/path/to/mapping.yml
```

`~` drops a concept's blocks, and the report counts them.

### Repeat a conversion

A second conversion of the same theme stops before writing and counts the existing outputs by type. `--replace` recreates them:

```shell
drush wordpal:convert twentytwentyfour --replace
```

- Replacement resets editor changes to owned outputs, and removes outputs the new conversion does not write.
- Replacement recreates Demo content, and the front page follows the new home.
- An output WordPal does not own blocks the conversion before any change. The command lists it, and `--replace` never deletes it.
- On an installed theme, replacement rebuilds the theme registry and clears rendered markup, so changed templates apply without `drush cr`.
- Replacement deletes the previous outputs before it generates the theme and writes the new ones. A failure after that point leaves the previous conversion deleted. Take a database backup first, or run the conversion again with `--replace` until it succeeds. A failed first conversion also leaves outputs behind, so its next run needs `--replace` too.

Each conversion records its outputs in an ownership manifest, `wordpal_convert.conversion.<theme_id>`. The manifest also records the WordPress version of the snapshot and the WordPal release. A git checkout of WordPal has no release, so it records none.

Two themes on one site each need their own post bundle: Canvas keys a content template by bundle and view mode. On Display Builder, two themes can share a bundle. Activating the second takes over the shared full display from the first theme's manifest, and the first theme loses its own sources on it.

### Choose a Builder target

`--target` names the Builder target a conversion writes to: `canvas` or `display_builder`. Without `--target`, the conversion uses the target whose module is enabled. When both modules are enabled, `--target` is required. The command fails before Playground when the named target's module is disabled, or when neither module is, naming the modules to enable.

A theme's manifest records the target it last converted to. Converting the same theme to the other target fails like a repeat conversion. `--replace` deletes the existing outputs first:

```shell
drush wordpal:convert twentytwentyfour --target=display_builder --replace
```

### Display Builder dependencies

`wordpal_display_builder_runtime` depends on `display_builder`, with its `display_builder_entity_view`, `display_builder_page_layout` and `display_builder_views` submodules. It also depends on `ui_patterns`, with its `ui_patterns_field`, `ui_patterns_field_formatters` and `ui_patterns_views` submodules, and on `ui_skins`. `wordpal_display_builder` depends on the runtime module.

Notes on the released versions:

- UI Patterns 2.0.21 needs a fix that lands later in its `2.0.x` branch, so the commands pin a `2.0.x` commit. That branch also drops a prop set to `"0"`, such as a zero padding; a patch fixes it.
- Display Builder 1.0.0-beta8 fails on a site with Canvas enabled, as Drupal CMS 2.x sites are: `ComponentSource::__construct()` receives no component manager. Two patches fix it.
- Display Builder 1.0.0-beta8 lists pattern presets through its `display_builder_ui` submodule. Enable it to browse the presets a conversion wrote.
- UI Patterns logs `Missing entity from context for node` when a conversion saves a pattern preset that reads a post's referenced entity, such as its featured image. A preset has no post, and UI Patterns logs the missing context while it lists the preset's dependencies. The conversion completes.

From the project root, require the modules and the patch plugin:

```shell
composer config allow-plugins.cweagans/composer-patches true
composer require cweagans/composer-patches drupal/display_builder:1.0.0-beta8 'drupal/ui_patterns:2.0.x-dev#e47d09e482e8689917f94113a5fb6450ea647cdf' drupal/ui_skins:1.2.1
```

Copy the three patch files from the WordPal package into the site's `patches` directory, declare them, and apply them:

```shell
mkdir -p patches
cp web/modules/contrib/wordpal/patches/*.patch patches/
composer config --json extra.patches '{"drupal/display_builder": {"Inject UI Patterns component manager": "patches/display_builder-ui-patterns-component-manager.patch", "Declare the placeholder props schema": "patches/display_builder-placeholder-props-schema.patch"}, "drupal/ui_patterns": {"Keep a zero string prop value": "patches/ui_patterns-zero-prop-value.patch"}}'
composer patches-relock
composer patches-repatch
drush cr
```

Display Builder also asks for JS aggregation off in development, beyond the local-development-only rule every Builder target follows.

## Read the report

`wordpal:convert` prints these lists:

- Target: the Builder target the run wrote to.
- Created outputs, as `entity_type:id`.
- Seeded Demo content, and Demo menu items left out.
- Menus holding Navigation blocks' own links, with the trees that bind each.
- Disabled content templates and Views that the converted Templates replace.
- Listing Templates, with the Canvas URL that edits each: `/canvas/pattern/<id>`.
- The Site recipe path and the command that applies it.
- Style variations.
- Dropped concepts, with a block count per concept and the Drupal feature that provides it.
- Block options without their WordPress behavior.
- WordPress controls with no prop, per tree, such as a custom duotone. The block keeps its imported appearance, and editors cannot change it.
- Frozen blocks, per tree.
- Block namespaces no active plugin registers, with the trees that use each.
- Left out: the Templates, links, style variations and theme assets the conversion skipped. Routes a page frame could not condition and Demo content the Site recipe leaves out are listed here too.
- Queries left out, per tree, with the settings no View reproduces, such as another post type. The rest of the tree converts.
- Skipped patterns, with the reason.

Conversion skips an SVG asset that holds script, event handlers, external references or an unknown DOCTYPE, because the theme origin would serve it.

## Choose a style variation

Conversion builds one Global stylesheet per `styles/*.json` style variation, plus `default` for the theme's own styles. Select one at `/admin/appearance/settings/<theme_id>`. The change needs no reconversion: the `wordpal` module rebuilds library definitions when the setting changes. Pages and the Canvas preview load the selected variation.

## Edit in Canvas

| Output | Canvas editor |
|---|---|
| Home and 404 pages | `/canvas/editor/canvas_page/<id>` |
| Single and page Templates | `/canvas/template/node/<bundle>/full` |
| Listing Templates and Inserter patterns | `/canvas/pattern/<id>` |

The single Template binds post props to Drupal fields, and editors can change those bindings. A Frozen block moves or goes away in Canvas, but its HTML cannot change. A Query inside it stays a View.

## Check parity

`wordpal:parity` and `wordpal:reference` need the Evaluation module, which only a git clone holds, enabled:

```shell
drush en wordpal_evaluate
```

`wordpal:parity` renders a Drupal path and compares its markup with the Reference render WordPress wrote into the snapshot:

```shell
drush wordpal:parity /node/<nid> single --scope=page
drush wordpal:parity / home --scope=page
drush wordpal:parity - twentytwentyfour_cta_pricing
```

- The second argument names a Reference render: `home`, `home-page-2`, `single`, `page`, `archive`, `category`, `search`, `search-empty` or `not_found`. It can also name a Canvas pattern, and then the path is ignored, or `fixture:<name>` for a block library fixture: `library`, `archive` or `search`.
- A route the theme has no Template for has no Reference render, and neither has `home-page-2` when the home Template shows the same posts on page 2. Parity reports such a route as skipped.
- `--scope=main` compares the main area, and `--scope=page` compares the whole `wp-site-blocks` element. The default is `main`.
- `--theme=<slug>` picks the converted theme whose snapshot to read. The default is the site's default theme.

Use the node ID of a Demo post for `single`. The 404 Template compares on a path nothing matches:

```shell
drush wordpal:parity /no-such-page not_found --theme=twentytwentyfive
```

Grant anonymous visitors `post comments` on the site parity and the block matrix render on:

```shell
drush role:perm:add anonymous 'post comments'
```

WordPress prints the comment form and reply links to every visitor. Drupal prints them only with this permission. A conversion never grants it.

Parity ignores sample text, HTML-sourced attribute values, the layout hash, and a class or attribute only Drupal prints. [ADR 0004](docs/adr/0004-parity-exceptions.md) lists every other exception. Parity compares no CSS, and it is a diagnostic. The acceptance rule is Appearance equivalence, which the block matrix checks ([ADR 0017](docs/adr/0017-appearance-equivalence.md)).

`wordpal:reference <theme>` reruns WordPress to refresh the Reference renders alone, without converting.

## Check the block matrix

`wordpal:block-matrix` also needs `wordpal_evaluate` enabled. It renders every
conformance case of the named core blocks in WordPress and in Drupal, then
compares computed style and box geometry between the two
([ADR 0017](docs/adr/0017-appearance-equivalence.md)):

```shell
drush wordpal:block-matrix /node/16 paragraph heading --mapping=/path/to/mapping.yml
```

- The path argument is a post the canonical single node "A walk through the
  city" maps to. The block arguments are core block slugs. Without them, or
  with `all`, the command checks every core block that converts to a
  component, a block plugin, or a part of a parent block that does.
- `--mapping` names the Content mapping file the site converted with.
- A case fails on a style difference. Its markup differences, from
  `MarkupComparator`, print underneath as diagnostics.

The command runs Chromium, through Playwright, inside the web container. Add
Chromium to the ddev webimage with a `.ddev/web-build/Dockerfile`:

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends chromium \
    && rm -rf /var/lib/apt/lists/*
```

Run `ddev restart` after adding it. Playwright installs into a private npm
directory the first time the command runs, the way `wordpal:block-matrix`'s
Node serialization installs jsdom.

## Deploy with the Site recipe

The Site recipe holds the Owned config and, in `content/`, the front page, 404 page and Demo content. Demo comments stay out: core's importer gives anonymous content to the administrator, and an anonymous commenter's name then fails validation. [ADR 0008](docs/adr/0008-site-recipe-deploy.md) records the decision.

A new production site needs:

- Drupal CMS, installed;
- the WordPal project code, of which the recipe installs `wordpal`, the target's runtime module, the Display Builder submodules on that target, and every other module the Owned config depends on;
- pathauto's code, which Drupal CMS ships. The recipe installs pathauto, so imported pages keep the alias `system.site` names;
- with `--mapping`, the bundles, fields and vocabularies the mapping names;
- on the Display Builder target, the patches in [Display Builder dependencies](#display-builder-dependencies);
- the generated theme, `web/themes/custom/<theme_id>`;
- `recipes/wordpal` and `recipes/wordpal_<theme_id>`, beside the Drupal CMS recipes.

From the Drupal root, apply the recipe once:

```shell
drush recipe ../recipes/wordpal_<theme_id>
```

Production never enables `wordpal_convert`, `wordpal_canvas` or `wordpal_display_builder`.

A redeploy after a reconversion does not reapply the recipe, since core recipes do not update existing config. Redeploy through config sync:

1. On the conversion site, keep the converter modules out of the export, in `settings.php`:

   ```php
   $settings['config_exclude_modules'] = ['wordpal_convert', 'wordpal_canvas', 'wordpal_display_builder'];
   ```

2. Give the conversion site production's site UUID, since `config:import` refuses config from another site. Read it on production with `drush config:get system.site uuid`, then set it on the conversion site:

   ```shell
   drush config:set system.site uuid <production_uuid>
   ```

3. Export config with `drush config:export`.
4. Ship the exported config and the regenerated `web/themes/custom/<theme_id>` directory with the code.
5. Import on production with `drush config:import`.

The front and 404 pages are stored as path aliases, so the imported settings resolve on production.

Content goes through Drupal's content tooling.

## What does not convert

[Block support](docs/block-support.md) lists every WordPress core block with its Drupal target and what it loses.

- The default mapping drops categories, comments and author biographies. The report names the Drupal feature each needs, and an override mapping converts them.
- The image lightbox and enhanced Query pagination have no script yet ([ADR 0009](docs/adr/0009-reuse-interactivity-scripts.md)).
- A block with no WordPal component becomes a Frozen block: its WordPress HTML, fixed.
- Plugin scripts are not exported ([ADR 0007](docs/adr/0007-frozen-blocks.md)), so a script-driven plugin block such as the WooCommerce mini-cart renders static. Its stylesheets export, one library per plugin.
- WordPress plugin filters on core block output do not run on Drupal.
- [ADR 0004](docs/adr/0004-parity-exceptions.md) lists each markup difference Drupal cannot avoid.

## Reference

### Frozen blocks

The generated theme holds the HTML WordPress rendered for each Frozen block, in `frozen/<sha256>.html`. The HTML comes from the snapshot's block render index. The `wordpal:frozen-html` component holds only the hash and prints the file from the default theme. The tree around a Frozen block still converts.

A Query inside a Frozen block becomes a View, like any other Query. The file holds a hole marker where the Query's HTML was, and the View fills the matching hole slot, `hole_1` to `hole_4`. A Query the converter leaves out is cut from the file. A Frozen block with more than four Queries, or whose HTML holds a Query's HTML other than once, is skipped. The Canvas target reports it; the Display Builder target leaves it out.

The component adds `data-wordpal-frozen="<block name>"` to the first element of that HTML. A dashed outline marks it on public pages and in both builders, and the block matrix's style comparison skips it.

### Navigation

A Navigation block with a `ref`, or with no inner blocks, renders the mapped menu. Demo content populates that menu. A Navigation block with its own links gets its own Owned menu, `wordpal-<hash>`, and blocks of one theme with the same links share it. Its links are Custom Links, Submenus and a Home link, optionally followed by Social Links, which render as a nested component after the links. A Submenu becomes a menu link with child links, and the Home link a link to the front page. A block holding any other inner block, such as a Page List, renders the mapped menu, and the report lists it.

Nested menu links print as WordPress submenus, in own menus and the mapped menu alike.

A link URL must be a root-relative path, a fragment or an http(s) URL. Conversion leaves out other links and reports them. The navigation overlay runs WordPress's own Interactivity script.

### Query listings

Each Query block becomes a View and a Canvas content template for its post cards. Views owns paging and empty results. Query blocks with the same card and settings share one View ([ADR 0005](docs/adr/0005-query-props-in-view-config.md)). Offsets, sticky-post exclusions, page caps and title or date order follow the WordPress Query settings. Query blocks in template parts become Views too.

Latest Posts, Page List and Latest Comments blocks each become a listing View, rendered by one shared `views-view--wordpal-listing.html.twig` template. Blocks of the same kind and settings share one View. Latest Posts and Latest Comments cap their row count; Page List shows every page.

### Comments

The Drupal CMS `blog` bundle has no comment field, so the default mapping drops comment blocks. To convert them:

1. Create a comment type and add a comment field to the post bundle.
2. Set `comments` in an override mapping to that field.

The runtime follows Drupal permissions and field visibility. Closed discussions keep existing comments. Hidden discussions render no comments and no form. The form keeps Drupal validation, submission and preview.

## Regenerate definitions

```shell
drush wordpal:generate-components 7.1.2
drush cr
```

The generator updates the definitions of existing Twig components and records the WordPress version. It keeps hand-written Twig.

It also copies WordPress's Interactivity runtime and the accordion, navigation, search and fit-text view modules to `js/script-modules/`. The navigation block attaches the `wordpal/interactivity.navigation` library when its overlay is on or its submenus open by click or toggle. The accordion attaches `wordpal/interactivity.accordion`. The copy rewrites each view module's `@wordpress/interactivity` import to a relative path, because Drupal core prints no import map.

`wordpal_convert` implements `hook_wordpal_component_definition_alter()`. It restores the entity-binding props `ComponentDefinitionAlter::RUNTIME_PROPS` lists, such as the `post-*` and `query-pagination-*` components' props, on either target. `wordpal:generate-components` prints the modules that implement the hook, or `none`.
