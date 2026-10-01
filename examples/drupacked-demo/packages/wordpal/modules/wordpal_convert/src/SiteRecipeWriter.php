<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DefaultContent\AdminAccountSwitcher;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\wordpal\Theme\StyleVariation;
use Drupal\wordpal_convert\EventSubscriber\SiteRecipeExport;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use Drupal\wordpal_convert\Content\SiteSetup;
use Drupal\wordpal_convert\Write\CanvasInlineFormat;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes a conversion's Site recipe from its ownership manifest.
 *
 * The recipe applies the WordPal recipe, installs the generated theme, the
 * target's own runtime module, and the modules the Owned config depends on,
 * creates the Owned config, points the site settings at the converted
 * outputs as they point locally, and imports the front page, 404 page and
 * Demo content exported by core's content exporter. Demo comments stay out.
 */
final class SiteRecipeWriter {

  /**
   * The modules production does not install.
   *
   * Each converter's runtime-visible plugins and hooks - wordpal_canvas's
   * PatternArea and ensure_array adapter, wordpal_display_builder's
   * PatternPresetArea and page-content wrapper - live in a `_runtime`
   * companion module instead, so a saved config's own dependencies never
   * name a converter here.
   */
  private const DEVELOPMENT_MODULES = ['wordpal_convert', 'wordpal_canvas', 'wordpal_display_builder'];

  /**
   * The profile every Display Builder output names.
   *
   * Display Builder ships it as optional config, which a recipe's module
   * install leaves out.
   */
  private const DISPLAY_BUILDER_PROFILE = 'display_builder.profile.default';

  /**
   * The recipe holding the content model and core's archive View.
   *
   * The archives block lists months through that View, which node installs
   * only as disabled optional config.
   */
  private const BLOG_RECIPE = 'wordpal';

  /**
   * The content template properties a conversion writes.
   */
  private const TEMPLATE_PROPERTIES = ['status', 'component_tree', 'exposed_slots', 'page_variant'];

  public function __construct(
    private readonly string $appRoot,
    private readonly ConversionOwnership $ownership,
    private readonly StorageInterface $configStorage,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly Exporter $exporter,
    private readonly AdminAccountSwitcher $accountSwitcher,
    private readonly SiteRecipeExport $export,
    private readonly WriterSelector $writers,
  ) {}

  /**
   * Returns the name of a theme's Site recipe, which is its directory name.
   */
  public static function name(string $themeId): string {
    return "wordpal_$themeId";
  }

  /**
   * Returns the directory of a theme's Site recipe.
   */
  public static function directory(string $appRoot, string $themeId): string {
    return SiteSetup::recipesDirectory($appRoot) . '/' . self::name($themeId);
  }

  /**
   * Replaces a theme's Site recipe with one built from its manifest.
   *
   * @return string[]
   *   One line per Owned entity left out because it fails validation.
   *
   * @throws \RuntimeException
   *   When Owned config is missing or depends on a development module, or
   *   when the front or 404 page has no path alias.
   */
  public function write(string $themeId): array {
    $manifest = $this->ownership->load($themeId);
    $config = [...$this->ownedConfig($manifest), ...$this->sharedConfig($manifest)];
    // Every conversion for this target installs its runtime
    // module, not only one whose Owned config happens to depend on it.
    $modules = ['wordpal' => TRUE, $this->writers->forTarget($manifest['target'])->runtimeModule() => TRUE];
    // An exported alias carries pathauto's skip state, which core's importer
    // accepts only with pathauto installed.
    $modules['pathauto'] = TRUE;
    foreach ($manifest['entities'] as $type => $ids) {
      if ($ids !== []) {
        $modules[$this->entityTypeManager->getDefinition($type)->getProvider()] = TRUE;
      }
    }
    foreach ($manifest['demo_content'] as $type => $uuids) {
      if ($uuids !== []) {
        $modules[$this->entityTypeManager->getDefinition($type)->getProvider()] = TRUE;
      }
    }
    foreach ($config as $name => $data) {
      foreach ($data['dependencies']['module'] ?? [] as $module) {
        if (in_array($module, self::DEVELOPMENT_MODULES, TRUE)) {
          throw new \RuntimeException("Owned config $name depends on $module, which production does not install.");
        }
        $modules[$module] = TRUE;
      }
    }
    unset($modules['core']);
    ksort($modules);

    $directory = self::directory($this->appRoot, $themeId);
    $staging = "$directory." . bin2hex(random_bytes(8));
    $filesystem = new Filesystem();
    try {
      $leftOut = $this->writeTo($staging, $themeId, $manifest, $config, $modules);
      $filesystem->remove($directory);
      $filesystem->rename($staging, $directory);
    }
    finally {
      $filesystem->remove($staging);
    }
    return $leftOut;
  }

