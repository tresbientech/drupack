# UI Patterns: keep a `"0"` string prop value

Draft issue for the UI Patterns queue. Tested on 2.0.x at e47d09e, the current head.

## Problem

`ComponentElementBuilder::isPropValueEmpty()` tests string and other props with `empty($data)`. PHP's `empty()` is TRUE for the string `"0"`, so the builder drops a prop whose value is `"0"`. A CSS length of `0`, such as a padding, never reaches the component.

## Steps to reproduce

1. Declare a string prop on a component, for example `padding_right`.
2. Set it to `0` through the `textfield` source.
3. Render the component.

Result: the component receives no `padding_right` prop.

## Proposed fix

Treat only a missing, empty string or empty array value as empty:

```php
default => $data === NULL || $data === '' || $data === [],
```

The patch is `patches/ui_patterns-zero-prop-value.patch` in the WordPal repository.
