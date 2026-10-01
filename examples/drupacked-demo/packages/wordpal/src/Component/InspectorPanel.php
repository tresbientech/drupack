<?php

declare(strict_types=1);

namespace Drupal\wordpal\Component;

/**
 * Places props in the panels of the WordPress block inspector.
 */
final class InspectorPanel {

  /**
   * The prop key that names the panel a prop sits in.
   */
  public const KEY = 'x-wordpal-panel';

  /**
   * The prop key that lists the values the WordPress editor offers.
   *
   * The schema enum keeps every value saved content may hold.
   */
  public const OPTIONS_KEY = 'x-wordpal-options';

  /**
   * The inspector tabs and their panels, in WordPress 7.1 render order.
   *
   * The Settings tab ends with Advanced. The Styles tab renders its panels in
   * the order of the block-editor's StylesTab. That tab also has Background
   * and Position panels: background props go to Color, and position props to
   * Layout.
   */
  public const TABS = [
    'content' => ['content'],
    'settings' => ['settings', 'advanced'],
    'styles' => [
      'typography',
      'color',
      'filter',
      'layout',
      'dimensions',
      'border',
    ],
  ];

  /**
   * Orders props by panel, keeping their order within a panel.
   *
   * @param array $props
   *   Props keyed by name, each with a panel under self::KEY.
   *
   * @return array
   *   The props in inspector order.
   */
  public static function sort(array $props): array {
    $rank = array_flip(array_merge(...array_values(self::TABS)));
    // uasort() is stable, so props in one panel keep their order.
    uasort($props, static fn (array $a, array $b): int => $rank[$a[self::KEY]] <=> $rank[$b[self::KEY]]);
    return $props;
  }

}
