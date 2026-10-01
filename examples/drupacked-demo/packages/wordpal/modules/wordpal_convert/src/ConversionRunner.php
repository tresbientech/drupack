<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Theme\Registry;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\wordpal_convert\Component\AttributeFlattener;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Content\ContentMappingLoader;
use Drupal\wordpal_convert\Content\ContentMappingTargetValidator;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use Drupal\wordpal_convert\Theme\DrupalRouteFrame;
use Drupal\wordpal_convert\Theme\PartSet;
use Drupal\wordpal_convert\Theme\QueryLoop;
use Drupal\wordpal_convert\Theme\ResolvedPattern;
use Drupal\wordpal_convert\Theme\ResolvedTemplate;
use Drupal\wordpal_convert\Theme\TemplateResolver;
use Drupal\wordpal_convert\Theme\ThemeTrees;
use Drupal\wordpal_convert\Theme\ScriptDependencies;
use Drupal\wordpal_convert\Theme\ThemeGenerator;
use Drupal\wordpal_convert\Theme\TreeConcepts;
use Drupal\wordpal\Theme\StyleVariation;
use Drupal\wordpal\Theme\ThemeSettings;
use Drupal\wordpal_convert\WordPress\Snapshot;
use Drupal\wordpal_convert\WordPress\ThemeLicense;
use Drupal\wordpal_convert\Support\FrozenHoles;
use Drupal\wordpal_convert\Write\CanvasInlineFormat;
use Drupal\wordpal_convert\Write\ConversionTransaction;
use Drupal\user\RoleInterface;
use Drupal\views\Entity\View;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs one wordpal:convert conversion against an already-loaded snapshot.
 *
 * Holds the orchestration wordpal:convert drives: the Drush command itself
 * cannot run in a kernel test, since Drush wires its logger and IO only
 * when a real command invocation boots, so this is what a kernel test
 * drives instead.
 */
final class ConversionRunner {

  /**
   * Routes whose Template converts only when the Content mapping binds them.
   */
  private const MAPPED_ROUTES = ['page', 'search', 'tag', 'category'];

  /**
   * Routes whose Template converts to a View page and a Pattern.
   */
  private const VIEW_ROUTES = ['archive', 'search'];

  /**
   * Routes whose Template converts to a Pattern the archive View renders.
   */
  private const TERM_ROUTES = ['tag', 'category'];

  /**
   * The site settings activation sets.
   *
   * ConversionOwnership::delete() reports the ones a replace points at new
   * outputs. It never reports "comments": a replace leaves the permission.
   *
   * @see \Drupal\wordpal_convert\ConversionOwnership::delete()
   */
  private const WIRING = ['front', 'not_found', 'frame', 'views', 'comments'];

  /**
   * The page concepts a page Template's post blocks read instead.
   */
  private const PAGE_CONCEPTS = ['post' => 'page', 'post_body' => 'page_body', 'excerpt' => 'page_excerpt'];

  /**
   * The target's writer, set from prepare() or write() before either runs.
   */
  private WriterInterface $writer;

