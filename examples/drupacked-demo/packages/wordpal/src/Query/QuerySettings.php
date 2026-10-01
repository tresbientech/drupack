<?php

declare(strict_types=1);

namespace Drupal\wordpal\Query;

use Drupal\views\ViewEntityInterface;

/**
 * The `wordpal.query` third-party setting of one Query View, by concern.
 */
final readonly class QuerySettings {

  /**
   * Keys of the pagination block's own children, present when written.
   */
  private const PAGER_CHILD_KEYS = ['previous', 'numbers', 'next', 'pagination_order'];

  private function __construct(
    public array $query,
    public array $postTemplate,
    public array $layout,
    public array $bundles,
    public array $pagerSettings,
  ) {}

  /**
   * Reads one View's `wordpal.query` setting.
   *
   * `query` and `layout` feed only the `wordpal:query` embed. A writer that
   * places no such embed leaves them defaulted to `[]`.
   *
   * @throws \UnexpectedValueException
   *   When the setting is missing `post_template`, `bundles` or
   *   `pagination`. Every writer always writes these, so a missing key is a
   *   writer or config-import fault.
   */
  public static function fromView(ViewEntityInterface $view): self {
    $settings = $view->getThirdPartySetting('wordpal', 'query', []);
    return new self(
      query: $settings['query'] ?? [],
      postTemplate: self::required($view, $settings, 'post_template'),
      layout: $settings['layout'] ?? [],
      bundles: self::required($view, $settings, 'bundles'),
      pagerSettings: self::pagerSettings($view, $settings),
    );
  }

  /**
   * Returns one always-written key, or throws naming the View and the key.
   */
  private static function required(ViewEntityInterface $view, array $settings, string $key): array {
    if (!array_key_exists($key, $settings)) {
      $viewId = $view->id();
      throw new \UnexpectedValueException("View \"$viewId\" has no \"$key\" in its wordpal.query setting.");
    }
    return $settings[$key];
  }

  /**
   * Returns the pagination slice, keeping only the children the writer set.
   */
  private static function pagerSettings(ViewEntityInterface $view, array $settings): array {
    $pager = ['pagination' => self::required($view, $settings, 'pagination')];
    foreach (self::PAGER_CHILD_KEYS as $key) {
      if (array_key_exists($key, $settings)) {
        $pager[$key] = $settings[$key];
      }
    }
    return $pager;
  }

}
