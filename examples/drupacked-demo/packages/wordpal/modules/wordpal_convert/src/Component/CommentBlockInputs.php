<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Component;

use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\wordpal\Component\FrozenBlock;
use Drupal\wordpal\Plugin\Block\CommentsBlock;
use Drupal\wordpal_convert\Content\ContentMapping;
use Drupal\wordpal_convert\Support\FrozenHoles;
use Drupal\wordpal_convert\Theme\BlockNode;
use Drupal\wordpal_convert\Theme\TreeConcepts;

/**
 * Builds a comments block's settings and its rendered-fragment layout tree.
 *
 * `core/comments` and its standalone form resolve to one Drupal block
 * plugin, `wordpal_comments` (in the wordpal module), on every Builder
 * target. LayoutRenderer renders its `layout` setting directly through
 * Drupal core's own Component render element, so a `component` entry here
 * holds the same plain prop values regardless of which target wrote it:
 * unlike the rest of a writer's own component tree, this one needs no
 * target-specific storage shape.
 */
final class CommentBlockInputs {

  /**
   * The comments block and its standalone form.
   *
   * A core/post-comments-form outside a core/comments wrapper still needs
   * the comments block plugin's own access-checked lazy form rendering.
   */
  public const BLOCK_NAMES = [
    'core/comments', 'core/post-comments-form',
  ];

  /**
   * The module the comments layout's components come from.
   */
  private const PROVIDER = 'wordpal';

  public function __construct(
    private readonly AttributeFlattener $flattener,
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly ComponentInputs $componentInputs,
  ) {}

  /**
   * Returns the known settings accepted by each comment fragment.
   */
  public static function settingKeys(): array {
    // core/comment-author-name, core/comment-date, core/comment-edit-link and
    // core/comment-reply-link declare these typography and color supports in
    // their own block.json.
    $typography = [
      'font_size', 'font_family', 'font_style', 'font_weight', 'text_transform',
      'text_decoration', 'letter_spacing', 'line_height',
      'text_color', 'background_color', 'gradient', 'link_color',
    ];
    return [
      'core/comments-title' => ['level', 'show_post_title', ...CommentsBlock::TITLE_PROPS],
      'core/comment-template' => [],
      'core/avatar' => ['size', 'is_link', 'link_target'],
      'core/comment-author-name' => [...['is_link', 'link_target', 'text_align'], ...$typography],
      'core/comment-date' => [...['format', 'is_link'], ...$typography],
      'core/comment-content' => CommentsBlock::CONTENT_PROPS,
      'core/comment-edit-link' => [...['label', 'text_align'], ...$typography],
      'core/comment-reply-link' => [...['label', 'text_align'], ...$typography],
      'core/comments-pagination' => ['layout_type', 'justify_content', 'orientation', 'flex_wrap', 'block_gap'],
      'core/comments-pagination-previous' => ['label'],
      'core/comments-pagination-next' => ['label'],
      'core/comments-pagination-numbers' => [],
      'core/post-comments-form' => ['padding_top', 'padding_bottom'],
    ];
  }

  /**
   * Maps the fixed WordPress comment tree to one aggregate block's settings.
   *
   * The caller resolves `layout` itself, from the returned children, with
   * layout().
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode $node
   *   The comments root: core/comments or core/post-comments-form.
   * @param array $defaultSettings
   *   The wordpal_comments plugin's own default settings, ready to override.
   * @param \Drupal\wordpal_convert\Content\ContentMapping $mapping
   *   Content targets; the comments target names the bound field.
   *
   * @return array{settings: array, children: \Drupal\wordpal_convert\Theme\BlockNode[]}
   *   `settings` holds every key except `layout`; `children` is the comment
   *   tree layout() turns into that setting.
   */
  public function values(BlockNode $node, array $defaultSettings, ContentMapping $mapping): array {
    $settings = $defaultSettings;
    unset($settings['layout']);
    $settings['field_name'] = $mapping->target('comments');
    $children = match (TRUE) {
      // WordPress's parser loads a core/post-comments save as core/comments
      // with `legacy` set, which comments.php renders as the classic tree.
      !empty($node->attributes['legacy']) => $this->legacyCommentsTree(),
      // A standalone form has no comments wrapper of its own; its layout
      // holds only its own marker.
      $node->name === 'core/post-comments-form' => [$node],
      default => $node->children,
    };
    $rootValues = $this->flattener->flatten($node->attributes, $node->name);
    // A standalone form's supports go to post_comments_form below.
    $rootProps = $node->name === 'core/post-comments-form' ? ['css_class'] : CommentsBlock::ROOT_PROPS;
    foreach ($rootProps as $name) {
      if (array_key_exists($name, $rootValues)) {
        $settings[$name] = $rootValues[$name];
      }
    }
    foreach ($this->settingKeys() as $blockName => $allowed) {
      $child = $this->firstNode($children, $blockName);
      if ($child === NULL) {
        continue;
      }
      $key = str_replace('-', '_', substr($blockName, strlen('core/')));
      $values = $this->flattener->flatten($child->attributes, $child->name);
      foreach ($allowed as $name) {
        if (array_key_exists($name, $values)) {
          $settings[$key][$name] = $values[$name];
        }
      }
    }
    return ['settings' => $settings, 'children' => $children];
  }

