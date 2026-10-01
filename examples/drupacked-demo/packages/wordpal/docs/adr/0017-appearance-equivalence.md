# Appearance equivalence

Status: Accepted

A converted block passes when it looks and behaves like its **Reference render**. Drupal may print other wrappers, classes or inline styles when the result is the same. This rule is **Appearance equivalence**. It replaces **Markup parity** as the acceptance rule.

`wordpal:block-matrix` checks each case in a headless browser. It loads the WordPress render and the Drupal render with their stylesheets. It then compares the computed styles and box geometry of each visible element. A failing case names the element and the property that differ.

Markup parity stays as a diagnostic. `MarkupComparator` still runs, and a markup difference points to where a style difference comes from. ADR 0004 keeps listing the differences parity accepts.

Frozen blocks are declared exceptions (ADR 0007). The check skips them.

## Considered Options

- Keep Markup parity. Rejected: it forbids printing a different wrapper that gives the same result. That blocks running WordPress's own code where its output differs only in form.
- Compare screenshots. Rejected: a pixel difference names no cause, and font rendering makes thresholds noisy.
- Widen ADR 0004 into a list of accepted markup differences. Rejected: each entry is judged by hand, and theme CSS stays unchecked against the new markup.

## Consequences

- Theme and block stylesheets are checked against Drupal's markup, which Markup parity never did.
- The block matrix branch finishes on Markup parity. Appearance equivalence applies to work that starts after it.
- A matrix run needs a headless browser.
