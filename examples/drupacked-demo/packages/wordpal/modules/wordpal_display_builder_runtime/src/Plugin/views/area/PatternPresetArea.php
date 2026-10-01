<?php

declare(strict_types=1);

namespace Drupal\wordpal_display_builder_runtime\Plugin\views\area;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\display_builder\Entity\PatternPreset;
use Drupal\ui_patterns\Element\ComponentElementBuilder;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a saved Display Builder pattern preset in a View area.
 *
 * With vocabularies set, the area renders only when the View's first
 * argument is a term of one of them, or with negate set, of none of them.
 * Mirrors wordpal_canvas_runtime's PatternArea for Canvas's own Pattern
 * entity; kept a separate plugin id and class because the two render
 * different entity types through different writers. Renders the preset
 * directly through ui_patterns rather than through DisplayBuilderWriter,
 * which stays in wordpal_display_builder: production installs this module
 * without the converter.
 */
#[ViewsArea('wordpal_pattern_preset')]
final class PatternPresetArea extends AreaPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager, protected ComponentElementBuilder $componentElementBuilder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get(ComponentElementBuilder::class));
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['pattern_id'] = ['default' => ''];
    $options['vocabularies'] = ['default' => []];
    $options['negate'] = ['default' => FALSE];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    $form['pattern_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Display Builder pattern preset ID'),
      '#default_value' => $this->options['pattern_id'],
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE): array {
    if ($this->options['vocabularies'] !== []) {
      // The page display's argument validator already loaded the term and
      // checked its view access.
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($this->view->args[0]);
      if (in_array($term->bundle(), $this->options['vocabularies'], TRUE) === $this->options['negate']) {
        return [];
      }
    }
    $patternId = $this->options['pattern_id'];
    $preset = PatternPreset::load($patternId);
    if ($preset === NULL) {
      throw new \UnexpectedValueException("Area pattern preset $patternId does not exist.");
    }
    $build = $this->componentElementBuilder->buildSource([], 'content', [], $preset->getSources(), []);
    $build = $build['#slots']['content'] ?? [];
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($preset)
      ->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = parent::calculateDependencies();
    $pattern = PatternPreset::load($this->options['pattern_id']);
    if ($pattern !== NULL) {
      $dependencies[$pattern->getConfigDependencyKey()][] = $pattern->getConfigDependencyName();
    }
    return $dependencies;
  }

}
