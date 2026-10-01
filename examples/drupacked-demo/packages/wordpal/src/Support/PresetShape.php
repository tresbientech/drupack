<?php

declare(strict_types=1);

namespace Drupal\wordpal\Support;

/**
 * The shape a preset prop stores its value in.
 */
enum PresetShape {

  case Slug;
  case Reference;

}
