<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Content;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Path\PathValidatorInterface;

/**
 * Validates Content mapping targets against Drupal's entity model.
 */
final class ContentMappingTargetValidator {

  public function __construct(
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PathValidatorInterface $pathValidator,
  ) {}

  /**
   * Validates every non-null target before conversion writes begin.
   *
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   The Content mapping.
   * @param bool $postContentRequired
   *   Whether a Template binds the post body.
   * @param bool $searchPathConverted
   *   Whether the conversion writes the View that creates the search path,
   *   so the path need not resolve yet.
   */
  public function validate(ContentMapping $mapping, bool $postContentRequired = FALSE, bool $searchPathConverted = FALSE): void {
    $errors = [];
    $postBundle = $mapping->has('post') ? $mapping->target('post') : NULL;
    if ($postBundle !== NULL) {
      $errors = [...$errors, ...$this->bundleErrors('post', $postBundle)];
    }
    if ($mapping->binds('page')) {
      $pageBundle = $mapping->target('page');
      $errors = [...$errors, ...$this->bundleErrors('page', $pageBundle)];
      if ($mapping->binds('page_body') && $this->nodeBundleExists($pageBundle)) {
        $errors = [...$errors, ...$this->fieldErrors(
            'page_body',
            'node',
            $pageBundle,
            $mapping->target('page_body'),
            ['text', 'text_long', 'text_with_summary'],
          ),
        ];
      }
      if ($mapping->binds('page_excerpt') && $this->nodeBundleExists($pageBundle)) {
        $errors = [...$errors, ...$this->fieldErrors(
            'page_excerpt',
            'node',
            $pageBundle,
            $mapping->target('page_excerpt'),
            ['string', 'string_long', 'text', 'text_long'],
          ),
        ];
      }
    }

    if ($postBundle !== NULL && $this->nodeBundleExists($postBundle)) {
      if ($postContentRequired && $mapping->binds('post_body')) {
        $errors = [...$errors, ...$this->fieldErrors(
            'post_body',
            'node',
            $postBundle,
            $mapping->target('post_body'),
            ['text', 'text_long', 'text_with_summary'],
          ),
        ];
      }
      foreach (['category', 'tag'] as $concept) {
        if ($mapping->binds($concept)) {
          $target = $mapping->target($concept);
          $errors = [...$errors, ...$this->termTargetErrors($concept, $postBundle, $target)];
        }
      }
      if ($mapping->binds('featured_image')) {
        $errors = [...$errors, ...$this->fieldErrors(
            'featured_image',
            'node',
            $postBundle,
            $mapping->target('featured_image'),
            ['image', 'entity_reference'],
          ),
        ];
      }
      if ($mapping->binds('excerpt')) {
        $errors = [...$errors, ...$this->fieldErrors(
            'excerpt',
            'node',
            $postBundle,
            $mapping->target('excerpt'),
            ['string', 'string_long', 'text', 'text_long'],
          ),
        ];
      }
      if ($mapping->binds('comments')) {
        $errors = [...$errors, ...$this->fieldErrors(
            'comments',
            'node',
            $postBundle,
            $mapping->target('comments'),
            ['comment'],
          ),
        ];
      }
    }

    if ($mapping->binds('author_biography')) {
      $errors = [...$errors, ...$this->fieldErrors(
          'author_biography',
          'user',
          'user',
          $mapping->target('author_biography'),
          ['string', 'string_long', 'text', 'text_long'],
        ),
      ];
    }
    if ($mapping->binds('navigation') && $this->entityTypeManager->getStorage('menu')->load($mapping->target('navigation')) === NULL) {
      $errors[] = 'navigation names a missing menu: ' . $mapping->target('navigation') . '.';
    }
    if ($mapping->binds('search') && !$searchPathConverted && $this->pathValidator->getUrlIfValidWithoutAccessCheck($mapping->target('search')['path']) === FALSE) {
      $errors[] = 'search.path does not resolve in Drupal: ' . $mapping->target('search')['path'] . '.';
    }
    if ($mapping->binds('search') && (!$this->entityTypeManager->hasDefinition('search_api_index') || $this->entityTypeManager->getStorage('search_api_index')->load($mapping->target('search')['index']) === NULL)) {
      $errors[] = 'search.index names a missing Search API index: ' . $mapping->target('search')['index'] . '.';
    }

    if ($errors !== []) {
      throw new \InvalidArgumentException("Invalid Content mapping targets:\n- " . implode("\n- ", $errors));
    }
  }

