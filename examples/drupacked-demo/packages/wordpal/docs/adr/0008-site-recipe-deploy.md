# Deploy through a Site recipe

A **Conversion** runs in local development and writes a **Site recipe** at `recipes/wordpal_<theme>/`. The recipe holds the Owned config and, in `content/`, the front page, 404 page and **Demo content** exported with core's `content:export`. A new production site applies it once with `drush recipe`. Later deploys use Drupal's config export and import.

Conversion needs Node and network access to wordpress.org (ADR 0001). Shared hosts and locked-down platforms have neither. Config sync alone carries configuration but not the Canvas Pages and nodes a Conversion creates. A recipe carries both, and it is the standard Drupal packaging for site setup.

## Considered Options

- Convert on every environment. Rejected: production needs Node and Playground.
- Deploy through config sync and recreate content by hand. Rejected: production lacks the front page and posts.
- Publish prebuilt theme packages on drupal.org. Rejected: every theme needs a maintainer, and paid themes cannot be published.

## Consequences

- `wordpal_convert` and `wordpal_canvas` ship in the WordPal project beside `wordpal`. One Composer package cannot `require-dev` its own submodules. Production never enables them: the Site recipe installs `wordpal`, which depends on neither.
- `wordpal` stays builder-agnostic: it declares no Canvas or Display Builder class, config name or dependency. `wordpal_canvas_runtime` holds the `wordpal_pattern` Views area and the `ensure_array` adapter, depending on `wordpal` and `canvas`; `wordpal_display_builder_runtime` holds the `wordpal_pattern_preset` Views area and the page-content wrapper hook. It depends on `wordpal`, `display_builder` and every Display Builder, UI Patterns and UI Skins module a converted site renders with. The Site recipe installs each runtime module, and `wordpal_canvas` and `wordpal_display_builder` each depend on their own.
- The Site recipe is the first-install path. Core recipes do not update existing config entities, so a redeploy after a reconversion uses config export and import, and content tooling for content.
- A reconversion rewrites the Site recipe. The recipe directory is an Owned output, and replacing or deleting the conversion removes it.
- `content:export` needs Drupal 11.3 or later.
- The Site recipe applies the WordPal recipe (`recipes/wordpal`), which carries the blog bundle and enables core's archive View. Production keeps that recipe beside the Site recipe.
- The recipe imports config without strict mode, so existing config wins. It writes each Owned content template over the site's own with `setProperties`, as a conversion takes over Drupal CMS's disabled page template.
- Production renders the listing Template Views and term fields through the `wordpal_pattern` Views area and the `ensure_array` Canvas adapter, both in `wordpal_canvas_runtime`.
- The writer leaves out Demo content that fails validation and lists it under "Left out". It leaves out Demo comments too: core's importer gives anonymous content to the administrator, and an anonymous commenter's name then fails validation. Core's exporter drops creation dates, and the writer keeps them because they order the Demo posts.
- Core's exporter writes password hashes. The writer leaves them out, because the recipe goes into version control.
- The manifest lists the site Views a conversion disabled, and the recipe disables the same Views.
- A conversion stores the front and 404 pages as path aliases, because imported pages get new ids. The recipe and config sync carry the aliases.
- Owned config files in the Site recipe keep their `uuid`. A first apply gives production the conversion site's entities, and the next `config:import` updates them. Two cases still recreate instead of update. Config production already held keeps its own UUID, since the non-strict recipe skips it. A `--replace` reconversion keeps each Owned entity's UUID. Canvas and Display Builder writers derive it from the entity type and id (`OwnedEntityUuid::uuid()`). The exception is a views_block Component: Canvas's own block-plugin rebuild creates it fresh each time. Core deletes and recreates an entity whose UUID changed, dependents included.
