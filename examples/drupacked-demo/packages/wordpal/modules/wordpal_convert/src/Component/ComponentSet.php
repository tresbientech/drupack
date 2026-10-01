<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * The components the wordpal module ships for WordPress core blocks.
 */
final class ComponentSet {

  /**
   * The hand-written component that prints only its slot's blocks.
   */
  public const FRAGMENT_SLUG = 'fragment';

  /**
   * The hand-written component that prints one Post Terms link.
   */
  public const POST_TERM_SLUG = 'post-term';

  public function __construct(
    private readonly string $appRoot,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Returns the WordPress version the definitions were generated from.
   */
  public function wordPressVersion(): string {
    if (!is_file($this->versionFile())) {
      throw new \RuntimeException('No components have been generated yet. Run drush wordpal:generate-components.');
    }
    return (string) json_decode((string) file_get_contents($this->versionFile()), TRUE)['version'];
  }

  /**
   * Records the WordPress version the definitions were generated from.
   */
  public function recordWordPressVersion(string $version): void {
    file_put_contents($this->versionFile(), json_encode([
      'version' => $version,
    ], JSON_PRETTY_PRINT) . "\n");
  }

  /**
   * Returns the support prop names of each block a Block plugin renders.
   *
   * @return array<string, string[]>
   *   Prop names, keyed by WordPress block name.
   */
  public function blockSupports(): array {
    if (!is_file($this->blockSupportsFile())) {
      throw new \RuntimeException('No block supports have been generated yet. Run drush wordpal:generate-components.');
    }
    return json_decode((string) file_get_contents($this->blockSupportsFile()), TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Records the support prop names of each block a Block plugin renders.
   *
   * @param array<string, string[]> $supports
   *   Prop names, keyed by WordPress block name.
   */
  public function recordBlockSupports(array $supports): void {
    file_put_contents($this->blockSupportsFile(), json_encode($supports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  }

  /**
   * Returns the Drupal target of every WordPress core block.
   *
   * @return array<string, array>
   *   BlockCoverage::rows() rows, keyed by WordPress block name.
   */
  public function blockCoverage(): array {
    if (!is_file($this->blockCoverageFile())) {
      throw new \RuntimeException('No block coverage has been generated yet. Run drush wordpal:generate-components.');
    }
    return json_decode((string) file_get_contents($this->blockCoverageFile()), TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Records the Drupal target of every WordPress core block.
   *
   * @param array<string, array> $rows
   *   BlockCoverage::rows() rows, keyed by WordPress block name.
   */
  public function recordBlockCoverage(array $rows): void {
    file_put_contents($this->blockCoverageFile(), json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  }

  /**
   * Returns the theme census share of each core block.
   *
   * @return array{crawl_date: string, themes: int, share: array<string, float>}
   *   The usage, as components/block-usage.json holds it.
   */
  public function blockUsage(): array {
    return json_decode((string) file_get_contents($this->directory() . '/block-usage.json'), TRUE, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * Returns the hand-kept notes of the block support table.
   *
   * @return array{blocks: array<string, array[]>, site: array[]}
   *   The notes, as BlockTable::render() takes them.
   */
  public function blockNotes(): array {
    return Yaml::decode((string) file_get_contents($this->directory() . '/block-notes.yml'));
  }

  /**
   * Returns the directory holding the components.
   */
  public function directory(): string {
    return $this->appRoot . '/' . $this->moduleList->getPath('wordpal') . '/components';
  }

  /**
   * Returns the directory holding WordPress's script modules.
   */
  public function scriptModulesDirectory(): string {
    return $this->appRoot . '/' . $this->moduleList->getPath('wordpal') . '/js/script-modules';
  }

  /**
   * Returns the directory holding the copied WordPress style engine.
   */
  public function styleEngineDirectory(): string {
    return $this->appRoot . '/' . $this->moduleList->getPath('wordpal') . '/src/WordPress/StyleEngine/Vendor';
  }

  /**
   * Returns the file recording the WordPress version.
   */
  private function versionFile(): string {
    return $this->directory() . '/wordpress-version.json';
  }

  /**
   * Returns the file recording each core block's Drupal target.
   */
  private function blockCoverageFile(): string {
    return $this->directory() . '/block-coverage.json';
  }

  /**
   * Returns the file recording the Block plugins' support prop names.
   */
  private function blockSupportsFile(): string {
    return $this->directory() . '/block-supports.json';
  }

}
