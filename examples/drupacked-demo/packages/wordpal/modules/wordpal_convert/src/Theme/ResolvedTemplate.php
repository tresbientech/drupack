<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Theme;

/**
 * One route's resolved Template: its main area, Part set, and Query blocks.
 */
final readonly class ResolvedTemplate {

  /**
   * Constructs a resolved Template.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The Template's main area, references expanded.
   * @param \Drupal\wordpal_convert\Theme\PartSet $partSet
   *   The Template parts around the main area.
   * @param \Drupal\wordpal_convert\Theme\QueryLoop[] $loops
   *   The main area's Query blocks.
   * @param string[] $dropped
   *   One report line per Query left out of the main area.
   */
  public function __construct(
    public array $nodes,
    public PartSet $partSet,
    public array $loops,
    public array $dropped,
  ) {}

}
