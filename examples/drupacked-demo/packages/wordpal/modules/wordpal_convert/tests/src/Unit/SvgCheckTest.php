<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Support\SvgCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the SVG check that guards copied theme assets.
 */
#[CoversClass(SvgCheck::class)]
#[Group('wordpal')]
final class SvgCheckTest extends UnitTestCase {

  /**
   * Tests that plain drawings, fragment links and the SVG 1.1 DTD pass.
   */
  #[DataProvider('safeSvgs')]
  public function testAcceptsSafeSvg(string $svg): void {
    self::assertNull(SvgCheck::problem($svg));
  }

  /**
   * Returns SVGs the check accepts.
   */
  public static function safeSvgs(): array {
    return [
      'drawing' => [
        '<?xml version="1.0" encoding="UTF-8"?><!-- Generator --><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g fill="#000"><path d="M0 0h24v24H0z"/></g></svg>',
      ],
      'fragment use' => [
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><path id="a" d="M0 0"/></defs><use xlink:href="#a"/><use href="#a"/></svg>',
      ],
      'fragment style url' => [
        '<svg xmlns="http://www.w3.org/2000/svg"><style>.a{fill:url(#g)}</style><rect class="a" style="fill:url(\'#g\')"/></svg>',
      ],
      'public doctype' => [
        '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"><circle r="1"/></svg>',
      ],
      'animated fill' => [
        '<svg xmlns="http://www.w3.org/2000/svg"><rect><set attributeName="fill" to="red"/></rect></svg>',
      ],
      'fragment fill url' => [
        '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(#g)"/></svg>',
      ],
      'UTF-8 BOM and lower-case declaration' => [
        "\xEF\xBB\xBF" . '<?xml version="1.0" encoding="utf-8"?><svg xmlns="http://www.w3.org/2000/svg"/>',
      ],
    ];
  }

  /**
   * Tests that each construct that can run script or load content fails.
   */
  #[DataProvider('unsafeSvgs')]
  public function testRejectsUnsafeSvg(string $svg, string $problem): void {
    self::assertSame($problem, SvgCheck::problem($svg));
  }

