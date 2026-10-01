<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\trash\TrashManagerInterface;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal\Theme\ViewTags;

/**
 * Records the exact Drupal outputs owned by one theme conversion.
 */
final class ConversionOwnership {

  /**
   * Entity types a conversion's manifest tracks, referencer types first.
   *
   * Deleting an owned entity first among these limits what
   * ConfigEntityBase::preDelete() must fix or cascade-delete on its own
   * account, since a Pattern or Component built for a theme never survives
   * its own theme's replacement.
   *
   * `component` comes after `view`: deleting a View or a Pattern rebuilds
   * the block plugin discovery cache, and a still-enabled View with a
   * block display makes that rebuild recreate its views_block Component.
   * Deleting `component` last, after every entity able to trigger that
   * rebuild, removes whichever copy exists by then.
   */
  public const ENTITY_TYPES = [
    'content_template', 'page_variant', 'page_layout', 'pattern', 'pattern_preset',
    'view', 'component', 'entity_view_mode', 'menu',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ConfigFactoryInterface $config,
    private readonly string $appRoot,
    private readonly ThemeGenerator $themes,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly ?TrashManagerInterface $trashManager,
  ) {}

  /**
   * Returns the saved ownership manifest for one theme.
   */
  public function load(string $themeId): array {
    return $this->config->get("wordpal_convert.conversion.$themeId")->getRawData();
  }

  /**
   * Lists outputs still present from an earlier conversion.
   */
  public function existingOwned(string $themeId): array {
    $owned = $this->load($themeId);
    $ownedEntities = $owned['entities'] ?? [];
    $ownedFiles = $owned['files'] ?? [];
    $existing = [];
    foreach ($ownedEntities as $type => $ids) {
      foreach ($ids as $id) {
        if ($this->entities->getStorage($type)->load($id) !== NULL) {
          $existing[] = "$type:$id (owned)";
        }
      }
    }
    $this->themes->validatePaths($themeId, $ownedFiles);
    foreach ($ownedFiles as $path) {
      if (file_exists($this->appRoot . '/' . $path)) {
        $existing[] = "$path (owned)";
      }
    }
    foreach ($owned['config'] ?? [] as $name) {
      if ($this->config->get($name)->getRawData() !== []) {
        $existing[] = "$name (owned)";
      }
    }
    foreach ($owned['full_displays'] ?? [] as $name) {
      if (self::fullDisplayBuilt($this->config->get($name))) {
        $existing[] = "$name (owned)";
      }
    }
    if (isset($owned['recipe']) && file_exists($this->recipeDirectory($themeId, $owned['recipe']))) {
      $existing[] = 'recipes/' . $owned['recipe'] . ' (owned)';
    }
    foreach ($owned['pages'] ?? [] as $uuid) {
      $page = $this->entityRepository->loadEntityByUuid($owned['page_entity_type'], $uuid);
      if ($page !== NULL) {
        $existing[] = $owned['page_entity_type'] . ':' . $page->id() . ' (owned)';
      }
    }
    sort($existing);
    return $existing;
  }

  /**
   * Lists existing intended outputs and their ownership.
   */
  public function collisions(string $themeId, array $intended): array {
    $this->themes->validatePaths($themeId, $intended['files']);
    $owned = $this->load($themeId);
    // No manifest for the theme leaves every one of its own keys unset;
    // once save() writes a manifest, it always writes all three.
    $ownedEntities = $owned['entities'] ?? array_fill_keys(self::ENTITY_TYPES, []);
    $ownedFiles = $owned['files'] ?? [];
    $ownedConfig = $owned['config'] ?? [];
    $existing = [];
    foreach ($intended['entities'] as $type => $ids) {
      foreach ($ids as $id) {
        if ($this->entities->getStorage($type)->load($id) !== NULL) {
          $existing[] = "$type:$id" . (in_array($id, $ownedEntities[$type], TRUE) ? ' (owned)' : ' (unmanaged)');
        }
      }
    }
    foreach ($intended['files'] as $path) {
      if (file_exists($this->appRoot . '/' . $path)) {
        $existing[] = "$path" . (in_array($path, $ownedFiles, TRUE) ? ' (owned)' : ' (unmanaged)');
      }
    }
    foreach ($intended['config'] as $name) {
      if ($this->config->get($name)->getRawData() !== []) {
        $existing[] = $name . (in_array($name, $ownedConfig, TRUE) ? ' (owned)' : ' (unmanaged)');
      }
    }
    $ownedFullDisplays = $owned['full_displays'] ?? [];
    foreach ($intended['full_displays'] as $name) {
      if (self::fullDisplayBuilt($this->config->get($name))) {
        $existing[] = $name . (in_array($name, $ownedFullDisplays, TRUE) ? ' (owned)' : ' (unmanaged)');
      }
    }
    if (file_exists($this->recipeDirectory($themeId, $intended['recipe']))) {
      $existing[] = 'recipes/' . $intended['recipe'] . (($owned['recipe'] ?? NULL) === $intended['recipe'] ? ' (owned)' : ' (unmanaged)');
    }
    foreach ($intended['pages'] as $route => $uuid) {
      $page = $this->entityRepository->loadEntityByUuid($intended['page_entity_type'], $uuid);
      $pageOwned = ($owned['pages'][$route] ?? NULL) === $uuid;
      if ($page !== NULL) {
        $existing[] = $intended['page_entity_type'] . ':' . $page->id() . ($pageOwned ? ' (owned)' : ' (unmanaged)');
      }
      $alias = TemplatePage::alias($themeId, $route);
      $expectedPath = $page !== NULL ? '/' . $page->toUrl()->getInternalPath() : NULL;
      $aliasStorage = $this->entities->getStorage('path_alias');
      $ids = $aliasStorage->getQuery()->accessCheck(FALSE)->condition('alias', $alias)->execute();
      foreach ($aliasStorage->loadMultiple($ids) as $pathAlias) {
        $isOwned = $pageOwned && $pathAlias->getPath() === $expectedPath;
        $existing[] = "path_alias:$alias" . ($isOwned ? ' (owned)' : ' (unmanaged)');
      }
    }
    sort($existing);
    return array_unique($existing);
  }