  /**
   * Writes the recipe files into an empty directory.
   *
   * @return string[]
   *   One line per Owned entity left out because it fails validation.
   */
  private function writeTo(string $directory, string $themeId, array $manifest, array $config, array $modules): array {
    $filesystem = new Filesystem();
    $filesystem->mkdir("$directory/config");
    foreach ($config as $name => $data) {
      unset($data['_core']);
      $filesystem->dumpFile("$directory/config/$name.yml", Yaml::encode($data));
    }
    $leftOut = [];
    $comments = count($manifest['demo_content']['comment']);
    if ($comments > 0) {
      $leftOut[] = "Site recipe: $comments Demo comments: core's importer gives anonymous content to the administrator.";
    }
    // Core's importer validates each entity as the site's administrator,
    // and Demo content is saved without validation.
    $this->accountSwitcher->switchToAdministrator();
    $this->export->setActive(TRUE);
    try {
      foreach ($this->ownedContent($manifest) as $entity) {
        $violations = $entity->validate();
        if ($violations->count() > 0) {
          $leftOut[] = sprintf('Site recipe: %s %s: %s: %s', $entity->getEntityTypeId(), $entity->uuid(), $violations->get(0)->getPropertyPath(), $violations->get(0)->getMessage());
          continue;
        }
        $this->exporter->exportToFile($entity, "$directory/content");
      }
    }
    finally {
      $this->export->setActive(FALSE);
      $this->accountSwitcher->switchBack();
    }
    // Drupal CMS ships a disabled page content template, which a conversion
    // takes over. The site keeps other existing config.
    $recipeConfig = ['strict' => FALSE];
    if ($manifest['target'] === 'display_builder') {
      $recipeConfig['import'] = ['display_builder' => [self::DISPLAY_BUILDER_PROFILE]];
    }
    $recipeConfig['actions'] = $this->actions($themeId, $manifest, $config);
    $filesystem->dumpFile("$directory/recipe.yml", Yaml::encode([
      'name' => "WordPal $themeId",
      'description' => "Deploys the $themeId conversion: its theme, Builder configuration, front page, 404 page and Demo content. Written by wordpal:convert.",
      'type' => 'Site',
      'recipes' => [self::BLOG_RECIPE],
      'install' => [...array_keys($modules), $themeId],
      'config' => $recipeConfig,
    ]));
    return $leftOut;
  }

  /**
   * Reads every Owned config object from active storage, keyed by name.
   */
  private function ownedConfig(array $manifest): array {
    $names = $manifest['config'];
    foreach ($manifest['entities'] as $type => $ids) {
      // A target owns no ids of an entity type another target's writer
      // uses, and its module need not be enabled to look up its prefix.
      if ($ids === []) {
        continue;
      }
      $prefix = $this->entityTypeManager->getDefinition($type)->getConfigPrefix();
      foreach ($ids as $id) {
        $names[] = "$prefix.$id";
      }
    }
    sort($names);
    $config = [];
    foreach ($names as $name) {
      $data = $this->configStorage->read($name);
      if ($data === FALSE) {
        throw new \RuntimeException("Owned config $name does not exist.");
      }
      $config[$name] = $data;
    }
    return $config;
  }

  /**
   * Reads a bundle's full display and its override field, keyed by name.
   *
   * A node page's override field and every mapped bundle's full display are
   * site-wide content model config `wordpal:convert` finds, or creates once
   * (see DisplayBuilderWriter::ensureOverrideField()), and never deletes,
   * unlike a theme's own Owned config in ownedConfig(). The recipe still
   * needs a copy: this dump lets it create the full display fresh on a site
   * that never had one; actions() forces its display_builder settings onto
   * one that already exists, since a fresh site's own default view display
   * for the bundle is a real, pre-existing config strict:FALSE otherwise
   * leaves untouched.
   */
  private function sharedConfig(array $manifest): array {
    $config = [];
    foreach ($manifest['full_displays'] as $displayName) {
      $display = $this->configStorage->read($displayName);
      $config[$displayName] = $display;
      $fieldName = $display['third_party_settings']['display_builder']['override_field'] ?? NULL;
      if ($fieldName === NULL) {
        continue;
      }
      foreach (["field.storage.node.$fieldName", "field.field.node.{$display['bundle']}.$fieldName"] as $name) {
        $config[$name] = $this->configStorage->read($name);
      }
    }
    return $config;
  }

