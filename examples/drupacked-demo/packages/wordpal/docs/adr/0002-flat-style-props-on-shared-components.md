# Flat style props on shared components

Each **Component** exposes WordPress block supports as flat scalar props, such as `layout_type`, `justify_content`, `padding_top`, `font_size` and `background_color`. Fixed WordPress value sets stay enums. Values that depend on a theme's presets are strings in WordPress syntax, such as `var:preset|spacing|50` or `20px`. One component set in the `wordpal` module serves every converted **Block theme**.

Canvas rejects a component when a prop has no field type and widget, and WordPress stores styling as nested objects that have none. Flat scalar props use core field types, so Canvas accepts them and Display Builder reads them unchanged.

## Considered Options

- Flat props with enums per theme, written into each generated theme. Rejected: the component library grows by one set per converted theme.
- One `style` object prop stored by a WordPal field type and widget through `hook_canvas_storable_prop_shape_alter()`. Rejected: it needs a custom Canvas widget before any component works.
- Styles baked into a string prop at conversion time. Rejected: editors could not change styling.

## Consequences

- Editors type preset references as text. A preset picker can come later through the alter hook without changing stored values.
- Canvas component instances store these prop names. Renaming a prop needs a migration.

## Editing scope

Every flat prop stays editable. A WordPress control with no prop, such as duotone or a block style variation, keeps its imported appearance. The Conversion report names each such control per block.
