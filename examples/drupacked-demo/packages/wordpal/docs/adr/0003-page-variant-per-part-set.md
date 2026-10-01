# One page variant per part set

A **Conversion** writes one Canvas `PageVariant` for each distinct **Part set**. The variant holds the **Template parts** around a "Page content" marker. Each **Template** puts its main area where Canvas renders page content. For a single post, that is the post `ContentTemplate`, which selects the variant. For the home **Template**, it is a Canvas Page set as the front page.

WordPress shares a **Template part** by reference, so one header edit reaches every **Template**. A `PageVariant` has no shared part. A variant per **Part set** keeps one copy of each part for all **Templates** that use the same set. Twenty Twenty-Four home and single share one variant.

Canvas 1.11.0 deprecated `PageRegion`, and removes it in 2.0.0.

## Considered Options

- One `PageVariant` per **Template** file. Rejected: each variant holds its own header and footer copy, so a header edit must be repeated per variant.
- Home main area as a Canvas `Pattern` placed by a site builder. Rejected: the front page would not match the **Reference render** after a **Conversion**.

## Consequences

- A **Conversion** creates a Canvas Page, which is content, besides configuration.
- A **Template** with a different **Part set**, such as a sidebar layout, adds a variant with its own copy of the shared header and footer.
