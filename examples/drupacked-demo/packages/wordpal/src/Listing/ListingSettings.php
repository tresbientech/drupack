<?php

declare(strict_types=1);

namespace Drupal\wordpal\Listing;

use Drupal\views\ViewEntityInterface;

/**
 * The `wordpal.listing` third-party setting of one listing View, by concern.
 */
final readonly class ListingSettings {

  private function __construct(
    public string $kind,
    public array $settings,
  ) {}

  /**
   * Reads one View's `wordpal.listing` setting.
   *
   * @throws \UnexpectedValueException
   *   When the setting is missing `kind` or `settings`. The listing writer
   *   always writes both, so a missing key is a writer or config-import
   *   fault.
   */
  public static function fromView(ViewEntityInterface $view): self {
    $listing = $view->getThirdPartySetting('wordpal', 'listing', []);
    return new self(
      kind: self::required($view, $listing, 'kind'),
      settings: self::required($view, $listing, 'settings'),
    );
  }

  /**
   * Returns one always-written key, or throws naming the View and the key.
   */
  private static function required(ViewEntityInterface $view, array $listing, string $key) {
    if (!array_key_exists($key, $listing)) {
      $viewId = $view->id();
      throw new \UnexpectedValueException("View \"$viewId\" has no \"$key\" in its wordpal.listing setting.");
    }
    return $listing[$key];
  }

  /**
   * Returns the ListingRows method rendering one row of this listing's kind.
   */
  public function method(): string {
    return match ($this->kind) {
      'latest_posts' => 'latestPost',
      'page_list' => 'page',
      'latest_comments' => 'comment',
    };
  }

  /**
   * Returns one listing kind's default settings.
   */
  public static function defaults(string $kind): array {
    return match ($kind) {
      'latest_posts' => [
        'bundle' => '',
        'posts_to_show' => 5,
        'order' => 'desc',
        'order_by' => 'date',
        'display_post_date' => FALSE,
        'display_author' => FALSE,
        'post_layout' => 'list',
        'columns' => 3,
        'css_class' => '',
        'display_post_content' => FALSE,
        'excerpt_length' => 55,
        'display_featured_image' => FALSE,
        'featured_image_size_slug' => 'thumbnail',
        'featured_image_size_width' => NULL,
        'featured_image_size_height' => NULL,
        'featured_image_align' => '',
        'add_link_to_featured_image' => FALSE,
        'excerpt_field' => '',
        'image_field' => '',
        'font_size' => '',
        'font_family' => '',
        'line_height' => '',
        'font_weight' => '',
        'font_style' => '',
        'text_transform' => '',
        'text_decoration' => '',
        'letter_spacing' => '',
        'background_color' => '',
        'text_color' => '',
        'gradient' => '',
        'link_color' => '',
        'padding_top' => '',
        'padding_right' => '',
        'padding_bottom' => '',
        'padding_left' => '',
        'margin_top' => '',
        'margin_right' => '',
        'margin_bottom' => '',
        'margin_left' => '',
      ],
      'page_list' => [
        'bundle' => '',
        'css_class' => '',
      ],
      'latest_comments' => [
        'field_name' => '',
        'comments_to_show' => 5,
        'display_avatar' => TRUE,
        'display_date' => TRUE,
        'display_content' => 'excerpt',
        'css_class' => '',
      ],
    };
  }

}