  /**
   * Returns the full displays among $names other themes' manifests own.
   *
   * @param string $themeId
   *   The theme that intends to own $names.
   * @param string[] $names
   *   Full display config names this conversion intends to own.
   *
   * @return array<string, string[]>
   *   Names among $names, keyed by the id of the theme that owns them.
   */
  public function fullDisplaysOwnedByOthers(string $themeId, array $names): array {
    $owners = [];
    foreach ($this->config->listAll('wordpal_convert.conversion.') as $configName) {
      $otherThemeId = substr($configName, strlen('wordpal_convert.conversion.'));
      if ($otherThemeId === $themeId) {
        continue;
      }
      $overlap = array_values(array_intersect($names, $this->load($otherThemeId)['full_displays']));
      if ($overlap !== []) {
        $owners[$otherThemeId] = $overlap;
      }
    }
    return $owners;
  }

  /**
   * Drops full displays from the manifests of the themes that own them.
   *
   * A full display's config object has one owner across every conversion:
   * dropping them from the other theme's manifest here, before the taking
   * theme's own manifest lists them, keeps that invariant through
   * activation, so the other theme's next --replace never deletes them.
   *
   * @param array<string, string[]> $owners
   *   Full display names keyed by owning theme id, as
   *   fullDisplaysOwnedByOthers() returns them.
   */
  public function takeOverFullDisplays(array $owners): void {
    foreach ($owners as $otherThemeId => $names) {
      $manifest = $this->load($otherThemeId);
      $manifest['full_displays'] = array_values(array_diff($manifest['full_displays'], $names));
      $this->save($otherThemeId, $manifest);
    }
  }

  /**
   * Returns the ids of one entity type every other conversion owns.
   *
   * @return string[]
   *   Ids from the other themes' manifests.
   */
  public function ownedByOthers(string $themeId, string $entityType): array {
    $ids = [];
    foreach ($this->config->listAll('wordpal_convert.conversion.') as $configName) {
      $otherThemeId = substr($configName, strlen('wordpal_convert.conversion.'));
      if ($otherThemeId !== $themeId) {
        $ids = [...$ids, ...$this->load($otherThemeId)['entities'][$entityType]];
      }
    }
    return $ids;
  }

  /**
   * Rejects replacement without a manifest or over unmanaged outputs.
   */
  public function assertReplaceable(string $themeId, array $intended): void {
    $owned = $this->load($themeId);
    $unmanaged = [];
    foreach ($this->collisions($themeId, $intended) as $collision) {
      if (str_ends_with($collision, '(unmanaged)')) {
        $unmanaged[] = $collision;
      }
    }
    if ($unmanaged !== []) {
      throw new \RuntimeException("Conversion would overwrite unmanaged outputs:\n" . implode("\n", $unmanaged));
    }
    if ($owned === []) {
      throw new \RuntimeException('No prior conversion ownership manifest exists for this theme.');
    }
  }

