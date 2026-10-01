<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\views\pager;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsPager;
use Drupal\views\Plugin\views\pager\Full;
use Drupal\wordpal\Query\QuerySettings;

/**
 * Renders a Query View's full pager as the WordPress Query Pagination block.
 *
 * The View's wordpal.query setting holds the pagination block's own
 * settings; the page state stays core's pager.
 */
#[ViewsPager(
  id: 'wordpal_query',
  title: new TranslatableMarkup('WordPal Query pagination'),
  short_title: new TranslatableMarkup('Query pagination'),
  help: new TranslatableMarkup("The full pager, printed as the converted theme's Query Pagination block."),
  theme: 'pager',
  register_theme: FALSE,
)]
final class QueryPager extends Full {

  /**
   * {@inheritdoc}
   */
  public function render($input) {
    return [
      '#theme' => 'pager__wordpal_query_settings',
      '#wordpal_settings' => QuerySettings::fromView($this->view->storage)->pagerSettings,
    ] + parent::render($input);
  }

}
