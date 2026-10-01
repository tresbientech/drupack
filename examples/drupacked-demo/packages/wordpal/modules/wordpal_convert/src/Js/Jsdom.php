<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Js;

use Drupal\wordpal_convert\Support\PrivateNpmPackage;

/**
 * Resolves the pinned jsdom install a Node script needs on its NODE_PATH.
 *
 * Every Node entry point that runs WordPress's own JavaScript loads it
 * through jsdom: the conversion pipeline's attribute parser and the block
 * matrix's serializer.
 */
final class Jsdom {

  /**
   * The jsdom release these Node scripts load WordPress's bundles into.
   */
  private const VERSION = 'jsdom@30.1.1';

  /**
   * Returns the NODE_PATH entry for a Node process that needs jsdom.
   *
   * Installs the pinned jsdom release into a private directory first, if no
   * install is cached there yet.
   */
  public static function nodePath(): string {
    return PrivateNpmPackage::directory(self::VERSION) . '/node_modules';
  }

}
