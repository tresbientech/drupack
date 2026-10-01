<?php

declare(strict_types=1);

namespace Drupal\wordpal\Support;

/**
 * The preset group and stored shape of one prop.
 */
final readonly class PresetSupport {

  /**
   * Constructs a preset support.
   *
   * @param string $group
   *   The theme.json preset group, such as "color" or "spacing".
   * @param \Drupal\wordpal\Support\PresetShape $shape
   *   Whether the prop stores a bare slug or a `var:preset|…` reference.
   */
  public function __construct(
    public string $group,
    public PresetShape $shape,
  ) {}

}
