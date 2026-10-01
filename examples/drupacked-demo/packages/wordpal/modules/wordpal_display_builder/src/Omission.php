<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder;

/**
 * How a content template omits a bound block (RuntimeBindings::omission()).
 *
 * Silent omits the block with no report. MissingImage is a Post Featured
 * Image whose bundle has no image field, reported as a dropped block.
 */
enum Omission {

  case Silent;
  case MissingImage;

}
