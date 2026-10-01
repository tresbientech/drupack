# Display Builder: declare the placeholder component's props schema

Draft issue for the Display Builder queue. Tested on 1.0.0-beta7 with UI Patterns 2.0.x at e47d09e and Canvas 1.11.0.

## Problem

`components/placeholder/placeholder.component.yml` declares no `props`. Core requires a props schema for every component a module declares. UI Patterns' `plugin.manager.sdc` decorator fills in a missing schema. Canvas replaces that manager's class, and UI Patterns 2.0.x (#3625036) then leaves it undecorated.

## Steps to reproduce

1. Install Drupal CMS 2.2.0, which enables Canvas.
2. Require Display Builder 1.0.0-beta7 and UI Patterns 2.0.x-dev.
3. Render a page layout whose builder holds an empty slot.

Result:

```
Drupal\Core\Render\Component\Exception\InvalidComponentException: The component
"display_builder:placeholder" does not provide schema information. Schema
definitions are mandatory for components declared in modules.
```

## Proposed fix

Declare an empty props object:

```yaml
props:
  type: object
  properties: {}
```

The patch is `patches/display_builder-placeholder-props-schema.patch` in the WordPal repository.
