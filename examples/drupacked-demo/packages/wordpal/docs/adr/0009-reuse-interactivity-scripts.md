# Reuse WordPress Interactivity scripts

`drush wordpal:generate-components` copies WordPress's built `@wordpress/interactivity` runtime and the view modules of supported blocks into `wordpal/js/script-modules`. It reads them from the verified release zip it already reads block CSS from. `wordpal` declares each view module as a library, attached by the block that prints its directives.

Core prints no import map. The copy rewrites each view module's `@wordpress/interactivity` import to a relative path. The runtime has no library of its own: Drupal's cache query string would give it a second URL and a second runtime instance.

The copies are WordPress's unminified builds. The global pre-commit hook checks a `.js` file as an ES module only when a line starts with `import ` or `export `. Minified builds start with `import{`, so the hook parses them as CommonJS and refuses them.

WordPress's navigation block calls no `wp_interactivity_state()`. Its state lives in `data-wp-context`, which the template prints, so Drupal prints no state script. The image lightbox is the first block that needs printed state, and it is deferred with it.

The converted markup already keeps WordPress's `data-wp-*` directives. The same scripts on the same markup give the same behavior. The navigation overlay uses them. The image lightbox and enhanced query pagination are deferred. ADR 0001 applies the same reasoning to styles: run WordPress's code instead of porting it.

## Considered Options

- Drupal behaviors per block, starting with the navigation overlay. Rejected: re-implements WordPress logic and drifts on each release.
- No JavaScript, as in milestone 1. Rejected: mobile navigation never opens.
- Convert copies the scripts from the Playground run into the generated theme. Rejected: a library's files sit in the extension that declares it, and the scripts depend on the WordPress release, not the theme.
- Drupal prints an import map for `@wordpress/interactivity`. Rejected: core has no import map API.

## Consequences

- Pages with interactive blocks load the runtime, 104 KB unminified (40 KB minified), and the block's view module.
- Scripts follow the pinned WordPress version.
- A view module reading a state key Drupal does not print fails in the browser without an error.
