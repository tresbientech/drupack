# Component generation boundary

Status: Proposed. Date: 2026-09-30.

## Problem

Question: why are component-generation tests coupled to command internals?

[ComponentCommands](../../modules/wordpal_convert/src/Drush/Commands/ComponentCommands.php) coordinates definition generation and file output inside its command loop. Tests invoke private hook and CSS methods through reflection.

The actual operation has an ordering constraint. The alter hook reads existing component YAML before generation overwrites that file.

The boundary covers four concerns:

- Source and parent-definition loading.
- Schema generation.
- Runtime-prop alteration through the existing hook.
- YAML and component CSS output.

Release acquisition and release-wide assets stay with the command. Discovery, eligibility checks, and aggregate reporting also remain there.

## Proposed interface

Question: how can callers generate one component through an existing module?

Add a file-generation operation to the existing `DefinitionGenerator`. Retain its pure `generate()` operation and existing tests.

```php
public function writeComponent(
  string $slug,
  string $blocksDirectory,
  string $componentDirectory,
  ModuleHandlerInterface $moduleHandler,
): array;
```

Return the unchanged `list<string>` of skipped attributes and supports. `$componentDirectory` is the existing directory for one eligible component.

```php
$skippedItems["core/$slug"] = $this->generator->writeComponent(
  $slug, $blocksDirectory, $directory, $this->moduleHandler,
);
$written[] = $slug;
```

Pass the module handler to this operation so pure schema generation keeps its current construction requirements. The method performs the complete per-component operation.

## Alternatives and recommendation

Question: which design removes reflection tests without adding a wrapper?

| Option | Trade-off |
|---|---|
| Public operation on the Drush command | Retains command dependencies in tests of component output. |
| File-generation operation on `DefinitionGenerator` | Expands an existing module while retaining direct pure-schema tests. Recommended. |
| Dedicated generation service | Separates filesystem work, but adds a service for one caller and delegates schema generation. |

The recommended design makes the generator responsible for completing its output. Its trade-off is knowledge of Drupal hooks and file output alongside pure schema logic.

When this misbehaves in production six months from now, the engineer will diagnose it by comparing input definitions and prior YAML with emitted files.

## Dependency strategy

Question: how are hooks and files exercised?

The category is local-substitutable. Use temporary directories, the real schema generator, and a controlled `ModuleHandlerInterface` in boundary tests.

Preserve the sequence:

1. Load the source definition and required parent definitions.
2. Generate the schema.
3. Invoke `alter('wordpal_component_definition', $definition, $slug)` once.
4. Encode and overwrite component YAML.
5. Copy `style.css`, or remove stale component CSS when that source file is absent.
6. Return skipped items.

The existing hook and runtime-prop overlay remain authoritative. The hook receives the bare slug and retains module-handler ordering.

Tests of the actual hook must point `ComponentSet` at the same temporary tree. Preserve required-input failures and command-level skipped-component reporting.

## Testing strategy

Question: which tests move to the public operation?

Move these methods from `ComponentCommandsTest` to `ComponentFileGenerationTest`. Call `DefinitionGenerator::writeComponent()` and retain each method name.

| Existing method | Replacement assertion |
|---|---|
| `testAltersComponentDefinitionThroughModuleHandler` | Same hook arguments; prior YAML remains readable during alteration; hook mutation reaches output YAML. |
| `testCopiesBlockStyleCssButNotThemeCss` | Exact CSS header, content, and trailing newline; theme CSS stays excluded. |
| `testRemovesStaleBlockCssWithNoStyleCss` | Theme-only source removes stale destination CSS. |

Preserve the schema generator tests and runtime-prop overlay tests. Preserve hook tests and the Canvas kernel test for generation without Canvas dependencies.

Keep command script-module tests and their failure datasets unchanged. Add a public-operation case for parent context and returned skipped items.

Each replacement must pass before its old method is removed in the same change. No tests are classified as trivia.

## Implementation recommendations

Question: how should the command adopt the boundary?

1. Run the current generation, overlay, and command unit tests.
2. Add the public operation and temporary-directory tests, with existing YAML fixtures.
3. Replace the command's per-component orchestration with one call and retain aggregate reporting.
4. Remove moved private hook/CSS helpers and their obsolete reflection calls. Keep helpers still used for release metadata.
5. Run affected unit and hook/kernel tests. Compare emitted YAML/CSS against fixed fixture expectations.

The refactor preserves generated file contents and hook timing. Atomic multi-file publication and release-wide asset changes require separate proposals.
