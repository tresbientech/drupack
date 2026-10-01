<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\WordPress\ThemeLicense;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the theme license allowlist and the convert check.
 */
#[CoversClass(ThemeLicense::class)]
#[Group('wordpal')]
final class ThemeLicenseTest extends UnitTestCase {

  /**
   * Tests that known phrasings and URIs map to their SPDX ids.
   */
  #[DataProvider('providerSpdx')]
  public function testSpdx(string $license, string $licenseUri, ?string $expected): void {
    self::assertSame($expected, ThemeLicense::spdx($license, $licenseUri));
  }

  /**
   * Provides License and License URI headers and their SPDX ids.
   */
  public static function providerSpdx(): array {
    return [
      'core theme header' => [
        'GNU General Public License v2 or later',
        'http://www.gnu.org/licenses/gpl-2.0.html',
        'GPL-2.0-or-later',
      ],
      'case and spacing' => ["  gnu   GENERAL public license  v2 or later.", '', 'GPL-2.0-or-later'],
      'short form' => ['GPLv2 or later', '', 'GPL-2.0-or-later'],
      'spdx id' => ['GPL-3.0-or-later', '', 'GPL-3.0-or-later'],
      'only' => ['GPLv3', '', 'GPL-3.0-only'],
      'mit' => ['MIT License', '', 'MIT'],
      'uri fallback' => ['Some GPL', 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.html', 'GPL-2.0-only'],
      'uri trailing slash' => ['', 'https://opensource.org/licenses/MIT/', 'MIT'],
      'unknown' => ['Envato Regular License', 'https://themeforest.net/licenses/standard', NULL],
      'empty' => ['', '', NULL],
    ];
  }

  /**
   * Tests the line each accepted case prints.
   */
  #[DataProvider('providerCheck')]
  public function testCheck(bool $fromWordPressOrg, string $license, string $licenseUri, bool $accepted, string $expected): void {
    self::assertSame($expected, ThemeLicense::check($fromWordPressOrg, $license, $licenseUri, $accepted));
  }

  /**
   * Provides check() inputs and the line they print.
   */
  public static function providerCheck(): array {
    return [
      'slug' => [
        TRUE,
        'GNU General Public License v2 or later',
        '',
        FALSE,
        'License: GNU General Public License v2 or later (WordPress.org)',
      ],
      'slug with an unknown phrasing' => [TRUE, 'Proprietary', '', FALSE, 'License: Proprietary (WordPress.org)'],
      'slug without a header' => [TRUE, '', '', FALSE, 'License: not stated (WordPress.org)'],
      'allowed zip' => [FALSE, 'MIT', '', FALSE, 'License: MIT (style.css)'],
      'allowed zip with the override' => [FALSE, 'MIT', '', TRUE, 'License: MIT (style.css)'],
      'accepted' => [
        FALSE,
        'Envato Regular License',
        '',
        TRUE,
        'License: "Envato Regular License" accepted with --accept-license',
      ],
      'accepted uri only' => [
        FALSE,
        '',
        'https://example.com/terms',
        TRUE,
        'License: "https://example.com/terms" accepted with --accept-license',
      ],
      'accepted without a header' => [FALSE, '', '', TRUE, 'License: none stated, accepted with --accept-license'],
      'control characters' => [FALSE, "Own\e[31m", '', TRUE, 'License: "Own[31m" accepted with --accept-license'],
    ];
  }

  /**
   * Tests the line each shipped plugin prints.
   */
  #[DataProvider('providerPluginLine')]
  public function testPluginLine(bool $fromWordPressOrg, string $version, string $license, string $licenseUri, string $expected): void {
    $headers = ['version' => $version, 'license' => $license, 'license_uri' => $licenseUri];
    self::assertSame($expected, ThemeLicense::pluginLine('acme-forms', $headers, $fromWordPressOrg));
  }

  /**
   * Provides pluginLine() inputs and the line they print.
   */
  public static function providerPluginLine(): array {
    return [
      'slug' => [TRUE, '1.2', 'GPLv3', '', 'acme-forms 1.2: GPLv3 (WordPress.org)'],
      'slug without a header' => [TRUE, '1.2', '', '', 'acme-forms 1.2: not stated (WordPress.org)'],
      'allowed zip' => [FALSE, '1.2', 'MIT', '', 'acme-forms 1.2: MIT (plugin header)'],
      'unknown zip license' => [
        FALSE,
        '1.2',
        'Acme Commercial License',
        '',
        'acme-forms 1.2: "Acme Commercial License", not on the GPL-compatible list',
      ],
      'zip without a header' => [FALSE, '1.2', '', '', 'acme-forms 1.2: not stated'],
      'no version' => [FALSE, '', 'MIT', '', 'acme-forms: MIT (plugin header)'],
      'control characters' => [
        FALSE,
        "1.2\e[31m",
        "Own\e[31m",
        '',
        'acme-forms 1.2[31m: "Own[31m", not on the GPL-compatible list',
      ],
    ];
  }

  /**
   * Tests that an unknown license without the override stops the run.
   */
  public function testUnknownLicenseIsRefused(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The theme states the license "Envato Regular License" in style.css, which is not on the GPL-compatible list.');
    ThemeLicense::check(FALSE, 'Envato Regular License', '', FALSE);
  }

  /**
   * Tests that a zip stating no license without the override stops the run.
   */
  public function testMissingLicenseIsRefused(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The theme states no license in style.css');
    ThemeLicense::check(FALSE, '', '', FALSE);
  }

}
