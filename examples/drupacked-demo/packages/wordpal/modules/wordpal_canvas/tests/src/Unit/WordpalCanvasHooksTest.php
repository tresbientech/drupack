<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the extension points wordpal_canvas implements on wordpal's hooks.
 */
#[Group('wordpal')]
final class WordpalCanvasHooksTest extends UnitTestCase {

  /**
   * Loads the hook implementations under test.
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/wordpal_canvas.module';
  }

  /**
   * Tests that the theme-files hook adds the Canvas page-variant template.
   */
  public function testThemeFilesAlterAddsCanvasPageVariantTemplate(): void {
    $files = ['themes/custom/example/example.info.yml' => "name: Example\n"];

    wordpal_canvas_wordpal_theme_files_alter($files, 'example');

    self::assertArrayHasKey('themes/custom/example/templates/canvas-page-variant.html.twig', $files);
    self::assertStringContainsString(
      "content|without('messages')",
      $files['themes/custom/example/templates/canvas-page-variant.html.twig'],
    );
  }

}