  /**
   * Loads the Owned pages and Demo content.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The entities a site builder has not deleted.
   */
  private function ownedContent(array $manifest): array {
    // A site builder may delete an owned page or Demo entity, and the
    // recipe then leaves it out.
    $entities = [];
    foreach ($manifest['pages'] as $uuid) {
      $entities[] = $this->entityRepository->loadEntityByUuid($manifest['page_entity_type'], $uuid);
    }
    // An anonymous comment's author name fails validation once core's
    // importer gives the comment to the administrator.
    foreach (array_diff(DemoContentSeeder::ENTITY_TYPES, ['comment']) as $type) {
      foreach ($manifest['demo_content'][$type] as $uuid) {
        $entities[] = $this->entityRepository->loadEntityByUuid($type, $uuid);
      }
    }
    return array_filter($entities);
  }

  /**
   * Builds the config actions that repeat the local site's wiring.
   *
   * Each setting the conversion activated locally is set the same way. Page
   * settings use the page's path alias, because imported pages get new ids.
   * Each Owned content template is written over the site's own, as a
   * conversion does to a disabled one.
   */
  private function actions(string $themeId, array $manifest, array $config): array {
    $actions = [];
    foreach ($manifest['entities']['content_template'] as $id) {
      $data = $config["canvas.content_template.$id"];
      $actions["canvas.content_template.$id"]['setProperties'] = [];
      foreach (self::TEMPLATE_PROPERTIES as $property) {
        $actions["canvas.content_template.$id"]['setProperties'][$property] = $data[$property];
      }
    }
    foreach ($manifest['full_displays'] as $name) {
      $data = $config[$name]['third_party_settings']['display_builder'];
      $keys = ['profile'];
      if (isset($data['override_field'])) {
        // The override field lets a route's own node (writeStaticPage()'s
        // home or 404 page) override this shared display; sharedConfig()
        // already creates the field itself, so only the display's own
        // settings need forcing here, the same as a page Template's sources
        // below. A bundle can carry both: the page bundle's full display
        // holds the override field for its static pages and, when a page
        // Template also maps to that bundle, the sources every other node
        // of the bundle renders with.
        $keys[] = 'override_field';
        $keys[] = 'override_profile';
      }
      if (isset($data['sources'])) {
        $keys[] = 'sources';
      }
      foreach ($keys as $key) {
        $actions[$name]['setThirdPartySettings'][] = [
          'module' => 'display_builder',
          'key' => $key,
          'value' => $data[$key],
        ];
      }
    }
    if ($manifest['target'] === 'display_builder') {
      // Display Builder ships the Design tokens island off.
      $actions[self::DISPLAY_BUILDER_PROFILE]['setProperties']['islands.tokens.status'] = TRUE;
    }
    if ($manifest['target'] === 'canvas') {
      $actions += CanvasInlineFormat::recipeActions($this->configFactory);
    }
    $variation = $this->configFactory->get("$themeId.settings")->get(StyleVariation::SETTING);
    if ($variation !== NULL) {
      $actions["$themeId.settings"]['simpleConfigUpdate'][StyleVariation::SETTING] = $variation;
    }
    if ($this->configFactory->get('system.theme')->get('default') === $themeId) {
      $actions['system.theme']['simpleConfigUpdate']['default'] = $themeId;
    }
    $site = $this->configFactory->get('system.site');
    foreach ($manifest['pages'] as $uuid) {
      $page = $this->entityRepository->loadEntityByUuid($manifest['page_entity_type'], $uuid);
      // A deleted page is no longer wired to any setting.
      if ($page === NULL) {
        continue;
      }
      // The runner stores the page's alias, which production resolves.
      $alias = $page->get('path')->alias;
      foreach (['page.front', 'page.404'] as $setting) {
        if ($site->get($setting) === $alias) {
          $actions['system.site']['simpleConfigUpdate'][$setting] = $alias;
        }
      }
    }
    $frame = $this->configFactory->get('canvas.settings')->get('default_page_variant');
    if (in_array($frame, $manifest['entities']['page_variant'], TRUE)) {
      $actions['canvas.settings']['simpleConfigUpdate']['default_page_variant'] = $frame;
    }
    foreach ($manifest['replaced_views'] as $id) {
      $actions["views.view.$id"]['setStatus'] = FALSE;
    }
    return $actions;
  }

}
