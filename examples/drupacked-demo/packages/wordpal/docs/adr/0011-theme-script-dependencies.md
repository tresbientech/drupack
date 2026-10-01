# Theme script dependencies

71.7% of active block themes ship JavaScript, such as sliders, scroll effects and back-to-top links. Convert exports a script the theme enqueues when each of its WordPress dependencies has a Drupal library.

After `wp_enqueue_scripts`, the snapshot lists the queued scripts whose file lies in the theme directory, in WordPress's print order, with their dependency handles. A dependency maps to a Drupal library when it is:

- `jquery`, which maps to `core/jquery`;
- another exported theme script, which maps to that script's library.

A script with any other dependency, such as `wp-i18n` or `wp-dom-ready`, is skipped. A script depending on a skipped script is skipped too. The report names each skipped script and the dependency with no Drupal library.

The generated theme declares one library per exported script and lists it in its `info.yml`, so every page loads it, as WordPress enqueues it on every page. A library keeps the script's footer placement and its `defer` or `async` strategy. Core and plugin scripts are never exported.

## Considered Options

- Vendor the WordPress handles theme scripts depend on, such as `wp-dom-ready` and `wp-i18n`. Rejected: it ships WordPress code outside the pinned component set, and `wp-i18n` needs WordPress's translation data.
- Export every theme script and let a missing dependency fail in the browser. Rejected: the failure is silent for the site builder.
- Skip theme scripts, as milestone 2 did. Rejected: sliders and toggles stop working.

## Consequences

- Pages load core's jQuery once, for the theme scripts needing it.
- A script reading a WordPress global, such as `wp` or a localized object, fails in the browser although its dependencies resolved.
- A script expecting WordPress markup Drupal does not print acts on nothing.
