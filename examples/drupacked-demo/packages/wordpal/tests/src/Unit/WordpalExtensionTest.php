<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Render\Element\HtmlTag;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\filter\Render\FilteredMarkup;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Support\BlockSupports;
use Drupal\wordpal\Support\LayoutCss;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal\Twig\WordpalExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the post-content filtering boundary and the timezone-aware date.
 */
#[CoversClass(WordpalExtension::class)]
#[Group('wordpal')]
final class WordpalExtensionTest extends UnitTestCase {

  /**
   * Builds a WordpalExtension around the given renderer and date formatter.
   */
  private function extension(?RendererInterface $renderer = NULL, ?DateFormatterInterface $dateFormatter = NULL): WordpalExtension {
    $themeSettings = new ThemeSettings($this->createMock(ConfigFactoryInterface::class), $this->createMock(ThemeManagerInterface::class));
    return new WordpalExtension(
      new BlockSupports(new LayoutCss($themeSettings), $themeSettings, $this->createMock(ComponentPluginManager::class)),
      $renderer ?? $this->createMock(RendererInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $dateFormatter ?? $this->createMock(DateFormatterInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(ThemeExtensionList::class),
      $this->createMock(RouteMatchInterface::class),
      new RequestStack(),
      $themeSettings,
      '/app',
    );
  }

  /**
   * Tests that a format-allowed style attribute survives, unlike filterAdmin.
   *
   * Xss::filterAdmin() always strips the style attribute, so a survival
   * here proves postContent() never runs the already-filtered processed
   * text through it a second time.
   */
  public function testPostContentKeepsMarkupFilterAdminWouldStrip(): void {
    $html = (string) $this->extension()->postContent(FilteredMarkup::create('<p style="color:red">Hello</p>'));
    self::assertStringContainsString('style="color:red"', $html);
    self::assertStringContainsString('wp-block-paragraph', $html);
  }

  /**
   * Tests that a plain string, which no text format filtered, is filtered.
   */
  public function testPostContentFiltersPlainString(): void {
    $html = (string) $this->extension()->postContent('<p>Hello</p><img src="x" onerror="alert(1)"><script>alert(2)</script>');
    self::assertStringNotContainsString('onerror', $html);
    self::assertStringNotContainsString('<script', $html);
    self::assertStringContainsString('<p class="wp-block-paragraph">Hello</p>', $html);
  }

  /**
   * Tests that the paragraph class is added without disturbing other markup.
   */
  public function testPostContentAddsParagraphClassToExistingClasses(): void {
    $html = (string) $this->extension()->postContent(FilteredMarkup::create('<p class="intro">Hello</p><p>Again</p>'));
    self::assertStringContainsString('class="intro wp-block-paragraph"', $html);
    self::assertStringContainsString('<p class="wp-block-paragraph">Again</p>', $html);
  }

  /**
   * Tests that the layout rules reach the page head with ">" unescaped.
   *
   * Each rule carries the "html" prefix that lets it win ties against the
   * theme stylesheets printed after it.
   */
  public function testLayoutStylesheetKeepsChildCombinator(): void {
    $captured = [];
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('render')->willReturnCallback(function (array &$build) use (&$captured) {
      $captured = $build;
      return '';
    });
    $this->extension($renderer)->attributes('core/group', ['block_gap' => '0px']);
    $element = HtmlTag::preRenderHtmlTag($captured['#attached']['html_head'][0][0] + ['#attributes' => []]);
    $css = (string) $element['#markup'];
    self::assertMatchesRegularExpression('/^html \.wp-container-core-group-is-layout-[0-9a-f]{8} > \* \+ \*\{/m', str_replace('}', "}\n", $css));
    self::assertStringNotContainsString('&gt;', $css);
  }

  /**
   * Tests that date() formats through DateFormatter and bubbles 'timezone'.
   */
  public function testDateBubblesTimezoneCacheContext(): void {
    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->expects(self::once())
      ->method('format')
      ->with(1705314600, 'custom', 'F j, Y')
      ->willReturn('January 15, 2024');
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->expects(self::once())
      ->method('render')
      ->with(['#cache' => ['contexts' => ['timezone']]]);
    $formatted = $this->extension($renderer, $dateFormatter)->date(1705314600, 'F j, Y');
    self::assertSame('January 15, 2024', $formatted);
  }

  /**
   * Tests that a missing background image builds no declarations.
   */
  public function testBackgroundStyleIsEmptyWithoutImage(): void {
    self::assertSame([], $this->extension()->backgroundStyle(NULL, NULL, NULL, NULL, NULL));
  }

  /**
   * Tests that size defaults to "cover" and position stays unset with it.
   */
  public function testBackgroundStyleDefaultsSizeToCover(): void {
    $style = $this->extension()->backgroundStyle('image.jpg', NULL, NULL, NULL, NULL);
    self::assertSame('cover', $style['background-size']);
    self::assertArrayNotHasKey('background-position', $style);
  }

  /**
   * Tests that position defaults to "50% 50%" only for "contain" with none.
   */
  public function testBackgroundStyleDefaultsPositionForContainWithoutPosition(): void {
    $style = $this->extension()->backgroundStyle('image.jpg', 'contain', NULL, NULL, NULL);
    self::assertSame('50% 50%', $style['background-position']);
  }

}
