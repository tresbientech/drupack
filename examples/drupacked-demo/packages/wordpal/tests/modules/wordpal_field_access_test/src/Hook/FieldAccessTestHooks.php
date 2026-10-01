<?php

declare(strict_types=1);

namespace Drupal\wordpal_field_access_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;

/**
 * Denies view access to the fields and files listed in state.
 */
final class FieldAccessTestHooks {

  /**
   * State key holding the denied fields, as "entity_type.field_name".
   */
  public const DENIED = 'wordpal_field_access_test.denied';

  /**
   * State key holding the denied file ids.
   */
  public const DENIED_FILES = 'wordpal_field_access_test.denied_files';

  /**
   * Cache tag on every result, so a test can see the result was bubbled.
   *
   * A field result also carries the tag suffixed with ":<entity_type>.<field>",
   * so a test can tell which field's result bubbled.
   */
  public const TAG = 'wordpal_field_access_test';

  /**
   * Cache tag on every file result.
   */
  public const FILE_TAG = 'wordpal_field_access_test:file';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_entity_field_access().
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $definition): AccessResultInterface {
    $name = $definition->getTargetEntityTypeId() . '.' . $definition->getName();
    return AccessResult::forbiddenIf($operation === 'view' && in_array($name, $this->state->get(self::DENIED, []), TRUE))
      ->addCacheTags([self::TAG, self::TAG . ':' . $name]);
  }

  /**
   * Implements hook_ENTITY_TYPE_access() for files.
   */
  #[Hook('file_access')]
  public function fileAccess(FileInterface $file, string $operation, AccountInterface $account): AccessResultInterface {
    return AccessResult::forbiddenIf($operation === 'view' && in_array((int) $file->id(), $this->state->get(self::DENIED_FILES, []), TRUE))
      ->addCacheTags([self::FILE_TAG]);
  }

}
