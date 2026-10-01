<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\EventSubscriber;

use Drupal\Core\DefaultContent\ExportMetadata;
use Drupal\Core\DefaultContent\PreExportEvent;
use Drupal\path\Plugin\Field\FieldType\PathItem;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adjusts core's content export while the Site recipe writer exports.
 *
 * Creation dates order the Demo posts on listings, and core's exporter
 * leaves them out by default. Core's exporter writes password hashes, and
 * a Site recipe is committed to version control. Pathauto (contrib)
 * replaces an imported alias unless the item carries its skip state, and
 * the recipe names the front and 404 pages by alias.
 */
final class SiteRecipeExport implements EventSubscriberInterface {

  /**
   * Whether the Site recipe writer is exporting.
   */
  private bool $active = FALSE;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After the path module's subscriber registers its path export callback.
    return [PreExportEvent::class => ['adjust', -100]];
  }

  /**
   * Sets whether the Site recipe writer is exporting.
   */
  public function setActive(bool $active): void {
    $this->active = $active;
  }

  /**
   * Keeps creation dates and aliases, and leaves out user passwords.
   */
  public function adjust(PreExportEvent $event): void {
    if (!$this->active) {
      return;
    }
    if ($event->entity->hasField('created')) {
      $event->setExportable('created', TRUE);
    }
    if ($event->entity instanceof UserInterface) {
      $event->setExportable('pass', FALSE);
    }
    $exportPath = $event->getCallbacks()['field_item:path'];
    $event->setCallback('field_item:path', static function (PathItem $item, ExportMetadata $metadata) use ($exportPath): array {
      $values = $exportPath($item, $metadata);
      if ($values['alias'] !== NULL) {
        // \Drupal\pathauto\PathautoState::SKIP, which autoloads only on a
        // site with pathauto enabled.
        $values['pathauto'] = 0;
      }
      return $values;
    });
  }

}