  /**
   * Returns an error when a node bundle is missing.
   */
  private function bundleErrors(string $concept, string $bundle): array {
    return $this->nodeBundleExists($bundle) ? [] : ["$concept names a missing node bundle: $bundle."];
  }

  /**
   * Returns whether a node bundle exists.
   */
  private function nodeBundleExists(string $bundle): bool {
    return isset($this->bundleInfo->getBundleInfo('node')[$bundle]);
  }

  /**
   * Validates a vocabulary and its post term-reference field.
   */
  private function termTargetErrors(string $concept, string $bundle, array $target): array {
    $errors = [];
    if ($this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($target['vocabulary']) === NULL) {
      $errors[] = "$concept names a missing vocabulary: {$target['vocabulary']}.";
    }
    $field = $this->fieldManager->getFieldDefinitions('node', $bundle)[$target['field']] ?? NULL;
    if ($field === NULL) {
      $errors[] = "$concept names a missing field on node.$bundle: {$target['field']}.";
    }
    elseif ($field->getType() !== 'entity_reference' || $field->getSetting('target_type') !== 'taxonomy_term') {
      $errors[] = "$concept field {$target['field']} must reference taxonomy terms.";
    }
    elseif (($field->getSetting('handler_settings')['target_bundles'] ?? []) !== [] && !in_array($target['vocabulary'], $field->getSetting('handler_settings')['target_bundles'], TRUE)) {
      $errors[] = "$concept field {$target['field']} does not allow vocabulary {$target['vocabulary']}.";
    }
    return $errors;
  }

  /**
   * Validates a field's existence and storage type.
   */
  private function fieldErrors(string $concept, string $entityType, string $bundle, string $fieldName, array $allowedTypes): array {
    $field = $this->fieldManager->getFieldDefinitions($entityType, $bundle)[$fieldName] ?? NULL;
    if (!$field instanceof FieldDefinitionInterface) {
      return ["$concept names a missing field on $entityType.$bundle: $fieldName."];
    }
    if (!in_array($field->getType(), $allowedTypes, TRUE)) {
      return ["$concept field $fieldName has type {$field->getType()}; expected " . implode(' or ', $allowedTypes) . '.'];
    }
    if ($concept === 'featured_image' && $field->getType() === 'entity_reference' && $field->getSetting('target_type') !== 'media') {
      return ["featured_image field $fieldName must reference media."];
    }
    if ($concept === 'featured_image' && $field->getType() === 'entity_reference') {
      return $this->mediaFieldErrors($fieldName, $field);
    }
    if ($concept === 'featured_image' && $field->getFieldStorageDefinition()->getCardinality() !== 1) {
      return ["featured_image field $fieldName must have cardinality 1."];
    }
    return [];
  }

  /**
   * Validates that every allowed media bundle uses an image source.
   */
  private function mediaFieldErrors(string $fieldName, FieldDefinitionInterface $field): array {
    $bundles = $field->getSetting('handler_settings')['target_bundles'] ?? [];
    if ($bundles === []) {
      return ["featured_image media field $fieldName must restrict its allowed media bundles."];
    }
    if (count($bundles) !== 1) {
      return ["featured_image media field $fieldName must allow exactly one image media bundle."];
    }
    $storage = $this->entityTypeManager->getStorage('media_type');
    foreach ($bundles as $bundle) {
      $mediaType = $storage->load($bundle);
      if ($mediaType === NULL || $mediaType->getSource()->getPluginId() !== 'image') {
        return ["featured_image media field $fieldName allows non-image media bundle $bundle."];
      }
      if ($mediaType->getSource()->getSourceFieldDefinition($mediaType)->getFieldStorageDefinition()->getCardinality() !== 1) {
        return ["featured_image media bundle $bundle source field must have cardinality 1."];
      }
    }
    if ($field->getFieldStorageDefinition()->getCardinality() !== 1) {
      return ["featured_image media field $fieldName must have cardinality 1."];
    }
    return [];
  }

}
