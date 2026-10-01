<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\wordpal\Plugin\Block\NavigationBlock;
use Drupal\wordpal_convert\Support\StableUuid;
use Drupal\wordpal_convert\Theme\BlockNode;

/**
 * Turns the inline links of Navigation blocks into Owned Drupal menus.
 *
 * WordPress renders a Navigation block's own link list when the block holds
 * one. A block with a `ref`, no inner blocks, or inner blocks other than
 * links renders the site's navigation instead, which the mapped menu holds.
 */
final class NavigationMenus {

  /**
   * The length of the menu_link_content title field.
   */
  private const TITLE_MAX_LENGTH = 255;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The inner blocks a Navigation block's own links are made of.
   */
  public const LINK_BLOCKS = ['core/navigation-link', 'core/navigation-submenu', 'core/home-link'];

  /**
   * The inner blocks that may follow a Navigation block's own links.
   */
  public const TRAILING_BLOCKS = ['core/social-links'];

  /**
   * Returns a Navigation block's own links, or NULL for the site navigation.
   *
   * A submenu's links follow it, each naming its parent's index. A block
   * holding any inner block other than links, optionally followed by
   * self::TRAILING_BLOCKS, renders the site navigation.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   A core/navigation block.
   * @param string[] $skipped
   *   Collects each link left out, with why.
   *
   * @return array<int, array{title: string, uri: string, parent: int|null, home: bool}>|null
   *   The links depth first, as menu link titles and link field URIs.
   */
  public static function links(BlockNode $node, array &$skipped = []): ?array {
    $own = self::ownChildren($node);
    if (isset($node->attributes['ref']) || $own === [] || !self::onlyLinks($own)) {
      return NULL;
    }
    $links = [];
    self::addLinks($own, NULL, $links, $skipped);
    return $links;
  }

  /**
   * Returns the blocks printed after a Navigation block's own links.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The trailing inner blocks, empty when the block has none.
   */
  public static function trailing(BlockNode $node): array {
    return array_slice($node->children, count(self::ownChildren($node)));
  }

  /**
   * Returns a Navigation block's inner blocks before its trailing blocks.
   *
   * @return \Drupal\wordpal_convert\Theme\BlockNode[]
   *   The inner blocks up to the trailing run at the end.
   */
  private static function ownChildren(BlockNode $node): array {
    $end = count($node->children);
    while ($end > 0 && in_array($node->children[$end - 1]->name, self::TRAILING_BLOCKS, TRUE)) {
      $end--;
    }
    return array_slice($node->children, 0, $end);
  }

