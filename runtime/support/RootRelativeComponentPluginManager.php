<?php

declare(strict_types=1);

namespace Drupack\Support;

use Drupal\canvas\Plugin\ComponentPluginManager;

/**
 * Rewrites a component's recorded directory relative to the application
 * root, where core's own DIRECTORY_SEPARATOR-joined suffix would otherwise
 * leave a Windows backslash and drive letter in the definition.
 *
 * Canvas's own PropShape::componentPluginManager() type-hints its return as
 * Canvas's own ComponentPluginManager, not core's, so plugin.manager.sdc
 * must stay an instance of that subclass. Extending it instead of core's
 * class keeps that contract on every platform.
 */
class RootRelativeComponentPluginManager extends ComponentPluginManager {

  /**
   * {@inheritdoc}
   */
  protected function alterDefinition(array $definition): array {
    $definition = parent::alterDefinition($definition);
    $definition['path'] = RootRelativePath::relativeTo($definition['path'], $this->appRoot);
    return $definition;
  }

}
