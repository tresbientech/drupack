<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Renders a component with neither the converter nor Canvas installed.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class StandaloneComponentTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'node',
    'comment',
    'views',
    'link',
    'menu_link_content',
    'wordpal',
  ];

  /**
   * Tests that wordpal alone renders its paragraph component.
   */
  public function testParagraphRendersWithoutConverter(): void {
    $moduleHandler = $this->container->get('module_handler');
    self::assertFalse($moduleHandler->moduleExists('wordpal_convert'));
    self::assertFalse($moduleHandler->moduleExists('wordpal_canvas'));

    $build = [
      '#type' => 'component',
      '#component' => 'wordpal:paragraph',
      '#props' => ['content' => 'Standalone text'],
    ];
    $markup = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertStringContainsString('class="wp-block-paragraph">Standalone text</p>', $markup);
  }

  /**
   * Tests that a post with no body renders no post content wrapper.
   */
  public function testEmptyPostContentRendersNothing(): void {
    $build = [
      '#type' => 'component',
      '#component' => 'wordpal:post-content',
      '#props' => [],
    ];
    $markup = (string) $this->container->get('renderer')->renderInIsolation($build);

    self::assertStringNotContainsString('wp-block-post-content', $markup);
  }

  /**
   * Tests a term link with no url renders without a deprecation.
   *
   * The builder previews a Post Terms block before any term supplies a url.
   */
  public function testPostTermWithoutUrlRendersWithoutDeprecation(): void {
    $build = [
      '#type' => 'component',
      '#component' => 'wordpal:post-term',
      '#props' => ['label' => 'City'],
    ];
    set_error_handler(static function (int $level, string $message): never {
      throw new \ErrorException($message, 0, $level);
    }, E_DEPRECATED);
    try {
      $markup = (string) $this->container->get('renderer')->renderInIsolation($build);
    }
    finally {
      restore_error_handler();
    }

    self::assertStringContainsString('rel="tag">City</a>', $markup);
  }

}
