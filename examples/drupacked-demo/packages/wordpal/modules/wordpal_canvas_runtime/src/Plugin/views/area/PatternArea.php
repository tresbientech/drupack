<?php

declare(strict_types=1);

namespace Drupal\wordpal_canvas_runtime\Plugin\views\area;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Renders a saved Canvas Pattern in a View area.
 *
 * With vocabularies set, the area renders only when the View's first
 * argument is a term of one of them, or with negate set, of none of them.
 */
#[ViewsArea('wordpal_pattern')]
final class PatternArea extends AreaPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
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
      '#title' => $this->t('Canvas Pattern ID'),
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
    $pattern = $this->entityTypeManager->getStorage('pattern')->load($patternId);
    if ($pattern === NULL) {
      throw new \UnexpectedValueException("Area Pattern $patternId does not exist.");
    }
    $build = $pattern->getComponentTree()->toRenderable($pattern);
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($pattern)
      ->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $dependencies = parent::calculateDependencies();
    $pattern = $this->entityTypeManager->getStorage('pattern')->load($this->options['pattern_id']);
    if ($pattern !== NULL) {
      $dependencies[$pattern->getConfigDependencyKey()][] = $pattern->getConfigDependencyName();
    }
    return $dependencies;
  }

}
