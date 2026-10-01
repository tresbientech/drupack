<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\WordPress\StyleEngine\StyleEngineBridge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shims the copied WordPress style engine calls.
 */
#[CoversClass(StyleEngineBridge::class)]
#[Group('wordpal')]
final class StyleEngineShimsTest extends UnitTestCase {

  /**
   * Tests that a declaration the shim can verify reaches the output.
   */
  public function testKeepsVerifiableDeclaration(): void {
    $result = StyleEngineBridge::declarations(['color' => ['text' => '#1a2b3c']]);

    self::assertSame(['color' => '#1a2b3c'], $result['styles']);
  }

  /**
   * Tests that the safecss_filter_attr() shim fails closed on a bad value.
   *
   * A `url()` value could load a resource, which is exactly what WordPress's
   * own safecss_filter_attr() also refuses; this shim reuses
   * PresetValue::css()'s allowed-function list and reports the same result
   * by dropping the declaration rather than passing it through.
   */
  public function testDropsDeclarationItCannotVerify(): void {
    $result = StyleEngineBridge::declarations([
      'typography' => ['fontStyle' => "italic') } body { background: url(https://evil.example/x"],
    ]);

    self::assertSame([], $result['styles']);
  }

}
