<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Js\WordPressScripts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the editor script load order a WordPress release resolves to.
 */
#[CoversClass(WordPressScripts::class)]
#[Group('wordpal')]
final class WordPressScriptsTest extends UnitTestCase {

  /**
   * Tests that each script loads after its dependencies, once.
   */
  public function testScriptsLoadAfterTheirDependencies(): void {
    $root = sys_get_temp_dir() . '/wordpal-scripts-' . bin2hex(random_bytes(4));
    (new Filesystem())->dumpFile("$root/wp-includes/assets/script-loader-packages.php", "<?php return " . var_export([
      'block-library.js' => ['dependencies' => ['react', 'wp-blocks', 'wp-element']],
      'blocks.js' => ['dependencies' => ['wp-element', 'wp-polyfill']],
      'element.js' => ['dependencies' => ['react']],
    ], TRUE) . ';');

    try {
      $dist = "$root/wp-includes/js/dist";
      self::assertSame([
        "$dist/vendor/react.js",
        "$dist/element.js",
        "$dist/vendor/wp-polyfill.js",
        "$dist/blocks.js",
        "$dist/block-library.js",
      ], WordPressScripts::forRoot($root));
    }
    finally {
      (new Filesystem())->remove($root);
    }
  }

}
