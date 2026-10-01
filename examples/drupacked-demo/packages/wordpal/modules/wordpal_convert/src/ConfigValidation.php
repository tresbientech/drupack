<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Validates a config entity against its typed config schema.
 *
 * The recipe installer runs the same validation on every config entity a
 * Site recipe creates, so a conversion stops on the values a recipe would
 * reject.
 *
 * @see \Drupal\Core\Recipe\RecipeConfigInstaller::installRecipeConfig()
 */
final class ConfigValidation {

  /**
   * Throws when the entity has constraint violations.
   */
  public static function assertValid(ConfigEntityInterface $entity): void {
    $violations = $entity->getTypedData()->validate();
    if ($violations->count() > 0) {
      throw new \UnexpectedValueException(sprintf('%s is invalid: %s', $entity->getConfigDependencyName(), $violations));
    }
  }

}
