<?php

declare(strict_types=1);

namespace Drupack\Support;

use Drupal\canvas\Plugin\ComponentPluginManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\Theme\Icon\IconFinder;

class WindowsPathServiceProvider implements ServiceModifierInterface {

  public function alter(ContainerBuilder $container) {
    $container->getDefinition(IconFinder::class)->setClass(DriveLetterIconFinder::class);
    $container->getDefinition('stream_wrapper.public')->setClass(SiteDataPublicStream::class);
    // Core's filesystem loader resolves a template name against the
    // application, which holds no public files.
    $container->register('drupack.twig.loader.public_files', PublicFilesTemplateLoader::class)
      ->addTag('twig.loader', ['priority' => 90]);
    // This runs on every compile, including ones before site:install makes Canvas active, when
    // the parent class is unresolvable. Setting the class only once Canvas is loadable leaves
    // plugin.manager.sdc on Canvas's own class until then.
    if (class_exists(ComponentPluginManager::class)) {
      $container->getDefinition('plugin.manager.sdc')->setClass(RootRelativeComponentPluginManager::class);
    }
  }

}
