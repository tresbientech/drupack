<?php

declare(strict_types=1);

namespace Drupal\wordpal\Theme;

/**
 * The View tags a conversion writes to mark its own output.
 */
final class ViewTags {

  /**
   * The tag a converted Query's listing or empty-state View carries.
   */
  public const QUERY = 'wordpal_query';

  /**
   * The tag a converted archive, tag, category or search View carries.
   */
  public const TEMPLATE = 'wordpal_template';

  /**
   * The tag a converted latest-posts, page-list or comments View carries.
   */
  public const LISTING = 'wordpal_listing';

}
