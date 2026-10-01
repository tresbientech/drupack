<?php

declare(strict_types=1);

namespace Drupal\wordpal\Theme;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * The class WordPress wraps the whole site's block markup in.
 */
final class SiteBlocks implements TrustedCallbackInterface {

  /**
   * The class name, matching WordPress's own wp-site-blocks wrapper.
   */
  public const SITE_BLOCKS_CLASS = 'wp-site-blocks';

  /**
   * The id core's skip link in html.html.twig points at.
   */
  public const MAIN_CONTENT_ID = 'main-content';

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['markMainContent'];
  }

  /**
   * Gives the first <main> element the skip link's target id.
   *
   * WordPress 7.0 gives the first <main> of a block template an id and links
   * its skip link to it. A <main> that carries an id keeps it, and markup
   * without a <main> stays unchanged. Used as a #post_render callback on the
   * content inside the wp-site-blocks wrapper.
   */
  public static function markMainContent(string $markup): string {
    if (!preg_match('/<main\b[^>]*>/i', $markup, $match, PREG_OFFSET_CAPTURE) || preg_match('/\sid\s*=/i', $match[0][0])) {
      return $markup;
    }
    return substr_replace($markup, '<main id="' . self::MAIN_CONTENT_ID . '"', $match[0][1], strlen('<main'));
  }

}