  /**
   * Returns SVGs the check rejects, with the reason it gives.
   */
  public static function unsafeSvgs(): array {
    $svg = static fn (string $inner, string $attributes = ''): string => '<svg xmlns="http://www.w3.org/2000/svg"'
      . ' xmlns:xlink="http://www.w3.org/1999/xlink"' . $attributes . '>' . $inner . '</svg>';
    return [
      'empty' => ['', 'does not parse as XML'],
      'not XML' => ['<svg xmlns="http://www.w3.org/2000/svg"><g></svg>', 'does not parse as XML'],
      'HTML' => ['<p>text', 'does not parse as XML'],
      'entity declaration' => [
        '<!DOCTYPE svg [<!ENTITY a "b">]><svg xmlns="http://www.w3.org/2000/svg">&a;</svg>',
        'declares a DOCTYPE internal subset',
      ],
      'external entity' => [
        '<!DOCTYPE svg [<!ENTITY a SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&a;</svg>',
        'declares a DOCTYPE internal subset',
      ],
      'stylesheet instruction' => [
        '<?xml-stylesheet href="https://example.com/a.css"?><svg xmlns="http://www.w3.org/2000/svg"/>',
        'holds a processing instruction',
      ],
      'no namespace' => [
        '<svg><circle r="1"/></svg>',
        'holds the element svg outside the SVG namespace',
      ],
      'XHTML element' => [
        $svg('<h:iframe xmlns:h="http://www.w3.org/1999/xhtml" src="https://example.com"/>'),
        'holds the element h:iframe outside the SVG namespace',
      ],
      'script' => [$svg('<script>alert(1)</script>'), 'holds a script element'],
      'script in capitals' => [$svg('<SCRIPT>alert(1)</SCRIPT>'), 'holds a SCRIPT element'],
      'foreignObject' => [
        $svg('<foreignObject><div xmlns="http://www.w3.org/1999/xhtml"/></foreignObject>'),
        'holds a foreignObject element',
      ],
      'onload' => [$svg('', ' onload="alert(1)"'), 'holds the event handler attribute onload'],
      'onclick in capitals' => [
        $svg('<rect OnClick="alert(1)"/>'),
        'holds the event handler attribute OnClick',
      ],
      'javascript href' => [
        $svg('<a href="javascript:alert(1)"><rect/></a>'),
        'holds the href "javascript:alert(1)", which is not a fragment',
      ],
      'javascript xlink href' => [
        $svg('<a xlink:href=" java&#9;script:alert(1)"><rect/></a>'),
        "holds the xlink:href \" java\tscript:alert(1)\", which is not a fragment",
      ],
      'data href' => [
        $svg('<image href="data:image/svg+xml;base64,PHN2Zz4="/>'),
        'holds the href "data:image/svg+xml;base64,PHN2Zz4=", which is not a fragment',
      ],
      'external href' => [
        $svg('<use href="https://example.com/sprite.svg#a"/>'),
        'holds the href "https://example.com/sprite.svg#a", which is not a fragment',
      ],
      'relative href' => [
        $svg('<image xlink:href="photo.png"/>'),
        'holds the xlink:href "photo.png", which is not a fragment',
      ],
      'animated href' => [
        $svg('<a href="#a"><set attributeName="href" to="javascript:alert(1)"/></a>'),
        'animates the attribute href on set',
      ],
      'animated handler' => [
        $svg('<rect><animate attributeName="onclick" values="alert(1)"/></rect>'),
        'animates the attribute onclick on animate',
      ],
      'javascript in other attribute' => [
        $svg('<rect filter="javascript:alert(1)"/>'),
        'holds a javascript: or data: URL in filter',
      ],
      'data in style attribute' => [
        $svg('<rect style="fill:url(data:image/png;base64,AA)"/>'),
        'holds a javascript: or data: URL in style',
      ],
      'external style attribute url' => [
        $svg('<rect style="fill:url(https://example.com/a.svg#g)"/>'),
        'holds a style attribute that references a URL other than a fragment',
      ],
      'style import' => [
        $svg('<style>@import "https://example.com/a.css";</style>'),
        'holds a style element that imports a stylesheet',
      ],
      'style external url' => [
        $svg('<style>.a{background:url("/a.png")}</style>'),
        'holds a style element that references a URL other than a fragment',
      ],
      'style javascript url' => [
        $svg('<style>.a{background:url(javascript:alert(1))}</style>'),
        'holds a style element that holds a javascript: or data: URL',
      ],
      'style escape' => [
        $svg('<style>.a{background:u\\72l(x)}</style>'),
        'holds a style element that holds a CSS escape',
      ],
      'CDATA style external url' => [
        $svg('<style><![CDATA[.a{background:url(https://example.com/a.png)}]]></style>'),
        'holds a style element that references a URL other than a fragment',
      ],
      'style image-set' => [
        $svg('<style>.a{background:image-set("a.png" 1x)}</style>'),
        'holds a style element that loads a resource with image-set()',
      ],
      'external fill url' => [
        $svg('<rect fill="url(https://example.com/a.svg#g)"/>'),
        'holds a fill attribute that references a URL other than a fragment',
      ],
      'external animation value' => [
        $svg('<rect><animate attributeName="fill" values="url(https://example.com/a.svg#g)"/></rect>'),
        'holds a values attribute that references a URL other than a fragment',
      ],
      'animated style' => [
        $svg('<rect><set attributeName="style" to="cursor:url(https://example.com/a.cur)"/></rect>'),
        'holds a to attribute that references a URL other than a fragment',
      ],
      'xml:base' => [
        $svg('<a xml:base="https://example.com/" href="#a"><rect/></a>'),
        'holds xml:base',
      ],
      'empty internal subset' => [
        '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" []><svg xmlns="http://www.w3.org/2000/svg"/>',
        'declares a DOCTYPE internal subset',
      ],
      'SYSTEM doctype' => [
        '<!DOCTYPE svg SYSTEM "https://example.com/a.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>',
        'declares a DOCTYPE other than the W3C SVG 1.0 or 1.1 one',
      ],
      'Latin-1 declared' => [
        '<?xml version="1.0" encoding="ISO-8859-1"?><svg xmlns="http://www.w3.org/2000/svg"/>',
        'declares the encoding ISO-8859-1',
      ],
      'UTF-7 declared' => [
        '<?xml version="1.0" encoding="UTF-7"?><svg xmlns="http://www.w3.org/2000/svg"><text>+ADw-script+AD4-alert(1)+ADw-/script+AD4-</text></svg>',
        'declares the encoding UTF-7',
      ],
      'UTF-16 declared' => [
        mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><svg xmlns="http://www.w3.org/2000/svg"/>', 'UTF-16LE', 'UTF-8'),
        'holds a NUL byte',
      ],
      'UTF-16 BOM' => [
        "\xFF\xFE" . mb_convert_encoding('<svg xmlns="http://www.w3.org/2000/svg"/>', 'UTF-16LE', 'UTF-8'),
        'is not valid UTF-8',
      ],
      'invalid UTF-8' => [
        $svg("<text>\xC3\x28</text>"),
        'is not valid UTF-8',
      ],
    ];
  }

}
