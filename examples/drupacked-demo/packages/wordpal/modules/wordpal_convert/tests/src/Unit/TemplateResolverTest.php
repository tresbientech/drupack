<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Theme\TemplateResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the WordPress Template hierarchy for each converted route.
 */
#[CoversClass(TemplateResolver::class)]
#[Group('wordpal')]
final class TemplateResolverTest extends UnitTestCase {

  /**
   * Tests a theme with every Template gets each route's own Template.
   */
  public function testEachRouteUsesItsOwnTemplate(): void {
    $names = ['404', 'archive', 'home', 'index', 'page', 'search', 'single', 'page-wide', 'single-with-sidebar'];
    self::assertSame([
      'home' => 'home',
      'single' => 'single',
      'page' => 'page',
      'tag' => 'archive',
      'category' => 'archive',
      'archive' => 'archive',
      'search' => 'search',
      'not_found' => '404',
    ], TemplateResolver::resolve($names));
  }

  /**
   * Tests a theme with only index.html uses it for every route.
   */
  public function testIndexIsTheLastFallback(): void {
    self::assertSame(array_fill_keys(['home', 'single', 'page', 'tag', 'category', 'archive', 'search', 'not_found'], 'index'), TemplateResolver::resolve(['index']));
  }

  /**
   * Tests a theme lacking archive.html uses index.html for term pages.
   */
  public function testArchiveFallsBackToIndex(): void {
    self::assertSame('index', TemplateResolver::resolve(['index', 'single', 'page', 'search', '404'])['archive']);
  }

  /**
   * Tests term routes take their vocabulary's Template before archive.
   */
  public function testTermTemplatesPrecedeArchive(): void {
    $resolved = TemplateResolver::resolve(['archive', 'category', 'index', 'tag', 'taxonomy']);
    self::assertSame('tag', $resolved['tag']);
    self::assertSame('category', $resolved['category']);
    self::assertSame('taxonomy', $resolved['archive']);
  }

  /**
   * Tests the 404 route's Template name.
   */
  public function testNotFoundTemplateName(): void {
    self::assertSame('404', TemplateResolver::templateName('not_found'));
    self::assertSame('archive', TemplateResolver::templateName('archive'));
  }

  /**
   * Tests the intermediate fallbacks WordPress applies before index.
   */
  public function testIntermediateFallbacks(): void {
    $resolved = TemplateResolver::resolve(['front-page', 'home', 'singular', 'single-post', 'index']);
    self::assertSame('front-page', $resolved['home']);
    self::assertSame('single-post', $resolved['single']);
    self::assertSame('singular', $resolved['page']);
  }

  /**
   * Tests a route none of whose candidates exist is left out.
   */
  public function testUnresolvedRouteIsLeftOut(): void {
    self::assertSame(['home' => 'home', 'single' => 'single'], TemplateResolver::resolve(['home', 'single']));
  }

}
