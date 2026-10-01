# Evaluation ships outside the release

An Evaluation checks a Conversion against WordPress: the comparators, the block matrix, Reference renders, fixtures and their Drush commands. That code lives in the development module `wordpal_evaluate`, which depends on `wordpal_convert`. Conversion code never depends on it. `.gitattributes` export-ignores the module, so the drupal.org release leaves it out.

A Conversion's WordPress run writes no Reference renders. `PlaygroundRunner` runs the extra PHP scripts a caller passes after its export, and knows nothing of what they do. `wordpal_evaluate` passes the script that renders references and fixtures, so an Evaluation reuses the same WordPress boot.

## Considered options

- A separate Playground run per Evaluation: a second WordPress boot, and the theme, content and site name setup written twice.
- A separate development repository: the evaluation code would track conversion internals across two repositories.

## Consequences

- drupal.org packages a release with `git archive`, which honors `export-ignore`, so the release and its Composer dist zip leave the module out. A git clone still holds it.
- The Node loader for WordPress's script bundles stays in `wordpal_convert`, since a Conversion parses saved blocks with it.