  /**
   * Serializes static comment wrappers and dynamic fragment markers.
   *
   * A comment fragment (comments-title, comment-template, and the rest of
   * settingKeys()) becomes a bare marker: CommentRuntime supplies its
   * rendered content by matching the marker's own slug. Anything else is a
   * WordPal SDC component reached inside the layout, or a Frozen block when
   * none exists.
   *
   * @param \Drupal\wordpal_convert\Theme\BlockNode[] $nodes
   *   The comments tree, or one level of it.
   *
   * @throws \UnexpectedValueException
   *   When a node's attributes hold a value no prop or the Frozen block
   *   accepts: a comments layout fails loud rather than silently dropping
   *   part of a rendered comment.
   */
  public function layout(array $nodes): array {
    $tree = [];
    foreach ($nodes as $node) {
      if (array_key_exists($node->name, $this->settingKeys())) {
        $tree[] = [
          'marker' => $node->slug(),
          'component' => NULL,
          'props' => NULL,
          'children' => $this->layout($node->children),
        ];
        continue;
      }
      $componentId = self::PROVIDER . ':' . $node->slug();
      $skipped = [];
      if (!$this->componentPluginManager->hasDefinition($componentId)) {
        $props = $this->frozenProps($node, $skipped);
        if ($skipped !== []) {
          throw new \UnexpectedValueException('Comment layout uses unsupported values: ' . implode(', ', $skipped));
        }
        $tree[] = [
          'marker' => NULL,
          'component' => self::PROVIDER . ':' . FrozenBlock::SLUG,
          'props' => $props,
          'children' => [],
        ];
        continue;
      }
      $tree[] = [
        'marker' => NULL,
        'component' => $componentId,
        'props' => $this->componentInputs->forNode($node, $skipped),
        'children' => $this->layout(TreeConcepts::childNodes($node)),
      ];
      if ($skipped !== []) {
        throw new \UnexpectedValueException('Comment layout uses unsupported values: ' . implode(', ', $skipped));
      }
    }
    return $tree;
  }

  /**
   * Returns a Frozen block's plain props for one comment-layout node.
   */
  private function frozenProps(BlockNode $node, array &$skipped): ?array {
    $file = FrozenHoles::file($node);
    if (is_string($file)) {
      $skipped[] = "$node->name: $file";
      return NULL;
    }
    if ($file['queries'] !== []) {
      $skipped[] = "$node->name: it holds a Query, which a comments layout cannot place";
      return NULL;
    }
    return ['block_name' => $node->name, 'key' => FrozenBlock::key($node->rendered)];
  }

  /**
   * Returns the comments layout a deprecated core/post-comments block shows.
   *
   * The block holds no inner blocks: WordPress renders the post's comments
   * with its default comment list and form.
   */
  private function legacyCommentsTree(): array {
    $node = static fn (string $name, array $children = []): BlockNode => new BlockNode("core/$name", [], '', $children);
    return [
      $node('comments-title'),
      $node('comment-template', [
        $node('avatar'),
        $node('comment-author-name'),
        $node('comment-date'),
        $node('comment-content'),
        $node('comment-reply-link'),
      ]),
      $node('comments-pagination', [
        $node('comments-pagination-previous'),
        $node('comments-pagination-next'),
      ]),
      $node('post-comments-form'),
    ];
  }

  /**
   * Finds the first block with a name in a fixed comment tree.
   */
  private function firstNode(array $nodes, string $name): ?BlockNode {
    foreach ($nodes as $node) {
      if ($node->name === $name) {
        return $node;
      }
      $child = $this->firstNode($node->children, $name);
      if ($child !== NULL) {
        return $child;
      }
    }
    return NULL;
  }

}
