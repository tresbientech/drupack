<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

use Drupal\wordpal_convert\Support\StableUuid;

/**
 * Identifies an Owned config entity stably across a --replace reconversion.
 */
final class OwnedEntityUuid {

  /**
   * Returns the stable UUID of one config entity type's id.
   */
  public static function uuid(string $entityTypeId, string $id): string {
    return StableUuid::fromName("$entityTypeId/$id");
  }

}
