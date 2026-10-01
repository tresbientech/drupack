# Target-owned recipe configuration

Status: Proposed. Date: 2026-09-30.

## Problem

Question: why does recipe export need knowledge of both Builder targets?

[SiteRecipeWriter](../../modules/wordpal_convert/src/SiteRecipeWriter.php) reads Display Builder's full-display fields and profile settings directly. It also knows Canvas content-template properties and default-frame wiring.

These rules duplicate the writers' knowledge of target configuration. [ADR 0012](../adr/0012-writer-interface.md) records this gap.

This RFC covers four export concerns:

- Additional module requirements.
- Optional configuration imports.
- Shared configuration snapshots.
- Target-specific recipe actions.

Activation and replacement cleanup remain separate work. Shared export retains the development-module exclusion check as a deployment safeguard.

## Proposed interface

Question: how does a writer contribute its recipe configuration?

Add one method to the existing `WriterInterface`, implemented by both targets:

```php
public function recipeContribution(
  string $themeId,
  array $manifest,
  array $ownedConfig,
): array;
```

The fixed return shape is:

```php
array{
  modules: list<string>,
  imports: array<string, list<string>>,
  config: array<string, array>,
  actions: array<string, array<string, mixed>>
}
```

The method reads current configuration without changing it. `config` contains additional snapshots; `actions` contains Drupal recipe actions keyed by configuration name.

```php
$writer = $this->writers->forTarget($manifest['target']);
$owned = $this->ownedConfig($manifest);
$target = $writer->recipeContribution($themeId, $manifest, $owned);
```

The exporter explicitly combines the four named fields with common recipe data. It derives the runtime module through the existing `runtimeModule()` method.

## Ownership and composition

Question: which side owns each exported setting?

| Owner | Configuration responsibility |
|---|---|
| Canvas writer | Content-template properties and an owned default page variant's recipe action. |
| Display Builder writer | Full displays, override fields, profile import, tokens action, and target module requirements. |
| Shared exporter | Owned configuration, content export, theme/style settings, front/404 aliases, and replaced Views. |

Each configuration action name has one owner under this split. Compose complete entries without recursive merging or caller-selected merge policies.

Additional shared displays and fields remain outside the conversion ownership manifest. Recipe export must not make them deletable during replacement.

Preserve `strict: FALSE`. Target actions must still update existing destination displays while retaining unrelated settings.

## Alternatives and recommendation

Question: where should target recipe knowledge live?

| Option | Trade-off |
|---|---|
| Private helpers in the exporter | Easier navigation, but shared export still owns both target schemas. |
| Contribution on the existing writer | Reuses target selection and colocates target rules. Recommended. |
| Separate recipe contributor registry | Adds a second target registry without a separate lifecycle. |

The contribution expands the writer contract by one operation. Its scope ends at recipe data; it performs no activation or file publication.

When this misbehaves in production six months from now, the engineer will diagnose it by comparing recipe YAML with destination configuration after application.

## Dependency strategy

Question: how is this boundary tested locally?

The category is local-substitutable. Writers use Drupal configuration services; tests use kernel storage and temporary recipe directories.

The exporter still owns dependency collection, content validation, staging, and publication. Apply its development-module check to contributed configuration too.

Required configuration missing from controlled storage must fail loudly. Preserve existing handling of deleted owned content and inactive site wiring.

## Testing strategy

Question: which existing scenarios prove export behavior?

| Existing tests | Disposition |
|---|---|
| Both recipe suites' `testRecipeHoldsManifest` | Preserve generated recipe assertions. |
| Both recipe suites' `testRecipeAppliesOnFreshSite` | Preserve application without converter modules. |
| `SiteRecipeTest::testRecipeWriterBranches` | Preserve inactive wiring, invalid content, and dependency failure coverage. |

No existing tests are replaced or deleted. Extend these boundary tests for additional cases:

- Missing required shared display configuration fails before publication.
- Contributed configuration cannot depend on a development module.
- Existing destination full displays retain unrelated settings.
- Both targets emit their existing imports and actions without cross-target configuration.

Use the existing fresh-site fixtures and real recipe application. Avoid mocks for Drupal recipe execution.

## Implementation recommendations

Question: how should export ownership move?

1. Run both existing recipe suites and record expected recipe data.
2. Add the contribution contract and implement each target's export rules.
3. Replace target branches in shared export with explicit contribution composition.
4. Delete moved export helpers and target-only constants from the exporter, except the development-module safeguard.
5. Run recipe round trips and failure cases. Update ADR 0012 to distinguish resolved export gaps from remaining lifecycle gaps.

Acceptance requires equivalent recipe content and fresh-site results. This RFC introduces no manifest schema change or deployment migration.
