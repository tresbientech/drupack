# Display Builder: inject UI Patterns' component manager, not `plugin.manager.sdc`

Draft issue for the Display Builder queue. Tested on 1.0.0-beta7 with UI Patterns 2.0.x at e47d09e and Canvas 1.11.0.

## Problem

Four places ask the container for `plugin.manager.sdc` and type the result as `Drupal\ui_patterns\ComponentPluginManager`:

- `display_builder.services.yml`: the `ComponentLibraryDefinitions` argument
- `src/Controller/ApiPreviewController.php`: the `#[Autowire]` attribute
- `src/Island/IslandPluginBase.php`: `create()`
- `src/Plugin/UiPatterns/Source/ComponentSource.php`: `create()`

UI Patterns decorates `plugin.manager.sdc`. Canvas replaces the same service's class with `Drupal\canvas\Plugin\ComponentPluginManager`. UI Patterns 2.0.x (#3625036) stops decorating when another module replaced the class. With Canvas enabled, `plugin.manager.sdc` is then Canvas's manager.

## Steps to reproduce

1. Install Drupal CMS 2.2.0, which enables Canvas.
2. Require Display Builder 1.0.0-beta7 and UI Patterns 2.0.x-dev.
3. Save a `display_builder.pattern_preset` entity, for example by applying a recipe that holds one.

Result:

```
TypeError: Drupal\display_builder\Plugin\UiPatterns\Source\ComponentSource::__construct():
Argument #12 ($componentManager) must be of type Drupal\ui_patterns\ComponentPluginManager,
Drupal\canvas\Plugin\ComponentPluginManager given
```

The builder UI fails the same way in the component library, an island and the preview controller.

## Proposed fix

Inject `plugin.manager.ui_patterns_component`, the service UI Patterns registers for its own manager, in all four places. The patch is `patches/display_builder-ui-patterns-component-manager.patch` in the WordPal repository.
