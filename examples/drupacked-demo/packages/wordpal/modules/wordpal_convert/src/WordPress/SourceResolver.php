<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\WordPress;

use Drupal\Core\File\FileSystemInterface;
use Drupal\wordpal_convert\Component\ComponentSet;

/**
 * Resolves a theme and its plugins, checked against the WordPress version.
 *
 * Shared by wordpal:convert and the Evaluation module's wordpal:reference,
 * the two commands that turn a theme and plugin reference into sources.
 */
final class SourceResolver {

  public function __construct(
    private readonly ThemeSource $themeSource,
    private readonly PlaygroundRunner $runner,
    private readonly ComponentSet $componentSet,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Resolves a theme and its plugin references.
   *
   * @param string $theme
   *   WordPress.org theme slug, theme zip path or https URL to a theme zip.
   * @param string[] $plugins
   *   Plugin references: a WordPress.org slug, zip path or https URL.
   *
   * @return array{0: \Drupal\wordpal_convert\WordPress\Source, 1: \Drupal\wordpal_convert\WordPress\Source[]}
   *   The theme source and the plugin sources.
   */
  public function resolve(string $theme, array $plugins): array {
    $downloads = PlaygroundRunner::downloadDirectory($this->fileSystem);
    $source = $this->themeSource->resolve($theme, SourceType::Theme, $downloads);
    $resolvedPlugins = array_map(
      fn (string $plugin): Source => $this->themeSource->resolve($plugin, SourceType::Plugin, $downloads),
      $plugins,
    );
    foreach ([$source, ...$resolvedPlugins] as $resolved) {
      $this->runner->checkRequiredVersion($resolved, $this->componentSet->wordPressVersion());
    }
    return [$source, $resolvedPlugins];
  }

}