  public function __construct(
    private readonly ThemeTrees $trees,
    private readonly WriterSelector $writers,
    private readonly ContentMappingLoader $mappingLoader,
    private readonly ContentMappingTargetValidator $mappingValidator,
    private readonly ThemeGenerator $themeGenerator,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ConversionOwnership $ownership,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DemoContentSeeder $demoContentSeeder,
    private readonly ThemeInstallerInterface $themeInstaller,
    private readonly ThemeManagerInterface $themeManager,
    private readonly SiteRecipeWriter $recipeWriter,
    private readonly NavigationMenus $navigationMenus,
    private readonly Registry $themeRegistry,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly ThemeExtensionList $themeList,
    #[Autowire(service: 'cache.bootstrap')]
    private readonly CacheBackendInterface $bootstrapCache,
    private readonly AttributeFlattener $flattener,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Checks a conversion and writes its theme, ahead of its target outputs.
   *
   * Installing the theme rebuilds the container, so the caller runs write()
   * on a runner fetched after this returns.
   *
   * @param \Drupal\wordpal_convert\WordPress\Snapshot $snapshot
   *   The theme snapshot to convert.
   * @param string|null $mapping
   *   Path to an override Content mapping YAML, or NULL for the default
   *   mapping.
   * @param string $target
   *   The Builder target, "canvas" or "display_builder". The caller checks
   *   the target's module is enabled before this runs.
   * @param bool $replace
   *   Whether to delete and recreate a previous conversion's owned outputs.
   * @param bool $activate
   *   Whether to install the generated theme.
   *
   * @throws \InvalidArgumentException
   *   When the Content mapping is invalid or names invalid Drupal targets.
   * @throws \RuntimeException
   *   When outputs already exist and $replace is FALSE, or when $replace
   *   is TRUE and outputs it does not own would be overwritten.
   */
  public function prepare(Snapshot $snapshot, ?string $mapping, string $target, bool $replace, bool $activate): PreparedConversion {
    $this->writer = $this->writers->forTarget($target);
    $patternOwners = [];
    foreach ($snapshot->patterns() as $key => $pattern) {
      if (($pattern['inserter'] ?? TRUE) !== TRUE) {
        continue;
      }
      try {
        $patternOwners[$key] = $this->trees->pattern($snapshot, $pattern['slug']);
      }
      catch (\RuntimeException $exception) {
        $patternOwners[$key] = ResolvedPattern::failed($pattern, $exception->getMessage());
      }
    }

    $templates = [];
    $templateErrors = [];
    $resolved = TemplateResolver::resolve($snapshot->templateNames());
    foreach (self::TERM_ROUTES as $route) {
      // The archive route's Template already serves these term pages.
      if (isset($resolved[$route]) && $resolved[$route] === ($resolved['archive'] ?? NULL)) {
        unset($resolved[$route]);
      }
    }
    foreach ($resolved as $route => $name) {
      try {
        $templates[$route] = $this->trees->template($snapshot, $name);
        foreach ($templates[$route]->dropped as $line) {
          $templateErrors[] = TemplateResolver::templateName($route) . " Template: $line";
        }
      }
      catch (\RuntimeException $exception) {
        $templateErrors[] = TemplateResolver::templateName($route) . ' Template: ' . $exception->getMessage();
      }
    }
    $required = [];
    foreach ($templates as $route => $template) {
      $required = [...$required, ...self::routeConcepts($route, TreeConcepts::required($template->nodes))];
      $required = [...$required, ...TreeConcepts::required($template->partSet->nodes())];
    }
    foreach ($patternOwners as $owner) {
      if (!$owner->isFailed()) {
        $required = [...$required, ...TreeConcepts::required($owner->nodes)];
      }
    }
    $required = array_values(array_unique($required));
    $contentMapping = $this->mappingLoader->resolve($mapping, $required);
    $contentMapping->setThemeId(ThemeGenerator::themeId($snapshot->themeSlug));
    $this->mappingValidator->validate($contentMapping, isset($templates['single']) && TreeConcepts::requiresPostContent($templates['single']->nodes), isset($templates['search']));

    $preflightSkipped = $templateErrors;
    foreach ($patternOwners as $owner) {
      foreach ($owner->dropped as $line) {
        $preflightSkipped[] = $owner->metadata['slug'] . ": $line";
      }
    }
    $queryReason = match (TRUE) {
      $contentMapping->drops('post') => 'Query blocks require a post mapping',
      !$this->writer->supportsQueryLoops() => 'Query blocks are not on this target yet',
      default => NULL,
    };
    foreach ($patternOwners as $key => $owner) {
      if (!$owner->isFailed() && $owner->loops !== [] && $queryReason !== NULL) {
        $preflightSkipped[] = $owner->metadata['slug'] . ": $queryReason";
        unset($patternOwners[$key]);
      }
    }
    foreach ($templates as $route => $template) {
      $reason = match (TRUE) {
        $template->loops !== [] && $queryReason !== NULL => $queryReason,
        in_array($route, self::MAPPED_ROUTES, TRUE) && !$contentMapping->binds($route) => "the Content mapping drops $route",
        in_array($route, TemplatePage::ROUTES, TRUE) && $this->writer->requiresPageMapping() && !$contentMapping->binds('page') => 'the Content mapping drops page',
        in_array($route, $this->writer->unsupportedRoutes(), TRUE) => 'this Template is not on this target yet',
        default => NULL,
      };
      if ($reason !== NULL) {
        $preflightSkipped[] = TemplateResolver::templateName($route) . " Template: $reason";
        unset($templates[$route]);
      }
    }
    foreach (ScriptDependencies::resolve($snapshot->themeScripts(), ThemeGenerator::themeId($snapshot->themeSlug))['skipped'] as $handle => $reason) {
      $preflightSkipped[] = "Theme script $handle: $reason";
    }
    $partSets = self::templatePartSets($templates, $snapshot->themeSlug);
    $unregistered = self::unregisteredNamespaces(self::trees($patternOwners, $partSets, $templates), $snapshot->registeredBlocks());
    $invalid = self::invalidBlocks(self::trees($patternOwners, $partSets, $templates));
    $navigation = NavigationMenus::collect(self::trees($patternOwners, $partSets, $templates), ThemeGenerator::themeId($snapshot->themeSlug), $snapshot->themeName, $contentMapping->binds('navigation'));
    // Drupal-owned routes render through the page route's Template, and get
    // a frame only when it converts. The page Template's own tree already
    // went through the namespace and navigation checks above.
    $routeFrame = isset($templates['page']) ? DrupalRouteFrame::partSet($templates['page']) : NULL;
    $noFrame = match (TRUE) {
      !isset($templates['page']) => 'no page Template converts',
      $routeFrame === NULL => 'the page Template has no Post Content block to hold their content',
      // A Frozen block prints its WordPress HTML, never the main content.
      DrupalRouteFrame::holdsMainContent($this->writer->frozenNodes($routeFrame->nodes(), $contentMapping)) => 'the page Template places Post Content in a Frozen block',
      default => NULL,
    };
    if ($noFrame === NULL) {
      $partSets[$routeFrame->id($snapshot->themeSlug)] = $routeFrame;
    }
    else {
      $preflightSkipped[] = "Drupal-owned routes: $noFrame, so they render without a converted frame.";
    }
    $partTrees = self::partTrees($partSets, $snapshot->postsPerPage(), $templates, $patternOwners, $queryReason);
    $routesByPartSet = self::routesByPartSet($templates, $snapshot->themeSlug);
    foreach ($partSets as $id => $partSet) {
      foreach ($partTrees[$id] as $tree) {
        foreach ($tree['dropped'] as $line) {
          $preflightSkipped[] = $partSet->label() . " frame: $line";
        }
        $listing = self::listingRoutes($partSet, $routesByPartSet, $snapshot->themeSlug);
        if (count($listing) > 1 && array_filter($tree['loops'], static fn (QueryLoop $loop): bool => $loop->inherit) !== []) {
          $preflightSkipped[] = $partSet->label() . " frame: its inheriting Query lists the posts of the $listing[0] route, though Templates for " . implode(', ', $listing) . ' share the frame.';
        }
      }
    }
    $preflightSkipped = array_values(array_unique([...$preflightSkipped, ...$navigation['skipped']]));
    $frozen = [];
    $frozenHtml = [];
    $frozenStyles = [];
    $blockStyles = $snapshot->blockStyles();
    $droppedOptions = [];
    $unreadStyles = [];
    foreach (self::trees($patternOwners, $partSets, $templates) as $label => $nodes) {
      $options = TreeConcepts::droppedOptions($nodes);
      if ($options !== []) {
        $droppedOptions[(string) $label] = $options;
      }
      $frozenNodes = $this->writer->frozenNodes($nodes, $contentMapping);
      $unread = $this->unreadStyles($nodes, $frozenNodes, $contentMapping);
      if ($unread !== []) {
        $unreadStyles[(string) $label] = $unread;
      }
      $names = array_column($frozenNodes, 'name');
      if ($names !== []) {
        $frozen[(string) $label] = $names;
        foreach ($this->writer->frozenHtml($nodes, $contentMapping) as $key => $html) {
          if (isset($frozenHtml[$key]) && $frozenHtml[$key] !== $html) {
            $preflightSkipped[] = "$label: a Frozen block rendered the same HTML as one in an earlier tree and leaves out other Queries, so it shows that block's file.";
          }
          $frozenHtml[$key] ??= $html;
        }
      }
      foreach ($this->writer->frozenKeys($nodes, $contentMapping) as $key => $name) {
        if (isset($blockStyles[$key])) {
          $frozenStyles[(string) $label][] = "$name: " . implode(', ', $blockStyles[$key]);
        }
      }
    }
    $themeId = ThemeGenerator::themeId($snapshot->themeSlug);
    $intended = $this->intendedOutputs($snapshot, $patternOwners, $partSets, $partTrees, $contentMapping, $templates, $frozenHtml, $navigation['menus'], $target);
    $takeover = $activate ? $this->disabledUnmanagedTemplates($themeId, $templates, $contentMapping) : [];
    $fullDisplayOwners = $activate ? $this->ownership->fullDisplaysOwnedByOthers($themeId, $intended['full_displays']) : [];
    $checked = $intended;
    $checked['entities']['content_template'] = array_values(array_diff($intended['entities']['content_template'], $takeover));
    $checked['full_displays'] = array_values(array_diff($intended['full_displays'], ...array_values($fullDisplayOwners)));
    if ($replace) {
      $this->ownership->assertReplaceable($themeId, $checked);
    }
    else {
      $existing = array_values(array_unique([
        ...$this->ownership->collisions($themeId, $checked),
        ...$this->ownership->existingOwned($themeId),
      ]));
      sort($existing);
      if ($existing !== [] || $this->ownership->load($themeId) !== []) {
        throw new \RuntimeException(self::existingOutputsMessage($snapshot->themeName, $existing));
      }
    }
    // Read before the replace deletes the previous conversion, so a bad
    // settings.json fails with the site unchanged.
    $settingsData = $snapshot->settings() + ['body' => self::bodyRoutes($snapshot->themeSlug, $contentMapping)];
    $this->ownership->takeOverFullDisplays($fullDisplayOwners);
    $wired = [];
    if ($replace) {
      // A replace leaves the site's replaced Views disabled, and the new
      // conversion's Site recipe disables them too, unless one was deleted
      // since.
      $intended['replaced_views'] = array_values(array_filter(
        $this->ownership->load($themeId)['replaced_views'],
        static fn (string $id): bool => View::load($id) !== NULL,
      ));
      $wired = $this->ownership->delete($themeId);
    }
    $this->ownership->save($themeId, $intended);
    $installed = $this->configFactory->get('core.extension')->get("theme.$themeId") !== NULL;
    $this->themeGenerator->generate($snapshot, $frozenHtml);
    $pluginLicenses = array_map(
      static fn (string $plugin): string => ThemeLicense::pluginLine($plugin, $snapshot->pluginHeaders()[$plugin], $snapshot->pluginFromWordPressOrg($plugin)),
      ThemeGenerator::shippedPlugins($snapshot, $frozenHtml),
    );

    $this->configFactory->getEditable(ThemeSettings::configName($themeId))
      ->setData($settingsData)
      ->save();
    if ($installed) {
      // generate() rewrote the installed theme's templates, preprocess
      // functions, info file and libraries, which its cached registry,
      // extension info, libraries and rendered markup still hold.
      $this->themeRegistry->reset();
      $this->themeList->reset();
      // ThemeInitialization caches the active theme, with the info file's
      // libraries, under this id and no cache tag.
      $this->bootstrapCache->delete('theme.active_theme.' . $themeId);
      $this->cacheTagsInvalidator->invalidateTags(['rendered', 'library_info']);
    }
    elseif ($activate) {
      // Extension discovery keeps each directory scan for the whole process,
      // and a scan from before generate() misses the theme.
      (new \ReflectionProperty(ExtensionDiscovery::class, 'files'))->setValue(NULL, []);
      $this->themeInstaller->install([$themeId]);
      $installed = TRUE;
    }
    // An installed theme keeps its settings across a replace, and the
    // variation they select may be gone from the new snapshot. Installing
    // the theme is what creates its settings otherwise.
    $themeSettings = $this->configFactory->getEditable("$themeId.settings");
    if ($installed && !isset($snapshot->variations()[(string) $themeSettings->get(StyleVariation::SETTING)])) {
      $themeSettings->set(StyleVariation::SETTING, Snapshot::DEFAULT_VARIATION)->save();
    }
    return new PreparedConversion($target, $snapshot, $contentMapping, $patternOwners, $partSets, $partTrees, $templates, $intended, $wired, $takeover, $preflightSkipped, $unregistered, $invalid, $frozen, $droppedOptions, $navigation['menus'], $frozenStyles, $unreadStyles, $required, $pluginLicenses);
  }

  /**
   * Explains a repeat conversion of a theme, or returns NULL for a new one.
   *
   * Reads only the ownership manifest, so the command can refuse a repeat
   * before it loads a snapshot or runs WordPress.
   */
  public function alreadyConverted(string $themeSlug): ?string {
    $themeId = ThemeGenerator::themeId($themeSlug);
    if ($this->ownership->load($themeId) === []) {
      return NULL;
    }
    return self::existingOutputsMessage($themeSlug, $this->ownership->existingOwned($themeId));
  }

  /**
   * Explains why a conversion without --replace stopped before writing.
   *
   * @param string $themeName
   *   The WordPress theme's display name.
   * @param string[] $existing
   *   Existing outputs as ConversionOwnership lists them: "type:id", a file
   *   path or a config name, each followed by " (owned)" or " (unmanaged)".
   */
  private static function existingOutputsMessage(string $themeName, array $existing): string {
    $unmanaged = [];
    $counts = [];
    foreach ($existing as $line) {
      [$item, $ownership] = explode(' ', $line, 2);
      if ($ownership === '(unmanaged)') {
        $unmanaged[] = $item;
        continue;
      }
      $kind = str_contains($item, ':') ? strstr($item, ':', TRUE) : (str_contains($item, '/') ? 'file' : 'config');
      $counts[$kind] = ($counts[$kind] ?? 0) + 1;
    }
    if ($unmanaged !== []) {
      return "These items are in the way and WordPal does not own them:\n" . implode("\n", $unmanaged) . "\nNothing was changed. Move or delete them, then run the command again.";
    }
    ksort($counts);
    $summary = $counts === [] ? '' : sprintf(': %d outputs (%s)', array_sum($counts), implode(', ', array_map(static fn (string $kind, int $count): string => "$count $kind", array_keys($counts), $counts)));
    return "$themeName is already converted on this site$summary. Nothing was changed. Run the command again with --replace to delete and recreate them.";
  }

  /**
   * Lists the disabled content templates activation may take over.
   *
   * A site's own full content template on the post or page bundle blocks
   * conversion while enabled. A disabled one renders nothing, so the
   * single or page Template replaces it. A target with its own
   * fullDisplayConfigName() has no "disabled content template" concept:
   * its full display is a single, always-active config object, taken over
   * through the full_displays ownership check instead, so this never loads
   * the Canvas-only "content_template" entity type for it.
   *
   * @return string[]
   *   Ids of disabled content templates the conversion does not own.
   */
  private function disabledUnmanagedTemplates(string $themeId, array $templates, ContentMapping $mapping): array {
    $owned = $this->ownership->load($themeId)['entities']['content_template'] ?? [];
    $takeover = [];
    foreach (['single' => 'post', 'page' => 'page'] as $route => $concept) {
      if (!isset($templates[$route]) || !$mapping->binds($concept)) {
        continue;
      }
      $bundle = (string) $mapping->target($concept);
      if ($this->writer->fullDisplayConfigName($bundle) !== NULL) {
        continue;
      }
      $id = "node.$bundle.full";
      $template = ContentTemplate::load($id);
      if ($template !== NULL && !$template->status() && !in_array($id, $owned, TRUE)) {
        $takeover[] = $id;
      }
    }
    return $takeover;
  }

  /**
   * Returns what BodyClasses reads to print WordPress's body classes.
   *
   * Every block theme supports responsive embeds in WordPress, which prints
   * wp-embed-responsive on each page, with the theme's slug.
   *
   * @see \Drupal\wordpal\Theme\BodyClasses
   */
  private static function bodyRoutes(string $themeSlug, ContentMapping $mapping): array {
    $body = [
      'classes' => ['wp-embed-responsive', "wp-theme-$themeSlug"],
      'bundles' => [],
      'vocabularies' => [],
      'search' => $mapping->binds('search') ? $mapping->target('search') : NULL,
    ];
    foreach (['post' => 'single', 'page' => 'page'] as $concept => $route) {
      if ($mapping->binds($concept)) {
        $body['bundles'][$mapping->target($concept)] = $route;
      }
    }
    foreach (['tag', 'category'] as $concept) {
      if ($mapping->binds($concept)) {
        $body['vocabularies'][$mapping->target($concept)['vocabulary']] = $concept;
      }
    }
    return $body;
  }

  /**
   * Writes a prepared conversion's outputs, Demo content and Site recipe.
   *
   * @param \Drupal\wordpal_convert\PreparedConversion $conversion
   *   What prepare() returned.
   * @param bool $demoContent
   *   Whether to seed Demo content when the mapped post bundle has no nodes.
   * @param bool $activate
   *   Whether to make the installed theme the default theme and its home the
   *   front page.
   */
  public function write(PreparedConversion $conversion, bool $demoContent, bool $activate): ConversionReport {
    $this->writer = $this->writers->forTarget($conversion->target);
    $themeId = ThemeGenerator::themeId($conversion->snapshot->themeSlug);
    $wiring = $activate ? self::WIRING : $conversion->wired;
    $tx = new ConversionTransaction($this->ownership, $this->entityTypeManager, $themeId);
    if ($activate) {
      // Canvas renders components while validating what the writer saves,
      // and WordPal components render with the active theme's settings.
      $tx->setConfig($this->configFactory->getEditable('system.theme'), 'default', $themeId);
      $this->themeManager->resetActiveTheme();
    }
    try {
      return $tx->run(fn (): ConversionReport => $this->writeOutputs($conversion, $demoContent, $wiring, $tx));
    }
    finally {
      $this->themeManager->resetActiveTheme();
    }
  }

  /**
   * Writes the target outputs, Demo content and Site recipe.
   */
  private function writeOutputs(PreparedConversion $conversion, bool $demoContent, array $wiring, ConversionTransaction $tx): ConversionReport {
    $snapshot = $conversion->snapshot;
    $contentMapping = $conversion->mapping;
    $themeId = ThemeGenerator::themeId($snapshot->themeSlug);
    $partSets = $conversion->partSets;
    $templates = $conversion->templates;
    foreach ($conversion->takeover as $id) {
      $tx->takeOver('content_template', $id);
    }
    // Canvas renders each Navigation block while validating what the
    // writer saves, and the block needs its menu.
    $created = array_map(self::line(...), $this->navigationMenus->write($themeId, $conversion->menus));

    $skippedPatterns = [];
    $skippedTemplates = [];
    $frozen = $conversion->frozen;
    $frozenStyles = $conversion->frozenStyles;
    $unreadStyles = $conversion->unreadStyles;
    foreach ($conversion->patterns as $owner) {
      $pattern = $owner->metadata;
      if ($owner->isFailed()) {
        $skippedPatterns[] = $pattern['slug'] . ': ' . $owner->error;
        continue;
      }
      $patternId = self::patternId($snapshot->themeSlug, $pattern['slug']);
      try {
        if ($owner->loops !== []) {
          ['pattern' => $savedPattern, 'queries' => $queries] = $this->writer->writeQueryPattern($patternId, $pattern['title'], $owner->nodes, $owner->loops, $contentMapping, $snapshot->themeSlug);
          $created = [...$created, self::line($savedPattern), ...self::queryLines($queries)];
        }
        else {
          $created[] = self::line($this->writer->writePattern($patternId, $pattern['title'], $owner->nodes, $contentMapping));
        }
      }
      catch (\UnexpectedValueException $exception) {
        $skippedPatterns[] = $pattern['slug'] . ': ' . $exception->getMessage();
        // The Pattern's frozen HTML files stay Owned theme files.
        unset($frozen[$pattern['slug']], $frozenStyles[$pattern['slug']], $unreadStyles[$pattern['slug']]);
        $candidates = array_fill_keys(ConversionOwnership::ENTITY_TYPES, []);
        $candidates[$this->writer->patternEntityType()][] = $patternId;
        foreach ($owner->loops as $loop) {
          $this->addQueryOutputs($candidates, $snapshot->themeSlug, $loop, $contentMapping->target('post'), NULL);
        }
        // A Query output an earlier Pattern saved stays with that Pattern.
        // The rest were saved for this Pattern alone: a content template
        // validates only once its view mode exists, and a views_block
        // Component only once its View exists, so validation can fail
        // after them.
        $tx->abandon($candidates, $created);
      }
    }

    $routesByPartSet = self::routesByPartSet($templates, $snapshot->themeSlug);
    foreach ($partSets as $id => $partSet) {
      ['variant' => $variant, 'queries' => $queries] = $this->writer->writePageVariant($partSet, $snapshot->themeSlug, $contentMapping, $conversion->partTrees[$id], self::listingRoute($partSet, $routesByPartSet, $snapshot->themeSlug), $routesByPartSet);
      $created = [...$created, self::line($variant), ...self::queryLines($queries)];
    }
    $pages = [];
    foreach (TemplatePage::ROUTES as $route) {
      if (!isset($templates[$route])) {
        continue;
      }
      $template = $templates[$route];
      if ($template->loops === []) {
        $pages[$route] = $this->writer->writeStaticPage($route, $template->nodes, $contentMapping, $snapshot->themeSlug, $template->partSet->id($snapshot->themeSlug));
      }
      else {
        ['page' => $page, 'queries' => $queries] = $this->writer->writeQueryPage($route, $contentMapping->target('post'), $template, $contentMapping, $snapshot->themeSlug);
        $pages[$route] = $page;
        $created = [...$created, ...self::queryLines($queries)];
      }
      $created[] = self::line($pages[$route]);
    }
    if (isset($templates['single'])) {
      $single = $templates['single'];
      $bundle = $contentMapping->has('post') ? $contentMapping->target('post') : NULL;
      if ($bundle === NULL) {
        foreach (TreeConcepts::counts($single->nodes) as $concept => $count) {
          if ($contentMapping->has($concept) && $contentMapping->drops($concept)) {
            $contentMapping->recordDroppedBlocks($concept, $count);
          }
        }
      }
      else {
        try {
          $written = $this->writeFullTemplate($bundle, 'single', $single, $contentMapping, $snapshot->themeSlug);
          $created = [...$created, ...$written];
        }
        catch (\UnexpectedValueException $exception) {
          $skippedTemplates[] = TemplateResolver::templateName('single') . ' Template: ' . $exception->getMessage();
          $this->abandonTemplateOutputs($tx, $created, $bundle, $single, $contentMapping, $snapshot->themeSlug);
        }
      }
    }
    if (isset($templates['page'])) {
      $page = $templates['page'];
      $bundle = $contentMapping->target('page');
      try {
        $written = $this->writeFullTemplate($bundle, 'page', $page, $contentMapping, $snapshot->themeSlug);
        $created = [...$created, ...$written];
      }
      catch (\UnexpectedValueException $exception) {
        $skippedTemplates[] = TemplateResolver::templateName('page') . ' Template: ' . $exception->getMessage();
        $this->abandonTemplateOutputs($tx, $created, $bundle, $page, $contentMapping, $snapshot->themeSlug);
      }
    }
    foreach (self::runtimePresetBundles($contentMapping) as $bundle) {
      foreach ($this->writer->writeRuntimePresets($snapshot->themeSlug, $bundle, $contentMapping) as $preset) {
        $created[] = self::line($preset);
      }
    }
    $termPatterns = [];
    $templatePaths = [];
    foreach (self::TERM_ROUTES as $route) {
      if (isset($templates[$route])) {
        ['pattern' => $termPattern, 'queries' => $queries] = $this->writer->writeTermTemplate($route, $templates[$route], $contentMapping, $snapshot->themeSlug);
        $termPatterns[$contentMapping->target($route)['vocabulary']] = $termPattern->id();
        $termPath = $this->writer->templatePath($termPattern, NULL);
        if ($termPath !== NULL) {
          $templatePaths[] = $termPath;
        }
        $created = [...$created, self::line($termPattern), ...self::queryLines($queries)];
      }
    }
    $listingViews = [];
    foreach (self::VIEW_ROUTES as $route) {
      if (isset($templates[$route])) {
        ['pattern' => $viewPattern, 'view' => $view, 'queries' => $queries] = $this->writer->writeTemplateView($route, $templates[$route], $contentMapping, $snapshot->themeSlug, $route === 'archive' ? $termPatterns : []);
        $listingViews[] = $view;
        $templatePaths[] = $this->writer->templatePath($viewPattern, $view);
        $created = [
          ...$created,
          self::line($viewPattern),
          self::line($view),
          ...self::queryLines($queries),
        ];
      }
    }
    $created = array_values(array_unique($created));
    sort($created);

    // Demo menu items resolve against the front page.
    if (isset($pages['home']) && in_array('front', $wiring, TRUE)) {
      $tx->setConfig($this->configFactory->getEditable('system.site'), 'page.front', self::sitePath($pages['home']));
    }
    $seeded = $demoContent ? $this->demoContentSeeder->seed($contentMapping, $themeId) : NULL;
    if ($seeded !== NULL) {
      $uuids = $seeded['uuids'];
      // The Navigation blocks' own links are Owned Demo content too.
      $themeLinks = $conversion->intended['demo_content']['menu_link_content'];
      $uuids['menu_link_content'] = [...$themeLinks, ...$uuids['menu_link_content']];
      // The seed patches the live manifest, so reconcile() keeps the
      // seeded ids.
      $manifest = $this->ownership->load($themeId);
      $manifest['demo_content'] = $uuids;
      $this->ownership->save($themeId, $manifest);
    }
    if (isset($pages['not_found']) && in_array('not_found', $wiring, TRUE)) {
      $tx->setConfig($this->configFactory->getEditable('system.site'), 'page.404', self::sitePath($pages['not_found']));
    }
    // WordPress shows every visitor a post's comments.
    if (in_array('comments', $wiring, TRUE) && in_array('comments', $conversion->concepts, TRUE) && $contentMapping->binds('comments')) {
      $tx->grantPermission(RoleInterface::ANONYMOUS_ID, 'access comments');
    }
    // Canvas's frame for a page with no Template of its own is one sitewide
    // setting. Display Builder's equivalent is its condition-less page
    // layout (see DisplayBuilderWriter::writePageVariant()), which needs no
    // config write, so only the canvas target wires this setting.
    $frame = $conversion->target === 'canvas' ? self::defaultFrame($partSets) : NULL;
    if ($frame !== NULL && in_array('frame', $wiring, TRUE)) {
      $tx->setConfig($this->configFactory->getEditable('canvas.settings'), 'default_page_variant', $frame);
    }
    if ($conversion->target === 'canvas') {
      CanvasInlineFormat::apply($tx, $this->configFactory);
    }
    $replacedViews = [];
    if (in_array('views', $wiring, TRUE)) {
      // A View cannot take over a route another View already controls, so
      // the site's own term and search pages step aside for the converted
      // ones.
      $replacedViews = $this->replacedViews($snapshot->themeSlug, $templates, $contentMapping);
      // Production never holds another conversion's View, so the Site
      // recipe must not name one.
      $otherConversions = $this->ownership->ownedByOthers($themeId, 'view');
      foreach ($replacedViews as $id) {
        in_array($id, $otherConversions, TRUE) ? $tx->disableView($id) : $tx->replaceView($id);
      }
      foreach ($listingViews as $view) {
        $tx->enableView($view->id());
      }
    }

    $tx->reconcile($created);
    $leftOut = $this->recipeWriter->write($themeId);

    $variations = array_map(static fn (array $variation): string => $variation['title'], $conversion->snapshot->variations());
    $skipped = [
      ...$conversion->skipped,
      ...$skippedTemplates,
      ...$this->writer->pageVariantNotes($routesByPartSet, $contentMapping),
      ...$conversion->snapshot->skippedVariations(),
      ...$conversion->snapshot->skippedAssets(),
      ...$conversion->snapshot->skippedLegalFiles(),
      ...$leftOut,
    ];
    return new ConversionReport($created, $skipped, $skippedPatterns, $contentMapping->droppedBlocks(), $contentMapping->providers(), $conversion->unregistered, $conversion->invalid, $frozen, $seeded, $conversion->droppedOptions, $replacedViews, $conversion->takeover, $this->writer->label(), $templatePaths, $variations, $conversion->menus, $frozenStyles, $unreadStyles, $conversion->pluginLicenses);
  }

  /**
   * Lists the style values a tree sets that no prop reads.
   *
   * A Frozen block keeps its HTML whole, so only the Queries in its holes
   * are walked. Dropped concepts write no block and are not walked.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   Blocks at one level of the tree.
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $frozen
   *   The tree's Frozen blocks.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets, whose dropped concepts write no block.
   *
   * @return string[]
   *   Lines such as "core/image: style.color.duotone".
   */
  private function unreadStyles(array $nodes, array $frozen, ContentMapping $mapping): array {
    $lines = [];
    foreach ($nodes as $node) {
      if (in_array($node, $frozen, TRUE)) {
        $lines = [...$lines, ...$this->unreadStyles(FrozenHoles::queries($node->children), $frozen, $mapping)];
        continue;
      }
      if (TreeConcepts::dropped(TreeConcepts::concept($node), $mapping)) {
        continue;
      }
      foreach ($this->flattener->unreadStyles($node->attributes, $node->name) as $path) {
        $lines[] = "$node->name: $path";
      }
      $lines = [...$lines, ...$this->unreadStyles($node->children, $frozen, $mapping)];
    }
    return $lines;
  }

  /**
   * Returns the listing route a Part set's inheriting Query shows.
   *
   * A term route's Template renders on the archive route's View. A Part set
   * shared by several listing routes takes the first one's.
   *
   * @param \Drupal\wordpal_convert\Theme\PartSet $partSet
   *   The Part set.
   * @param array<string, string[]> $routesByPartSet
   *   Route names using each Part set, keyed by `PartSet::id()`.
   * @param string $theme
   *   The theme slug.
   */
  private static function listingRoute(PartSet $partSet, array $routesByPartSet, string $theme): ?string {
    return self::listingRoutes($partSet, $routesByPartSet, $theme)[0] ?? NULL;
  }

  /**
   * Returns the distinct listing routes of a Part set's Templates, in order.
   *
   * @param \Drupal\wordpal_convert\Theme\PartSet $partSet
   *   The Part set.
   * @param array<string, string[]> $routesByPartSet
   *   Route names using each Part set, keyed by `PartSet::id()`.
   * @param string $theme
   *   The theme slug.
   *
   * @return string[]
   *   "archive" for a term route, else the view route's own name.
   */
  private static function listingRoutes(PartSet $partSet, array $routesByPartSet, string $theme): array {
    $listing = [];
    foreach ($routesByPartSet[$partSet->id($theme)] ?? [] as $route) {
      if (in_array($route, self::TERM_ROUTES, TRUE)) {
        $listing[] = 'archive';
      }
      elseif (in_array($route, self::VIEW_ROUTES, TRUE)) {
        $listing[] = $route;
      }
    }
    return array_values(array_unique($listing));
  }

  /**
   * Returns each Part set's parts with their Queries left out, and loops.
   *
   * A part Query numbers its pager element after the highest element any
   * Template or Inserter pattern uses, so a part Query never pages together
   * with another Query.
   *
   * @param array<string, \Drupal\wordpal_convert\Theme\PartSet> $partSets
   *   The Part sets, keyed by variant id.
   * @param int $mainPerPage
   *   The posts per page of WordPress's main query.
   * @param \Drupal\wordpal_convert\Theme\ResolvedTemplate[] $templates
   *   The Templates the conversion writes, keyed by route.
   * @param \Drupal\wordpal_convert\Theme\ResolvedPattern[] $patternOwners
   *   The Inserter patterns the conversion writes.
   * @param string|null $queryReason
   *   Why the target leaves every Query out, or NULL.
   *
   * @return array<string, array<int, array{nodes: \Drupal\wordpal_convert\Theme\BlockNode[], loops: \Drupal\wordpal_convert\Theme\QueryLoop[], dropped: string[]}>>
   *   PartSet::loops()'s result, keyed by variant id.
   */
  private static function partTrees(array $partSets, int $mainPerPage, array $templates, array $patternOwners, ?string $queryReason): array {
    $highest = 0;
    foreach ([...$templates, ...$patternOwners] as $owner) {
      // A failed Inserter pattern has no loops.
      foreach ($owner->loops ?? [] as $loop) {
        $highest = max($highest, $loop->pagerElement);
      }
    }
    return array_map(static fn (PartSet $partSet): array => $partSet->loops($mainPerPage, $highest + 1, $queryReason), $partSets);
  }

  /**
   * Returns the trees a conversion writes, keyed by their report label.
   *
   * @return array<string, \Drupal\wordpal_convert\Theme\BlockNode[]>
   *   Inserter pattern trees, Template part trees, and each route's
   *   Template main tree.
   */
  private static function trees(array $patternOwners, array $partSets, array $templates): array {
    $trees = [];
    foreach ($patternOwners as $owner) {
      if (!$owner->isFailed()) {
        $trees[$owner->metadata['slug']] = $owner->nodes;
      }
    }
    foreach ($partSets as $partSet) {
      foreach ($partSet->placements as $placement) {
        if ($placement['type'] === 'part') {
          $trees['Template part ' . $placement['slug']] = $placement['nodes'];
        }
        elseif ($placement['type'] === 'body') {
          $trees['Drupal-route frame'] = $placement['nodes'];
        }
      }
    }
    foreach ($templates as $route => $template) {
      $trees[TemplateResolver::templateName($route) . ' Template'] = $template->nodes;
    }
    return $trees;
  }

  /**
   * Returns the Part sets of the routes' Templates, keyed by variant id.
   *
   * @return array<string, \Drupal\wordpal_convert\Theme\PartSet>
   *   Templates with the same Part set share one entry.
   */
  private static function templatePartSets(array $templates, string $theme): array {
    $partSets = [];
    foreach ($templates as $template) {
      $partSets[$template->partSet->id($theme)] = $template->partSet;
    }
    return $partSets;
  }

  /**
   * Returns the routes sharing each Part set, keyed by the Part set's id.
   *
   * A target that conditions a frame on its routes reads this to know
   * every route a shared frame must satisfy at once.
   *
   * @return array<string, string[]>
   *   Route names, keyed by `PartSet::id()`.
   */
  private static function routesByPartSet(array $templates, string $theme): array {
    $routes = [];
    foreach ($templates as $route => $template) {
      $routes[$template->partSet->id($theme)][] = $route;
    }
    return $routes;
  }

  /**
   * Returns the content concepts a route's Template tree needs mapped.
   *
   * A page Template's post blocks read the page bundle, and a search
   * Template lists the mapped search page's results.
   */
  private static function routeConcepts(string $route, array $concepts): array {
    return match ($route) {
      'page' => [
        'page',
        ...array_map(static fn (string $concept): string => self::PAGE_CONCEPTS[$concept] ?? $concept, $concepts),
      ],
      'search', 'tag', 'category' => [$route, ...$concepts],
      default => $concepts,
    };
  }

  /**
   * Returns the page variant rendering routes that select none of their own.
   *
   * Every such route is Drupal-owned: a converted listing View selects its
   * own frame through wordpal_canvas_runtime.
   *
   * @param array<string, \Drupal\wordpal_convert\Theme\PartSet> $partSets
   *   The conversion's Part sets, keyed by id.
   */
  private static function defaultFrame(array $partSets): ?string {
    foreach ($partSets as $id => $partSet) {
      if (in_array('body', array_column($partSet->placements, 'type'), TRUE)) {
        return $id;
      }
    }
    return NULL;
  }

  /**
   * Lists the enabled Views whose pages the listing Templates replace.
   *
   * @return string[]
   *   Ids of the Views.
   */
  private function replacedViews(string $theme, array $templates, ContentMapping $mapping): array {
    $paths = [];
    $own = [];
    foreach (self::VIEW_ROUTES as $route) {
      if (isset($templates[$route])) {
        $paths[] = $route === 'archive' ? 'taxonomy/term/%' : ltrim($mapping->target('search')['path'], '/');
        $own[] = $this->writer->templateOutputIds($theme, $route)['view'];
      }
    }
    $replaced = [];
    foreach (View::loadMultiple() as $view) {
      if (!$view->status() || in_array($view->id(), $own, TRUE)) {
        continue;
      }
      foreach ($view->get('display') as $display) {
        // Only page displays carry a path.
        if (in_array($display['display_options']['path'] ?? NULL, $paths, TRUE)) {
          $replaced[] = $view->id();
          break;
        }
      }
    }
    return $replaced;
  }

  /**
   * Lists the block namespaces no registered block type uses.
   *
   * @param array<string, \Drupal\wordpal_convert\Theme\BlockNode[]> $trees
   *   Block trees keyed by their report label.
   * @param string[] $registeredBlocks
   *   The block names WordPress registered.
   *
   * @return array<string, string[]>
   *   Labels of the trees using each unregistered namespace, keyed by
   *   namespace.
   */
  private static function unregisteredNamespaces(array $trees, array $registeredBlocks): array {
    $registered = array_flip(array_map(static fn (string $name): string => strstr($name, '/', TRUE), $registeredBlocks));
    $unregistered = [];
    foreach ($trees as $label => $nodes) {
      foreach (self::namespaces($nodes) as $namespace) {
        if (!isset($registered[$namespace])) {
          $unregistered[$namespace][] = (string) $label;
        }
      }
    }
    ksort($unregistered);
    return $unregistered;
  }

  /**
   * Returns the namespaces of every block in a tree.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   *
   * @return string[]
   *   Unique namespaces.
   */
  private static function namespaces(array $nodes): array {
    $namespaces = [];
    foreach ($nodes as $node) {
      $namespaces[strstr($node->name, '/', TRUE)] = TRUE;
      foreach (self::namespaces($node->children) as $namespace) {
        $namespaces[$namespace] = TRUE;
      }
    }
    return array_keys($namespaces);
  }

  /**
   * Lists the blocks WordPress's own parser found invalid, per tree.
   *
   * @param array<string, \Drupal\wordpal_convert\Theme\BlockNode[]> $trees
   *   Block trees keyed by their report label.
   *
   * @return array<string, string[]>
   *   Names of the invalid blocks in each tree that holds one, keyed by
   *   tree label.
   */
  private static function invalidBlocks(array $trees): array {
    $invalid = [];
    foreach ($trees as $label => $nodes) {
      $names = self::invalidNames($nodes);
      if ($names !== []) {
        $invalid[(string) $label] = $names;
      }
    }
    return $invalid;
  }

  /**
   * Returns the names of the invalid blocks in a tree, in document order.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   *
   * @return string[]
   *   Block names, once per invalid block.
   */
  private static function invalidNames(array $nodes): array {
    $names = [];
    foreach ($nodes as $node) {
      if (!$node->isValid) {
        $names[] = $node->name;
      }
      $names = [...$names, ...self::invalidNames($node->children)];
    }
    return $names;
  }

  /**
   * Returns the path alias a system.site setting stores for a page.
   *
   * Config sync carries system.site to production, where imported pages get
   * other ids, so the setting names the page by its alias. $page is a Canvas
   * Page on the canvas target and a node on the display_builder target.
   */
  private static function sitePath(EntityInterface $page): string {
    return $page->get('path')->alias;
  }

  /**
   * Returns one saved entity's "entity_type:id" report line.
   */
  private static function line(EntityInterface $entity): string {
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

  /**
   * Returns report lines for the queries a Query-owning write returned.
   *
   * @param array[] $queries
   *   Entries shaped as CanvasWriter::saveQueryInfrastructure() or
   *   ListingWriter::saveQuery() return them: "view" always an entity, the
   *   rest an entity only when the target has one, NULL otherwise.
   */
  private static function queryLines(array $queries): array {
    $lines = [];
    foreach ($queries as $query) {
      foreach (['view_mode', 'template', 'view', 'component', 'page_template', 'empty_pattern'] as $key) {
        if ($query[$key] !== NULL) {
          $lines[] = self::line($query[$key]);
        }
      }
    }
    return $lines;
  }

  /**
   * Writes a single or page Template as its bundle's full content template.
   *
   * @return string[]
   *   Created output lines.
   */
  private function writeFullTemplate(string $bundle, string $route, ResolvedTemplate $template, ContentMapping $mapping, string $theme): array {
    if ($template->loops === []) {
      return [self::line($this->writer->writeContentTemplate($bundle, 'full', $template->nodes, $mapping, $template->partSet->id($theme)))];
    }
    ['template' => $written, 'queries' => $queries] = $this->writer->writeQueryContentTemplate($bundle, $route, $template, $mapping, $theme);
    return [self::line($written), ...self::queryLines($queries)];
  }

  /**
   * Deletes a failed Template write's planned outputs from the manifest.
   *
   * Mirrors the Pattern catch's shape above: candidates cover only the
   * entity types intendedOutputs() reserved for this Template before
   * writeFullTemplate() failed, so abandon() can strip them through
   * reconcile(). A target that writes the bundle's full display as its own
   * config object, not a content_template entity, reserved nothing here for
   * abandon() to strip.
   */
  private function abandonTemplateOutputs(ConversionTransaction $tx, array $created, string $bundle, ResolvedTemplate $template, ContentMapping $mapping, string $theme): void {
    $candidates = array_fill_keys(ConversionOwnership::ENTITY_TYPES, []);
    if ($this->writer->fullDisplayConfigName($bundle) === NULL) {
      $candidates['content_template'][] = "node.$bundle.full";
    }
    foreach ($template->loops as $loop) {
      $this->addQueryOutputs($candidates, $theme, $loop, $mapping->target('post'), NULL);
    }
    $tx->abandon($candidates, $created);
  }

  /**
   * Computes every persistent output before the first Drupal write.
   *
   * Every tree reaching here is the tree its write uses, so each
   * content-hashed id matches the id that write saves.
   */
  private function intendedOutputs(Snapshot $snapshot, array $patterns, array $partSets, array $partTrees, ContentMapping $mapping, array $templates, array $frozenHtml, array $menus, string $target): array {
    $theme = $snapshot->themeSlug;
    $themeId = ThemeGenerator::themeId($theme);
    $entities = array_fill_keys(ConversionOwnership::ENTITY_TYPES, []);
    foreach ($patterns as $owner) {
      if ($owner->isFailed()) {
        continue;
      }
      $patternId = self::patternId($theme, $owner->metadata['slug']);
      $entities[$this->writer->patternEntityType()][] = $patternId;
      foreach ($owner->loops as $loop) {
        $this->addQueryOutputs($entities, $theme, $loop, $mapping->target('post'), NULL);
      }
      $this->addListingOutputs($entities, $owner->nodes, $mapping);
    }
    $routesByPartSet = self::routesByPartSet($templates, $theme);
    foreach ($partSets as $id => $partSet) {
      $entities[$this->writer->pageFrameEntityType()][] = $partSet->id($theme);
      foreach ($partTrees[$id] as $tree) {
        foreach ($tree['loops'] as $loop) {
          $this->addQueryOutputs($entities, $theme, $loop, $mapping->target('post'), $loop->route(self::listingRoute($partSet, $routesByPartSet, $theme)));
        }
      }
    }
    $fullDisplays = [];
    if (isset($templates['single']) && $mapping->target('post') !== NULL) {
      $this->addFullDisplayOutput($entities, $fullDisplays, $mapping->target('post'));
    }
    // A target that requires a page mapping (display_builder) writes the
    // page bundle's own full display for a static home or 404 route too
    // (writeStaticPage()'s override field), not only for a routed "page"
    // Template; requiresPageMapping() already made the earlier preflight
    // skip any static route the mapping does not bind, so target('page')
    // resolves whenever one of those routes reached this point.
    if (isset($templates['page'])
      || ($this->writer->requiresPageMapping() && array_intersect(TemplatePage::ROUTES, array_keys($templates)) !== [])) {
      $this->addFullDisplayOutput($entities, $fullDisplays, $mapping->target('page'));
    }
    foreach (self::runtimePresetBundles($mapping) as $bundle) {
      $entities[$this->writer->patternEntityType()] = [
        ...$entities[$this->writer->patternEntityType()],
        ...$this->writer->runtimePresetIds($theme, $bundle, $mapping),
      ];
    }
    $pages = [];
    foreach ($templates as $route => $template) {
      $listing = in_array($route, self::VIEW_ROUTES, TRUE);
      $term = in_array($route, self::TERM_ROUTES, TRUE);
      foreach ($template->loops as $loop) {
        $this->addQueryOutputs($entities, $theme, $loop, $mapping->target('post'), $loop->route($term ? 'archive' : ($listing ? $route : NULL)));
        // A search Query lists both the post and the page bundle's card on
        // the target that saves one, matching writeQueryContentTemplate()'s
        // own page card. A target with no separate card entity, like
        // Display Builder's page_card-free row, has nothing to reserve here.
        if ($loop->route($route) === 'search' && $mapping->binds('page') && $this->writer->queryCardEntityTypes() !== []) {
          $entities['content_template'][] = 'node.' . $mapping->target('page') . '.' . $this->writer->queryOutputIds($theme, $loop, $mapping->target('post'), $route)['view_mode'];
        }
      }
      $this->addListingOutputs($entities, $template->nodes, $mapping);
      if ($listing || $term) {
        $ids = $this->writer->templateOutputIds($theme, $route);
        $entities[$this->writer->patternEntityType()][] = $ids['pattern'];
      }
      if ($listing) {
        $entities['view'][] = $ids['view'];
      }
      if (in_array($route, TemplatePage::ROUTES, TRUE)) {
        $pages[$route] = TemplatePage::uuid($themeId, $route);
      }
    }
    $entities['menu'] = array_keys($menus);
    foreach ($entities as &$ids) {
      $ids = array_values(array_unique($ids));
      sort($ids);
    }
    unset($ids);
    $fullDisplays = array_values(array_unique($fullDisplays));
    sort($fullDisplays);
    return [
      'target' => $target,
      'page_entity_type' => $this->writer->pageEntityType(),
      'entities' => $entities,
      'config' => [ThemeSettings::configName($themeId)],
      'full_displays' => $fullDisplays,
      'files' => $this->themeGenerator->filePaths($snapshot, $frozenHtml),
      'pages' => $pages,
      'demo_content' => array_replace(array_fill_keys(DemoContentSeeder::ENTITY_TYPES, []), ['menu_link_content' => NavigationMenus::linkUuids($themeId, $menus)]),
      'recipe' => SiteRecipeWriter::name($themeId),
      'replaced_views' => [],
      'wordpress_version' => $snapshot->wordPressVersion,
      // drupal.org packaging writes the version key; a git checkout has none.
      'wordpal_version' => $this->moduleList->getExtensionInfo('wordpal')['version'] ?? NULL,
    ];
  }

  /**
   * Returns the mapped bundles runtime-component presets bind against.
   *
   * @return string[]
   *   The bundle machine names.
   */
  private static function runtimePresetBundles(ContentMapping $mapping): array {
    $bundles = [];
    foreach (['post', 'page'] as $concept) {
      if ($mapping->binds($concept)) {
        $bundles[(string) $mapping->target($concept)] = TRUE;
      }
    }
    return array_keys($bundles);
  }

  /**
   * Adds one bundle's full content template to the intended output set.
   *
   * A target that returns a config name from fullDisplayConfigName() writes
   * that bundle's full display directly and owns it through $fullDisplays
   * instead of the "content_template" entity type Canvas's own distinct
   * entity uses.
   */
  private function addFullDisplayOutput(array &$entities, array &$fullDisplays, string $bundle): void {
    $configName = $this->writer->fullDisplayConfigName($bundle);
    if ($configName === NULL) {
      $entities['content_template'][] = "node.$bundle.full";
      return;
    }
    $fullDisplays[] = $configName;
  }

  /**
   * Adds entities generated by one Query block to the intended output set.
   *
   * Two Query blocks with the same card, settings, and empty state get the
   * same ids, so array_unique() in intendedOutputs() lists their shared
   * outputs once.
   */
  private function addQueryOutputs(array &$entities, string $theme, QueryLoop $loop, string $bundle, ?string $route): void {
    foreach (self::queryOutputEntities($this->writer, $theme, $loop, $bundle, $route) as $entityType => $ids) {
      $entities[$entityType] = [...($entities[$entityType] ?? []), ...$ids];
    }
  }

  /**
   * Returns the entity ids one Query block's outputs save as, by entity type.
   */
  public static function queryOutputEntities(WriterInterface $writer, string $theme, QueryLoop $loop, string $bundle, ?string $route): array {
    $ids = $writer->queryOutputIds($theme, $loop, $bundle, $route);
    $entities = ['view' => [$ids['view']]];
    $cardTypes = $writer->queryCardEntityTypes();
    if ($cardTypes !== []) {
      $entities[$cardTypes['view_mode']][] = 'node.' . $ids['view_mode'];
      $entities[$cardTypes['content_template']][] = "node.$bundle." . $ids['view_mode'];
      $entities[$cardTypes['component']][] = $ids['component'];
    }
    if ($ids['empty_pattern'] !== NULL) {
      $entities[$writer->patternEntityType()][] = $ids['empty_pattern'];
    }
    return $entities;
  }

  /**
   * Adds entities generated by a tree's listing blocks to the output set.
   *
   * Two listing blocks with the same kind and settings get the same ids,
   * so array_unique() in intendedOutputs() lists their shared outputs once.
   * A target whose listingComponentEntityType() is NULL saves no entity for
   * "component" (a Drupal block plugin id, not one of this target's own
   * entities), the same gate addQueryOutputs() gives a Query's card through
   * queryCardEntityTypes().
   *
   * @param array $entities
   *   The manifest's entity lists, keyed by entity type.
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The tree.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets used to resolve each listing block's settings.
   */
  private function addListingOutputs(array &$entities, array $nodes, ContentMapping $mapping): void {
    $componentType = $this->writer->listingComponentEntityType();
    foreach ($this->writer->listingOutputs($nodes, $mapping) as $ids) {
      $entities['view'][] = $ids['view'];
      if ($componentType !== NULL) {
        $entities[$componentType][] = $ids['component'];
      }
    }
  }

  /**
   * Turns a pattern slug into a Canvas machine name derived from the theme.
   *
   * A theme's own pattern slug starts with the theme slug. A Remote pattern's
   * slug names another namespace, so its id takes the theme id as a prefix.
   *
   * Each disallowed character becomes its own underscore, never a collapsed
   * run: a theme can name two patterns "a---b" and "a-b", and collapsing
   * both to one underscore would give them the same id.
   */
  public static function patternId(string $theme, string $slug): string {
    $id = preg_replace('/[^a-z0-9_]/', '_', strtolower($slug));
    return str_starts_with($slug, "$theme/") ? $id : ThemeGenerator::themeId($theme) . '_' . $id;
  }

}
