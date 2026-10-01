<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * Resolves the attributes WordPress's own parser derives from saved markup.
 */
interface AttributeSourceInterface {

  /**
   * Returns WordPress's own parsed forest for one block markup blob.
   *
   * @param string $html
   *   A complete saved block markup document: a Template's, a Template
   *   part's, or a Pattern's own content.
   *
   * @return array[]
   *   One entry per top-level block, in document order, each shaped
   *   {name: string, attributes: array, isValid: bool, innerBlocks: array[]}.
   *   A purely whitespace freeform segment is absent, as WordPress's own
   *   parser omits it.
   */
  public function forest(string $html): array;

}
