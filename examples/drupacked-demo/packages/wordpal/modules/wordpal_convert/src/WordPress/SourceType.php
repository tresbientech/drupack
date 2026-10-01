<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\WordPress;

/**
 * What a source installs into Playground: a theme or a plugin.
 */
enum SourceType: string {

  case Theme = 'theme';
  case Plugin = 'plugin';

  /**
   * Returns the WordPress.org information API URL for one slug.
   */
  public function informationUrl(string $slug): string {
    return sprintf(
      'https://api.wordpress.org/%1$ss/info/1.2/?action=%1$s_information&request[slug]=%2$s',
      $this->value,
      rawurlencode($slug),
    );
  }

  /**
   * Returns the capitalized label used in messages.
   */
  public function label(): string {
    return ucfirst($this->value);
  }

}
