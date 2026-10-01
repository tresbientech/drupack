<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_canvas_runtime\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Theme\SiteBlocks;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal_canvas_runtime\Hook\PageVariantHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Canvas page variant keeps Drupal's chrome out of the sibling chain.
 */
#[CoversClass(PageVariantHooks::class)]
#[Group('wordpal')]
final class PageVariantHooksTest extends UnitTestCase {

  /**
   * Tests a top-level messages block moves to content.messages.
   */
  public function testTopLevelMessagesBlockMovesBeforeTheWrapper(): void {
    $messages = $this->container('system_messages_block');
    $root = [
      'header' => $this->container('header'),
      'messages' => $messages,
      'main' => $this->container('main'),
    ];
    $variables = ['content' => ['root' => $root]];

    $this->hooks(TRUE)->preprocessCanvasPageVariant($variables);

    self::assertSame($messages, $variables['content']['messages']);
    self::assertSame(['header', 'main'], array_keys($variables['content']['root']));
    self::assertContains([SiteBlocks::class, 'markMainContent'], $variables['content']['#post_render']);
  }

  /**
   * Tests a messages block an editor placed inside a component stays there.
   */
  public function testNestedMessagesBlockStays(): void {
    $group = $this->container('group');
    $group['#component']['#slots']['content']['messages'] = $this->container('system_messages_block');
    $variables = ['content' => ['root' => ['group' => $group]]];

    $this->hooks(TRUE)->preprocessCanvasPageVariant($variables);

    self::assertArrayNotHasKey('messages', $variables['content']);
    self::assertSame($group, $variables['content']['root']['group']);
  }

  /**
   * Tests a theme no conversion wrote keeps Canvas's own page.
   */
  public function testUnconvertedThemeIsUntouched(): void {
    $variables = ['content' => ['root' => ['messages' => $this->container('system_messages_block')]]];
    $before = $variables;

    $this->hooks(FALSE)->preprocessCanvasPageVariant($variables);

    self::assertSame($before, $variables);
  }

  /**
   * Returns a Canvas component container whose component has a plugin id.
   */
  private function container(string $pluginId): array {
    return ['#type' => 'component_container', '#component' => ['#theme' => 'block', '#plugin_id' => $pluginId]];
  }

  /**
   * Returns the hooks with the active theme converted or not.
   */
  private function hooks(bool $converted): PageVariantHooks {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('isNew')->willReturn(!$converted);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $activeTheme = $this->createMock(ActiveTheme::class);
    $activeTheme->method('getName')->willReturn('variations');
    $themeManager = $this->createMock(ThemeManagerInterface::class);
    $themeManager->method('getActiveTheme')->willReturn($activeTheme);
    return new PageVariantHooks(new ThemeSettings($factory, $themeManager));
  }

}
