<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Write;

use Drupal\Core\Config\Config;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\wordpal_convert\ConversionOwnership;

/**
 * Undoes a Conversion write phase's site mutations when it fails.
 *
 * Records one undo closure per mutation as the write phase performs it, and
 * runs them in reverse when run()'s body throws.
 */
final class ConversionTransaction {

  /**
   * Undo closures, in the order their mutations ran.
   *
   * @var callable[]
   */
  private array $undo = [];

  /**
   * Ids abandon() has deleted, kept for reconcile() and its own undo to use.
   *
   * @var array<string, string[]>
   */
  private array $abandoned;

  /**
   * Whether abandon() has already registered its manifest-cleanup undo.
   */
  private bool $abandonUndoRegistered = FALSE;

  public function __construct(
    private readonly ConversionOwnership $ownership,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly string $themeId,
  ) {
    $this->abandoned = array_fill_keys(ConversionOwnership::ENTITY_TYPES, []);
  }

  /**
   * Sets one simple config key; undo restores the previous value.
   */
  public function setConfig(Config $config, string $key, mixed $value): void {
    $previous = $config->get($key);
    $this->undo[] = static function () use ($config, $key, $previous): void {
      $config->set($key, $previous)->save();
    };
    $config->set($key, $value)->save();
  }

  /**
   * Grants a role one permission; undo revokes it unless the role held it.
   */
  public function grantPermission(string $roleId, string $permission): void {
    $storage = $this->entityTypeManager->getStorage('user_role');
    $role = $storage->load($roleId);
    if ($role->hasPermission($permission)) {
      return;
    }
    $this->undo[] = static function () use ($storage, $roleId, $permission): void {
      $storage->load($roleId)->revokePermission($permission)->save();
    };
    $role->grantPermission($permission)->save();
  }

  /**
   * Deletes an Unmanaged entity so the Conversion can write its id.
   *
   * Undo recreates it with its saved values and removes $id from the
   * manifest's $entityType list, since a recreated entity is the site's
   * own again.
   */
  public function takeOver(string $entityType, string $id): void {
    $storage = $this->entityTypeManager->getStorage($entityType);
    $values = $storage->load($id)->toArray();
    $this->undo[] = function () use ($storage, $entityType, $id, $values): void {
      // A later step may have already saved its own entity at this id.
      $storage->load($id)?->delete();
      $storage->create($values)->save();
      $manifest = $this->ownership->load($this->themeId);
      $manifest['entities'][$entityType] = array_values(array_diff($manifest['entities'][$entityType], [$id]));
      $this->ownership->save($this->themeId, $manifest);
    };
    $storage->load($id)->delete();
  }

  /**
   * Disables a site View the Conversion replaces and records it.
   *
   * Undo re-enables the View and removes $id from the manifest's
   * replaced_views list.
   */
  public function replaceView(string $id): void {
    $storage = $this->entityTypeManager->getStorage('view');
    $this->undo[] = function () use ($storage, $id): void {
      $storage->load($id)->enable()->save();
      $manifest = $this->ownership->load($this->themeId);
      $manifest['replaced_views'] = array_values(array_diff($manifest['replaced_views'], [$id]));
      $this->ownership->save($this->themeId, $manifest);
    };
    $storage->load($id)->disable()->save();
    $manifest = $this->ownership->load($this->themeId);
    $manifest['replaced_views'] = array_values(array_unique([...$manifest['replaced_views'], $id]));
    sort($manifest['replaced_views']);
    $this->ownership->save($this->themeId, $manifest);
  }

  /**
   * Disables a View the manifest does not record; undo enables it.
   */
  public function disableView(string $id): void {
    $storage = $this->entityTypeManager->getStorage('view');
    $this->undo[] = static function () use ($storage, $id): void {
      $storage->load($id)->enable()->save();
    };
    $storage->load($id)->disable()->save();
  }

  /**
   * Enables a written listing View; undo disables it.
   */
  public function enableView(string $id): void {
    $storage = $this->entityTypeManager->getStorage('view');
    $this->undo[] = static function () use ($storage, $id): void {
      $storage->load($id)->disable()->save();
    };
    $storage->load($id)->enable()->save();
  }

