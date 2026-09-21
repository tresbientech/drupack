<?php

declare(strict_types=1);

namespace Drupack\Support;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\Theme\Icon\IconFinder;

/**
 * Swaps core service classes for Drupack's own, keeping core's own
 * constructor arguments.
 */
class WindowsPathServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container->getDefinition(IconFinder::class)->setClass(DriveLetterIconFinder::class);
  }

}