  /**
   * Returns whether blocks and their submenus hold links only.
   */
  private static function onlyLinks(array $nodes): bool {
    foreach ($nodes as $node) {
      if (!in_array($node->name, self::LINK_BLOCKS, TRUE) || ($node->name === 'core/navigation-submenu' && !self::onlyLinks($node->children))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Adds links, and the links of each submenu after it.
   */
  private static function addLinks(array $nodes, ?int $parent, array &$links, array &$skipped): void {
    foreach ($nodes as $child) {
      $home = $child->name === 'core/home-link';
      // Link labels are WordPress rich text, and a menu link title is plain.
      $label = (string) ($child->attributes['label'] ?? '');
      $title = trim(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5)) ?: ($home ? 'Home' : '');
      $url = (string) ($child->attributes['url'] ?? '');
      $uri = match (TRUE) {
        $home => 'route:<front>',
        // WordPress prints a submenu without a URL as an anchor without href.
        $child->name === 'core/navigation-submenu' && $url === '' => 'route:<nolink>',
        default => self::uri($url),
      };
      $reason = match (TRUE) {
        $title === '' => 'it has no label',
        mb_strlen($title) > self::TITLE_MAX_LENGTH => 'its label is longer than ' . self::TITLE_MAX_LENGTH . ' characters',
        $uri === NULL => 'its URL is not a root-relative path, a fragment or an http(s) URL',
        default => NULL,
      };
      if ($reason !== NULL) {
        $skipped[] = sprintf('%s "%s" (%s): %s', $child->name, mb_strimwidth($label, 0, 60, '…'), $url, $reason);
        continue;
      }
      // The link field's attributes option, which Drupal's link rendering
      // also reads, holds what WordPress prints on the item and its <a>.
      $rel = $child->attributes['rel'] ?? (!empty($child->attributes['nofollow']) ? 'nofollow' : '');
      $attributes = array_filter([
        'class' => (string) ($child->attributes['className'] ?? ''),
        'target' => ($child->attributes['opensInNewTab'] ?? FALSE) === TRUE ? '_blank' : '',
        'rel' => (string) $rel,
        'title' => (string) ($child->attributes['title'] ?? ''),
      ], static fn (string $value): bool => $value !== '');
      $links[] = ['title' => $title, 'uri' => $uri, 'parent' => $parent, 'home' => $home, 'attributes' => $attributes];
      if ($child->name === 'core/navigation-submenu') {
        self::addLinks($child->children, array_key_last($links), $links, $skipped);
      }
    }
  }

  /**
   * Returns the id of the menu holding a block's own links.
   *
   * Blocks with the same links in one theme share one menu.
   *
   * @return string|null
   *   The menu id, or NULL for a block that renders the site navigation.
   */
  public static function menuId(string $themeId, BlockNode $node): ?string {
    $links = self::links($node);
    return $links === NULL ? NULL : 'wordpal-' . substr(hash('sha256', $themeId . "\n" . json_encode($links, JSON_THROW_ON_ERROR)), 0, 12);
  }

  /**
   * Finds the menus a conversion's trees need.
   *
   * @param array<string, \Drupal\wordpal_convert\Theme\BlockNode[]> $trees
   *   Trees keyed by their report label.
   * @param string $themeId
   *   The generated theme, which scopes the menu ids.
   * @param string $themeName
   *   The WordPress theme name, which starts each menu label.
   * @param bool $bindsNavigation
   *   Whether the Content mapping binds navigation to a menu.
   *
   * @return array{menus: array<string, array{label: string, links: array, trees: string[]}>, skipped: string[]}
   *   The menus keyed by id, with the labels of the trees using each, and
   *   one line per link left out or block whose inner blocks it drops.
   */
  public static function collect(array $trees, string $themeId, string $themeName, bool $bindsNavigation): array {
    $result = ['menus' => [], 'skipped' => []];
    foreach ($trees as $label => $nodes) {
      self::collectNodes((string) $label, $nodes, $themeId, $themeName, $bindsNavigation, $result);
    }
    ksort($result['menus']);
    $result['skipped'] = array_values(array_unique($result['skipped']));
    return $result;
  }

  /**
   * Adds the menus of one tree's Navigation blocks, depth first.
   */
  private static function collectNodes(string $tree, array $nodes, string $themeId, string $themeName, bool $bindsNavigation, array &$result): void {
    foreach ($nodes as $node) {
      if ($node->name === 'core/navigation') {
        $skipped = [];
        $links = self::links($node, $skipped);
        if ($links === NULL && !isset($node->attributes['ref']) && $node->children !== []) {
          $inner = array_values(array_unique(array_map(static fn (BlockNode $child): string => $child->name, $node->children)));
          $result['skipped'][] = "$tree: core/navigation holds " . implode(', ', $inner) . ($bindsNavigation
            ? ', so it renders the mapped menu instead'
            : ', and the Content mapping drops navigation, so the block is dropped');
        }
        if ($links !== NULL) {
          $id = self::menuId($themeId, $node);
          $name = $node->attributes['ariaLabel'] ?? implode(', ', array_column($links, 'title'));
          $result['menus'][$id] ??= ['label' => "$themeName: $name", 'links' => $links, 'trees' => []];
          $result['menus'][$id]['trees'] = array_values(array_unique([...$result['menus'][$id]['trees'], $tree]));
          foreach ($skipped as $line) {
            $result['skipped'][] = "$tree: $line";
          }
        }
      }
      self::collectNodes($tree, $node->children, $themeId, $themeName, $bindsNavigation, $result);
    }
  }

  /**
   * Returns the stable UUID of one menu link.
   */
  private static function linkUuid(string $themeId, string $menuId, int $index): string {
    return StableUuid::fromName("demo/$themeId/navigation-link/$menuId/$index");
  }

  /**
   * Returns the UUIDs of every link the menus hold.
   *
   * @return string[]
   *   The UUIDs, menu by menu, in link order.
   */
  public static function linkUuids(string $themeId, array $menus): array {
    $uuids = [];
    foreach ($menus as $id => $menu) {
      foreach (array_keys($menu['links']) as $index) {
        $uuids[] = self::linkUuid($themeId, $id, $index);
      }
    }
    return $uuids;
  }

  /**
   * Creates the menus and their links.
   *
   * @param string $themeId
   *   The converted theme.
   * @param array $menus
   *   The menus collect() returned.
   *
   * @return \Drupal\system\MenuInterface[]
   *   The created menus.
   */
  public function write(string $themeId, array $menus): array {
    $menuStorage = $this->entityTypeManager->getStorage('menu');
    $linkStorage = $this->entityTypeManager->getStorage('menu_link_content');
    $created = [];
    foreach ($menus as $id => $menu) {
      $entity = $menuStorage->create(['id' => $id, 'label' => $menu['label']]);
      ConfigValidation::assertValid($entity);
      $entity->save();
      $created[] = $entity;
      foreach ($menu['links'] as $index => $link) {
        $linkStorage->create([
          'uuid' => self::linkUuid($themeId, $id, $index),
          'menu_name' => $id,
          'title' => $link['title'],
          'link' => [
            'uri' => $link['uri'],
            'options' => ($link['home'] ? [NavigationBlock::HOME_LINK_OPTION => TRUE] : []) + ($link['attributes'] === [] ? [] : ['attributes' => $link['attributes']]),
          ],
          'parent' => $link['parent'] === NULL ? '' : 'menu_link_content:' . self::linkUuid($themeId, $id, $link['parent']),
          'enabled' => TRUE,
          'weight' => $index,
        ])->save();
      }
    }
    return $created;
  }

  /**
   * Turns a navigation link URL from the theme into a link field URI.
   *
   * @return string|null
   *   The URI, or NULL for a URL with another scheme or an invalid one.
   */
  private static function uri(string $url): ?string {
    // Theme markup is external input, and menu links render its URLs.
    if ($url === '' || preg_match('/[\x00-\x20\\\\]/', $url)) {
      return NULL;
    }
    if (str_starts_with($url, '#') || preg_match('#^/(?!/)#', $url)) {
      return UrlHelper::isValid($url) ? 'internal:' . $url : NULL;
    }
    if (preg_match('#^https?://#i', $url) && UrlHelper::isValid($url, TRUE)) {
      return $url;
    }
    return NULL;
  }

}