  /**
   * Deletes a skipped Pattern's outputs, minus ids an earlier Pattern saved.
   *
   * Records the deleted ids for reconcile(). The deletion itself is not
   * undone on a later failure; the rollback boundary only unwinds wiring.
   *
   * The first call in a run also registers an undo that keeps the manifest
   * in step with storage: on a later failure, it drops every recorded id
   * abandon() deleted and no later step recreated.
   *
   * @param array<string, string[]> $candidates
   *   Entity ids keyed by entity type, from ConversionOwnership::ENTITY_TYPES.
   * @param string[] $created
   *   Report lines, "entity_type:id", of everything the write phase has
   *   saved so far.
   */
  public function abandon(array $candidates, array $created): void {
    if (!$this->abandonUndoRegistered) {
      $this->abandonUndoRegistered = TRUE;
      $this->undo[] = function (): void {
        $manifest = $this->ownership->load($this->themeId);
        foreach ($this->abandoned as $type => $ids) {
          // A target owns no ids of an entity type another target's writer
          // uses, and its module need not be enabled to look up its storage.
          if ($ids === []) {
            continue;
          }
          // A later step can recreate an abandoned id, since Query output
          // ids are content hashes, so only live storage tells a
          // recreated id from one still gone.
          $storage = $this->entityTypeManager->getStorage($type);
          $gone = array_values(array_filter($ids, static fn (string $id): bool => $storage->load($id) === NULL));
          $manifest['entities'][$type] = array_values(array_diff($manifest['entities'][$type], $gone));
        }
        $this->ownership->save($this->themeId, $manifest);
      };
    }
    foreach ($candidates as $type => $ids) {
      $missing = array_values(array_filter($ids, static fn (string $id): bool => !in_array("$type:$id", $created, TRUE)));
      $candidates[$type] = $missing;
      $this->abandoned[$type] = [...$this->abandoned[$type], ...$missing];
    }
    $this->ownership->deleteEntities($candidates);
  }

  /**
   * Strips abandon()'s recorded ids that are still missing from $created.
   *
   * A later Pattern or Template can recreate an id abandon() deleted, since
   * Query output ids are content hashes. Call once, after every write step,
   * before the recipe write.
   *
   * @param string[] $created
   *   Report lines, "entity_type:id", of everything the write phase saved.
   */
  public function reconcile(array $created): void {
    $manifest = $this->ownership->load($this->themeId);
    foreach ($this->abandoned as $type => $ids) {
      $stale = array_filter($ids, static fn (string $id): bool => !in_array("$type:$id", $created, TRUE));
      $manifest['entities'][$type] = array_values(array_diff($manifest['entities'][$type], $stale));
    }
    $this->ownership->save($this->themeId, $manifest);
  }

  /**
   * Runs $body; on a Throwable, runs every recorded undo in reverse order.
   *
   * Every undo runs even when an earlier one throws; a failure is
   * collected, not stopped on.
   *
   * With no undo failure, the original exception rethrows unchanged. With
   * any undo failure, a RuntimeException names the first failure's class,
   * file and line and its message.
   *
   * The RuntimeException carries $body's own exception as its previous, so
   * that instance stays reachable through getPrevious().
   *
   * @throws \Throwable
   *   $body's own exception, unchanged, when every undo succeeds.
   * @throws \RuntimeException
   *   Naming the first undo failure, with $body's exception as its
   *   previous, when any undo fails.
   */
  public function run(callable $body): mixed {
    try {
      return $body();
    }
    catch (\Throwable $exception) {
      $failures = [];
      foreach (array_reverse($this->undo) as $undo) {
        try {
          $undo();
        }
        catch (\Throwable $undoFailure) {
          $failures[] = $undoFailure;
        }
      }
      if ($failures === []) {
        throw $exception;
      }
      $first = $failures[0];
      throw new \RuntimeException(
        sprintf('Undo failed: %s (%s:%d): %s', get_class($first), $first->getFile(), $first->getLine(), $first->getMessage()),
        previous: $exception,
      );
    }
  }

}
