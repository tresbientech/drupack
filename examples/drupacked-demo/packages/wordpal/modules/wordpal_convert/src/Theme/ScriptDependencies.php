<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * Decides which theme scripts the generated theme exports.
 *
 * A theme script exports when each dependency has a Drupal library:
 * WordPress's jquery handle maps to core's jQuery, and another exported
 * theme script maps to its own library. A script with any other dependency
 * is skipped, and so is a script depending on a skipped one.
 */
final class ScriptDependencies {

  /**
   * The WordPress handles with a Drupal core library, keyed by handle.
   */
  private const CORE_LIBRARIES = ['jquery' => 'core/jquery'];

  /**
   * Resolves each theme script's dependencies to Drupal libraries.
   *
   * @param array<int, array{handle: string, dependencies: string[]}> $scripts
   *   Theme scripts in WordPress's print order, which lists a dependency
   *   before the scripts needing it.
   * @param string $themeId
   *   The generated theme's machine name.
   *
   * @return array{libraries: array<string, string[]>, skipped: array<string, string>}
   *   The Drupal library dependencies of each exported script, and the
   *   reason each skipped script is left out, both keyed by handle.
   */
  public static function resolve(array $scripts, string $themeId): array {
    $libraries = [];
    $skipped = [];
    foreach ($scripts as $script) {
      $dependencies = [];
      foreach ($script['dependencies'] as $dependency) {
        $library = self::CORE_LIBRARIES[$dependency] ?? (isset($libraries[$dependency]) ? "$themeId/" . ThemeGenerator::scriptLibrary($dependency) : NULL);
        if ($library === NULL) {
          $skipped[$script['handle']] = isset($skipped[$dependency])
            ? "needs theme script $dependency, which is skipped"
            : "needs $dependency, which has no Drupal library";
          continue 2;
        }
        $dependencies[] = $library;
      }
      $libraries[$script['handle']] = $dependencies;
    }
    return ['libraries' => $libraries, 'skipped' => $skipped];
  }

}