  /**
   * Deletes every output an earlier conversion owns.
   *
   * The caller writes fresh outputs immediately after, so this only tears
   * down: it never restores the site's prior front page.
   *
   * @return string[]
   *   The site settings that pointed at deleted outputs, for the caller to
   *   point at their replacements: "front" (system.site page.front),
   *   "not_found" (system.site page.404), "frame"
   *   (canvas.settings default_page_variant) and "views" (an enabled
   *   listing Template View).
   */
  public function delete(string $themeId): array {
    $owned = $this->load($themeId);
    // A manifest naming another theme's recipe never reaches the deletes
    // below.
    $this->recipeDirectory($themeId, $owned['recipe']);
    $purge = function () use ($owned): void {
      foreach (DemoContentSeeder::ENTITY_TYPES as $type) {
        foreach ($owned['demo_content'][$type] as $uuid) {
          // A site builder may have deleted this entity, and deleting a post
          // deletes its comments.
          foreach ($this->entities->getStorage($type)->loadByProperties(['uuid' => $uuid]) as $entity) {
            $entity->delete();
          }
        }
      }
    };
    // Trash only soft-deletes outside its ignore context, and a trashed
    // entity keeps the stable UUID the next seed creates again.
    $this->trashManager === NULL ? $purge() : $this->trashManager->executeInTrashContext('ignore', $purge);
    // Deleting the Demo files leaves their directory and its parent behind.
    $directory = DemoContentSeeder::directory($themeId);
    foreach ([$directory, dirname($directory)] as $path) {
      if (is_dir($path) && !(new \FilesystemIterator($path))->valid()) {
        rmdir($path);
      }
    }
    $wired = [];
    $this->themes->validatePaths($themeId, $owned['files']);
    $site = $this->config->getEditable('system.site');
    foreach ($owned['pages'] as $uuid) {
      $page = $this->entityRepository->loadEntityByUuid($owned['page_entity_type'], $uuid);
      if ($page === NULL) {
        continue;
      }
      $path = $page->get('path')->alias;
      if ($site->get('page.front') === $path) {
        $wired[] = 'front';
        // A page the caller writes next overwrites this; one that writes
        // none leaves the site on core's own default front page.
        $site->set('page.front', '/node')->save();
      }
      if ($site->get('page.404') === $path) {
        $wired[] = 'not_found';
        $site->set('page.404', '')->save();
      }
      // Trash only soft-deletes outside its ignore context, and a trashed
      // node keeps its stable UUID, colliding with the next conversion's.
      $delete = static function () use ($page): void {
        $page->delete();
      };
      $this->trashManager === NULL ? $delete() : $this->trashManager->executeInTrashContext('ignore', $delete);
    }
    $canvas = $this->config->getEditable('canvas.settings');
    if (in_array($canvas->get('default_page_variant'), $owned['entities']['page_variant'], TRUE)) {
      // No default renders pages through core block layout until an
      // activating conversion sets its own frame.
      $wired[] = 'frame';
      $canvas->set('default_page_variant', NULL)->save();
    }
    foreach ($this->entities->getStorage('view')->loadMultiple($owned['entities']['view']) as $view) {
      if ($view->status() && $view->get('tag') === ViewTags::TEMPLATE) {
        $wired[] = 'views';
        break;
      }
    }
    $this->deleteEntities($owned['entities']);
    foreach ($owned['config'] as $name) {
      // A conversion owns only its own theme's settings, so a manifest
      // naming any other config object never deletes it.
      if ($name !== ThemeSettings::configName($themeId)) {
        throw new \UnexpectedValueException("Ownership manifest for $themeId names config it cannot own: $name");
      }
      $this->config->getEditable($name)->delete();
    }
    foreach ($owned['full_displays'] ?? [] as $name) {
      // Unlike $owned['config'] above, a full display's own config object is
      // site-wide bundle infrastructure this conversion never owns outright
      // (the field it renders through survives a replace, like
      // DisplayBuilderWriter's override field): only the sources this
      // conversion wrote come out, so the next write starts clean without
      // deleting a Manage Display config another bundle feature still needs.
      $this->config->getEditable($name)->clear('third_party_settings.display_builder')->save();
    }
    foreach ($owned['files'] as $path) {
      $absolute = $this->appRoot . '/' . $path;
      if (is_file($absolute)) {
        unlink($absolute);
      }
    }
    return $wired;
  }

  /**
   * Deletes owned entities, in self::ENTITY_TYPES order.
   *
   * @param array<string, string[]> $entities
   *   Entity ids keyed by entity type, from self::ENTITY_TYPES.
   */
  public function deleteEntities(array $entities): void {
    foreach (self::ENTITY_TYPES as $type) {
      foreach ($entities[$type] as $id) {
        // ConfigEntityBase::preDelete() may have already cascade-deleted
        // this id as a dependent of an entity type deleted earlier in
        // self::ENTITY_TYPES, so load() here can come back NULL.
        $this->entities->getStorage($type)->load($id)?->delete();
      }
    }
  }

  /**
   * Returns whether a full display config already holds painted sources.
   *
   * A bundle's `core.entity_view_display...full` config normally exists
   * before any conversion, from ordinary Manage Display use, so its mere
   * existence is not a collision; only Display Builder content under it is.
   */
  private static function fullDisplayBuilt(ImmutableConfig $display): bool {
    return ($display->get('third_party_settings.display_builder.sources') ?? []) !== [];
  }

  /**
   * Returns the Site recipe directory a manifest names.
   *
   * @throws \UnexpectedValueException
   *   When the manifest names another recipe than the theme's own.
   */
  private function recipeDirectory(string $themeId, string $recipe): string {
    // The manifest is editable config, and delete() removes this directory.
    if ($recipe !== SiteRecipeWriter::name($themeId)) {
      throw new \UnexpectedValueException("Ownership manifest for $themeId names a recipe it cannot own: $recipe");
    }
    return SiteRecipeWriter::directory($this->appRoot, $themeId);
  }

  /**
   * Validates output paths and saves the ownership manifest.
   */
  public function save(string $themeId, array $outputs): void {
    $this->themes->validatePaths($themeId, $outputs['files']);
    $this->config->getEditable("wordpal_convert.conversion.$themeId")
      ->setData($outputs)->save();
  }

}
