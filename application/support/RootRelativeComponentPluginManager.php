<?php

declare(strict_types=1);

namespace Drupack\Support;

use Drupal\canvas\Plugin\ComponentPluginManager;
use Symfony\Component\Filesystem\Path;

/**
 * Rewrites a component's recorded directory relative to the application root; core's
 * DIRECTORY_SEPARATOR-joined suffix would leave a Windows backslash and drive letter in the
 * definition. Canvas's PropShape::componentPluginManager() type-hints Canvas's own
 * ComponentPluginManager, so plugin.manager.sdc must stay an instance of that subclass.
 */
class RootRelativeComponentPluginManager extends ComponentPluginManager {

  protected function alterDefinition(array $definition): array {
    $definition = parent::alterDefinition($definition);
    $definition['path'] = Path::makeRelative($definition['path'], $this->appRoot);
    return $definition;
  }

}
