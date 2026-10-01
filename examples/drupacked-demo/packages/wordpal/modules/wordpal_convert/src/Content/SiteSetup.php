<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Content;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;

/**
 * Creates the default mapping's targets by applying the WordPal recipe.
 */
final class SiteSetup {

  public function __construct(
    private readonly ContentMappingTargetValidator $validator,
    private readonly string $recipePath,
  ) {}

  /**
   * Builds site setup around the recipe that ships in the wordpal package.
   */
  public static function fromPackage(ContentMappingTargetValidator $validator, ModuleExtensionList $moduleList, string $appRoot): self {
    return new self($validator, $appRoot . '/' . $moduleList->getPath('wordpal') . '/recipes/wordpal');
  }

  /**
   * Applies the WordPal recipe when any of the mapping's targets is missing.
   *
   * Applying a recipe can install modules, which rebuilds the container.
   * Services fetched before this call keep the old definitions.
   *
   * @return string|null
   *   The applied recipe directory, or NULL when every target exists.
   *
   * @throws \RuntimeException
   *   When targets are still missing after the recipe.
   */
  public function ensureTargets(ContentMapping $mapping): ?string {
    try {
      $this->validator->validate($mapping, TRUE);
      return NULL;
    }
    catch (\InvalidArgumentException) {
      // The recipe below creates the missing targets.
    }
    RecipeRunner::processRecipe(Recipe::createFromDirectory($this->recipePath));
    // A View the recipe imports, such as the search page, only marks the
    // router for a rebuild at the end of the request.
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    \Drupal::service('router.builder')->rebuildIfNeeded();
    try {
      // The injected validator's entity services cache bundles and fields
      // per instance, and a module install replaced them in the container.
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      \Drupal::service(ContentMappingTargetValidator::class)->validate($mapping, TRUE);
    }
    catch (\InvalidArgumentException $remaining) {
      throw new \RuntimeException("The WordPal recipe at {$this->recipePath} left targets missing. Create them, or convert with --mapping.\n" . $remaining->getMessage());
    }
    return $this->recipePath;
  }

  /**
   * Returns where Composer installs recipes, beside the Drupal root.
   *
   * Core resolves a recipe's dependencies from its parent directory, so a
   * Site recipe applies only from the directory holding the Drupal CMS recipes.
   */
  public static function recipesDirectory(string $appRoot): string {
    return dirname($appRoot) . '/recipes';
  }

}
