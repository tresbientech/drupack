<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Support\FrozenHtmlCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the check that guards Frozen block HTML.
 */
#[CoversClass(FrozenHtmlCheck::class)]
#[Group('wordpal')]
final class FrozenHtmlCheckTest extends UnitTestCase {

  /**
   * Tests that rendered block markup with no script construct passes.
   */
  #[DataProvider('safeHtml')]
  public function testAcceptsSafeHtml(string $html): void {
    self::assertNull(FrozenHtmlCheck::problem($html));
  }

  /**
   * Returns HTML the check accepts.
   */
  public static function safeHtml(): array {
    return [
      'empty' => [''],
      'text' => ['<p class="has-text-align-center">Café “quoted”</p>'],
      'interactive block' => [
        '<div class="wc-block-mini-cart" style="visibility:hidden" data-wp-interactive="woocommerce/mini-cart"><button aria-label="Cart"><svg viewBox="0 0 32 32"><circle cx="12" cy="26" r="2"></circle></svg></button></div>',
      ],
      'data image' => ['<img src="data:image/png;base64,AAAA" srcset="data:image/png;base64,AAAA 2x" alt="">'],
      'style element' => ['<style>.a{background:url(/bg.png)}</style><div class="a"></div>'],
      'css data images' => [
        '<style>.a{background-image:url(\'data:image/png;base64,AAAA\')}.b{mask:url("data:image/svg+xml,%3Csvg%3E%3C/svg%3E")}</style>'
        . '<div class="a" style="background: url( data:image/webp;base64,AAAA )"></div>',
      ],
      'link' => ['<a href="https://example.com/metadata:x" title="Metadata">Link</a>'],
      'unclosed table cell' => ['<table><tr><td>Cell'],
      'utf-8 charset' => ['<meta charset="utf-8"><section class="guten-section"></section>'],
    ];
  }

  /**
   * Tests that each construct that can run script is rejected.
   */
  #[DataProvider('unsafeHtml')]
  public function testRejectsUnsafeHtml(string $html, string $problem): void {
    self::assertSame($problem, FrozenHtmlCheck::problem($html));
  }

  /**
   * Returns HTML the check rejects, with the reason it gives.
   */
  public static function unsafeHtml(): array {
    return [
      'script' => ['<p>x</p><script>alert(1)</script>', 'holds the element script'],
      'svg script' => ['<svg><script>alert(1)</script></svg>', 'holds the element script'],
      'iframe' => ['<iframe src="https://example.com"></iframe>', 'holds the element iframe'],
      'object' => ['<object data="x.swf"></object>', 'holds the element object'],
      'embed' => ['<embed src="x.swf">', 'holds the element embed'],
      'refresh' => ['<meta http-equiv="refresh" content="0;url=https://example.com">', 'holds the element meta'],
      'other charset' => ['<meta charset="utf-7">', 'holds the element meta'],
      'charset with content' => ['<meta charset="utf-8" content="x">', 'holds the element meta'],
      'noscript' => [
        '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
        'holds the element noscript',
      ],
      'template' => [
        '<div><template shadowrootmode="open"><script>alert(1)</script></template></div>',
        'holds the element template',
      ],
      'handler' => ['<img src="/x.png" onerror="alert(1)">', 'holds the event handler attribute onerror'],
      'svg handler' => ['<svg onload="alert(1)"></svg>', 'holds the event handler attribute onload'],
      'body handler' => ['<body onload="alert(1)">', 'sets attributes on the body or html element'],
      'javascript href' => ['<a href="javascript:alert(1)">x</a>', 'holds a javascript: URL in href'],
      'entity split scheme' => ['<a href=" jav&#x09;ascript:alert(1)">x</a>', 'holds a javascript: URL in href'],
      'vbscript' => ['<a href="VBScript:msgbox">x</a>', 'holds a vbscript: URL in href'],
      'data link' => ['<a href="data:text/html,x">x</a>', 'holds a data: URL in href'],
      'data svg use' => ['<svg><use href="data:image/svg+xml,x"></use></svg>', 'holds a data: URL in href'],
      'svg animation' => [
        '<svg><a><animate attributeName="href" to="javascript:alert(1)"></animate></a></svg>',
        'holds an SVG animation of href',
      ],
      'style expression' => [
        '<div style="width:expression(alert(1))"></div>',
        'holds a style attribute that holds a CSS expression()',
      ],
      'style element url' => [
        '<style>a{background:url( "javascript:alert(1)")}</style>',
        'holds a style element that holds a javascript: URL',
      ],
      'css data html' => [
        '<style>a{background:url(data:text/html,x)}</style>',
        'holds a style element that holds a data: URL other than an image in url()',
      ],
      'css data image type prefix' => [
        '<style>a{background:url("data:image/svg+xmlx,x")}</style>',
        'holds a style element that holds a data: URL other than an image in url()',
      ],
      'css data outside url' => [
        '<div style="--icon:data:image/png,AAAA"></div>',
        'holds a style attribute that holds a data: URL other than an image in url()',
      ],
      'css data import' => [
        '<style>@import "data:text/css,a{}";</style>',
        'holds a style element that holds a data: URL other than an image in url()',
      ],
      'css escape' => [
        '<style>a{background:url(\6a avascript:x)}</style>',
        'holds a style element that holds a CSS escape',
      ],
      'open attribute' => ['<p title="x', 'leaves a comment, tag, raw text or SVG element open'],
      'open comment' => ['<p>x</p><!-- x', 'leaves a comment, tag, raw text or SVG element open'],
      'open raw text' => ['<textarea>', 'leaves a comment, tag, raw text or SVG element open'],
      'open svg' => ['<svg><circle>', 'leaves a comment, tag, raw text or SVG element open'],
      'marker' => ['<wordpal-frozen-end></wordpal-frozen-end>', 'holds the name wordpal-frozen-end'],
    ];
  }

}
