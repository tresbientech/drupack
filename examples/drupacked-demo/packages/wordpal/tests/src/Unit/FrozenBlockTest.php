<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal\Component\FrozenBlock;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Frozen block key check.
 */
#[Group('wordpal')]
final class FrozenBlockTest extends UnitTestCase {

  /**
   * Tests that only a bare key() hash passes the check.
   */
  public function testIsKey(): void {
    $key = FrozenBlock::key('<p>Frozen</p>');
    self::assertTrue(FrozenBlock::isKey($key));
    self::assertFalse(FrozenBlock::isKey($key . "\n"));
    self::assertFalse(FrozenBlock::isKey(strtoupper($key)));
    self::assertFalse(FrozenBlock::isKey('../' . substr($key, 3)));
  }

}
