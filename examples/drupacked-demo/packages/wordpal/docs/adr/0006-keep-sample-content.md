# Keep Sample content

A **Conversion** keeps **Sample content** in every tree: Templates, Template parts and Patterns. An inserted Canvas Pattern brings the theme's text, links and images, as WordPress inserts them.

Milestone 1 stripped it, so converted pages showed empty headings and image slots. A converted front page did not resemble the theme, and the home page lost three figures and the footer credit link against its **Reference render**. WordPress itself inserts a pattern with its sample copy, and editors replace it.

## Considered Options

- Strip Sample content in every tree, as milestone 1 and Phase 10 did. Rejected: the converted site looks unlike the theme until an editor fills every section.
- Keep it in Templates and strip it in Patterns. Rejected: the same section differs between the front page and the pattern library.

## Consequences

- Theme marketing copy reaches the site. Editors replace it.
- Pattern images load from the generated theme's assets directory, where the snapshot already points them.
- Markup parity still ignores text, so parity results do not change.
