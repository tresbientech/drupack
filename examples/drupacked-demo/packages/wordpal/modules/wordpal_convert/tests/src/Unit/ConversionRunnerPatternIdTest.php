<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\ConversionRunner;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\Theme\ResolvedPattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ConversionRunner helpers that need no container.
 */
#[CoversClass(ConversionRunner::class)]
#[Group('wordpal')]
final class ConversionRunnerPatternIdTest extends UnitTestCase {

  /**
   * Tests that two slugs differing only by separator run length stay apart.
   *
   * X-t9 ships "header-logo---nav---contact" and "header-logo-nav---contact"
   * as two distinct patterns; collapsing every run of separators to one
   * underscore gave both the same id and aborted the conversion.
   */
  public function testSeparatorRunsStayDistinct(): void {
    $first = ConversionRunner::patternId('x-t9', 'x-t9/header-logo---nav---contact');
    $second = ConversionRunner::patternId('x-t9', 'x-t9/header-logo-nav---contact');

    self::assertNotSame($first, $second);
  }

  /**
   * Tests a part Query pages after the Queries of Inserter patterns.
   */
  public function testPartQueryPagerFollowsPatternQueries(): void {
    $postTemplate = new BlockNode('core/post-template', [], '', [new BlockNode('core/post-title', [], '', [])]);
    $query = new BlockNode('core/query', ['query' => ['perPage' => 2]], '', [$postTemplate]);
    $partSet = new PartSet([['type' => 'part', 'slug' => 'footer', 'nodes' => [$query]]]);
    $patternLoop = new QueryLoop($query, $postTemplate, NULL, NULL, 2, 0, 'DESC', FALSE, FALSE, 4, 0, 'date');
    $pattern = ResolvedPattern::resolved(['slug' => 'fixture/list'], [$query], [$patternLoop], []);

    $trees = (new \ReflectionMethod(ConversionRunner::class, 'partTrees'))->invoke(NULL, ['set' => $partSet], 10, [], [$pattern], NULL);

    self::assertSame(5, $trees['set'][0]['loops'][0]->pagerElement);
  }

}
