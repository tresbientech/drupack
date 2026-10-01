# Drupal-owned routes

Status: Accepted

A Drupal-owned route renders no converted output: `/user/login`, `/user/password`, the 403 text, `/user/{uid}`. WordPress has no counterpart for them. Plugin-owned pages, such as WooCommerce's My Account login, render through the theme's `page.html` there.

WordPal gives these routes the same treatment:

- The frame comes from the Template the `page` route resolves: `page`, then `singular`, then `index`.
- Post Title shows the route's title through the `wordpal_page_title` block. Canvas and core's block layout set that title on the block. Display Builder sets none, so `wordpal_display_builder_runtime` records the main content's own title for it.
- Post Content keeps its wrapper, and its `page_content` slot holds Drupal's main content. It takes no example post body.
- Blocks that print the current post are left out, as WordPress prints nothing for them without a post.
- A Cover that shows the featured image stays, without the image.
- The frame exists only when the page Template converts and holds a Post Content block outside a Frozen block. The conversion report says when it does not.

This frame departs from [ADR 0003](0003-page-variant-per-part-set.md). It holds the page Template's body, with the main content inside Post Content, in place of the "Page content" marker.

## Frame selection

Display Builder takes the frame as its fallback page layout. Every other frame then needs a condition, so each carries one `wordpal_frame_routes` condition. It matches any of the frame's paths or node bundles. Core's `request_path` and `entity_bundle` conditions cannot express that, since a page layout ANDs its conditions. The 404 route contributes the 404 page node's path.

Canvas takes the frame as `default_page_variant`. Canvas selects a frame for an entity route only, so a converted listing View records its own frame, and `wordpal_canvas_runtime` selects it for the View's route.

A frame renders only under the theme it was converted for. Under any other active theme, such as the admin theme gin_login picks for its login pages, the page gets the variant selected before the builder ran.

## Page chrome and forms

- Every frame places Drupal's status messages block right before the page content, where an editor can move it. At render, a messages block at the top level of the frame prints before `.wp-site-blocks`, so it never sits between the siblings a theme's CSS selects on, such as `header:has(+ .wp-block-cover)`. One placed inside a block stays there. A stylesheet the generated theme loads lines the messages up with the content width.
- The same stylesheet takes core's off-canvas page wrapper out of the layout. A theme's `.wp-site-blocks` height and flex rules then apply as on WordPress.
- No frame places tabs, local actions, breadcrumb or help. Drupal's Navigation top bar shows editors the Edit button and page actions.
- Front-end submit buttons take `wp-element-button`, so the theme's `elements.button` style applies. The comment form already prints it, as WordPress's comment form does.
- Front-end text inputs, selects and textareas take the input declarations of WordPress core's comment form.
- Core's skip link targets the first `<main>` element, which takes the id `main-content` when it has none. WordPress 7.0 also links its skip link to the first `<main>`. A template without `<main>` has no target, as on WordPress. A first `<main>` with its own id keeps it, and core's skip link then has no target, where WordPress links to that id.

## Considered Options

- Wrap the main content at render time for routes that are not converted. Rejected: runtime code would have to tell converted outputs apart from every other route, on each builder.
- Replace Post Content with a bare marker. Rejected: the content loses the wrapper that gives it the theme's content width.
- Split a mixed frame into one page layout per condition kind. Rejected: one frame would become several entities with the same sources.
- Wrap the messages in a constrained Group. Rejected: the Group prints on every page, empty, and adds the root block gap under the header.

## Consequences

- Drupal-owned routes show the theme's header, footer, page title and content width.
- A failed login shows its error on both builders.
- Every Display Builder frame but the Drupal-route frame carries a condition, including a frame shared by listing and node routes.
