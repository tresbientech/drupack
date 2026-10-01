<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Support\PlatformCredit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the rewrite of a theme's WordPress platform credit.
 */
#[CoversClass(PlatformCredit::class)]
#[Group('wordpal')]
final class PlatformCreditTest extends UnitTestCase {

  /**
   * Tests each credit shape found in the wordpress.org block themes.
   */
  #[DataProvider('credits')]
  public function testRewritesCredit(string $html, string $expected): void {
    self::assertSame($expected, PlatformCredit::rewriteHtml($html));
  }

  /**
   * Returns credits as themes print them, with the rewrite of each.
   */
  public static function credits(): array {
    $drupal = '<a href="https://www.drupal.org">Drupal</a>';
    return [
      'linked, Twenty Twenty-Four' => [
        '<p class="has-small-font-size">Designed with <a href="https://wordpress.org" rel="nofollow">WordPress</a></p>',
        '<p class="has-small-font-size">Designed with <s>WordPress</s>' . $drupal . '</p>',
      ],
      'plain text' => [
        '<p>Powered by WordPress</p>',
        '<p>Powered by <s>WordPress</s>' . $drupal . '</p>',
      ],
      'a theme link before it' => [
        '<p>Proudly powered by <a href="https://www.ovationthemes.com/">Ovation Themes</a> and <a href="https://WordPress.org">WordPress</a>.</p>',
        '<p>Proudly powered by <a href="https://www.ovationthemes.com/">Ovation Themes</a> and <s>WordPress</s>' . $drupal . '.</p>',
      ],
      'no space, lower case p, trailing space in the link' => [
        '<p>Designed with<a href="#" rel="nofollow">Wordpress </a></p>',
        '<p>Designed with<s>Wordpress</s>' . $drupal . ' </p>',
      ],
      'non-breaking space, strong inside the link' => [
        '<p>Proudly powered by&nbsp;<a href="https://wordpress.org/"><strong>WordPress</strong></a></p>',
        '<p>Proudly powered by&nbsp;<strong><s>WordPress</s>' . $drupal . '</strong></p>',
      ],
      'link split over lines' => [
        "<p>Site powered by <a href=\"https://wordpress.org\"\n>WordPress</a\n></p>",
        '<p>Site powered by <s>WordPress</s>' . $drupal . '</p>',
      ],
      'the rest of the sentence' => [
        '<p>© 2026 · Powered by WordPress and <a href="https://olliewp.com">Ollie</a></p>',
        '<p>© 2026 · Powered by <s>WordPress</s>' . $drupal . ' and <a href="https://olliewp.com">Ollie</a></p>',
      ],
      'German' => [
        '<p>Gestaltet mit <a href="https://de.wordpress.org" rel="nofollow">WordPress</a></p>',
        '<p>Gestaltet mit <s>WordPress</s>' . $drupal . '</p>',
      ],
      'a Button block link around the whole credit' => [
        '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://wordpress.org">Proudly Powered by WordPress</a></div>',
        '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://www.drupal.org">Proudly Powered by <s>WordPress</s>Drupal</a></div>',
      ],
    ];
  }

  /**
   * Tests that markup without a platform credit keeps its exact bytes.
   */
  #[DataProvider('notCredits')]
  public function testLeavesOtherMarkup(string $html): void {
    self::assertSame($html, PlatformCredit::rewriteHtml($html));
  }

  /**
   * Returns markup that names WordPress without crediting it as the platform.
   */
  public static function notCredits(): array {
    return [
      'theme credit' => ['<p>Copyright 2023 | WordPress Theme by <a href="http://superbthemes.com/">SuperbThemes</a></p>'],
      'powered by a theme' => ['<p>Powered By WordPress Theme</p>'],
      'blocks' => ['<p>Built with WordPress blocks.</p>'],
      'a domain' => ['<p>Powered by <a href="https://wordpress.org">WordPress.org</a></p>'],
      'marketing copy' => ['<p>Create beautiful websites with the new WordPress site editor.</p>'],
      'an attribute' => ['<p><img alt="Powered by WordPress" src="logo.png">Powered by Drupal</p>'],
      'another paragraph' => ['<div><p>Designed with</p><p>WordPress</p></div>'],
      'no WordPress, odd markup kept' => ['<P CLASS=x>Powered by <b>Drupal</P>'],
    ];
  }

}
