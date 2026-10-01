<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Theme\ScriptDependencies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which theme scripts export and the libraries they depend on.
 */
#[CoversClass(ScriptDependencies::class)]
#[Group('wordpal')]
final class ScriptDependenciesTest extends UnitTestCase {

  /**
   * Tests a script with no dependency exports with none.
   */
  public function testScriptWithoutDependencyExports(): void {
    self::assertSame(
      ['libraries' => ['theme-main' => []], 'skipped' => []],
      ScriptDependencies::resolve([['handle' => 'theme-main', 'dependencies' => []]], 'fixture'),
    );
  }

  /**
   * Tests WordPress's jquery handle maps to core's jQuery.
   */
  public function testJqueryMapsToCoreLibrary(): void {
    self::assertSame(
      ['libraries' => ['theme-slider' => ['core/jquery']], 'skipped' => []],
      ScriptDependencies::resolve([['handle' => 'theme-slider', 'dependencies' => ['jquery']]], 'fixture'),
    );
  }

  /**
   * Tests a script depending on another theme script uses its library.
   */
  public function testThemeScriptChainExports(): void {
    self::assertSame([
      'libraries' => [
        'theme-vendor' => ['core/jquery'],
        'theme-main' => ['fixture/script.theme-vendor', 'core/jquery'],
      ],
      'skipped' => [],
    ], ScriptDependencies::resolve([
      ['handle' => 'theme-vendor', 'dependencies' => ['jquery']],
      ['handle' => 'theme-main', 'dependencies' => ['theme-vendor', 'jquery']],
    ], 'fixture'));
  }

  /**
   * Tests a script with an unmapped dependency is skipped with its reason.
   */
  public function testUnmappedDependencySkips(): void {
    self::assertSame([
      'libraries' => [],
      'skipped' => ['theme-main' => 'needs wp-dom-ready, which has no Drupal library'],
    ], ScriptDependencies::resolve([
      ['handle' => 'theme-main', 'dependencies' => ['jquery', 'wp-dom-ready']],
    ], 'fixture'));
  }

  /**
   * Tests a script depending on a skipped script is skipped too.
   */
  public function testBrokenChainSkips(): void {
    self::assertSame([
      'libraries' => ['theme-other' => []],
      'skipped' => [
        'theme-vendor' => 'needs wp-i18n, which has no Drupal library',
        'theme-main' => 'needs theme script theme-vendor, which is skipped',
      ],
    ], ScriptDependencies::resolve([
      ['handle' => 'theme-vendor', 'dependencies' => ['wp-i18n']],
      ['handle' => 'theme-other', 'dependencies' => []],
      ['handle' => 'theme-main', 'dependencies' => ['theme-other', 'theme-vendor']],
    ], 'fixture'));
  }

}
