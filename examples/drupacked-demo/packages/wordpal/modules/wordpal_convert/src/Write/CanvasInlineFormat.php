<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Write;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Lets Canvas's inline text format keep struck-through text and spans.
 *
 * WordPress rich text marks struck text with `<s>`, as a Platform credit
 * does, and themes wrap separators in a classed `<span>`. Canvas's
 * `canvas_html_inline` format drops both at render, and its CKEditor 5
 * editor drops them on edit, until both allow them.
 */
final class CanvasInlineFormat {

  /**
   * The text format Canvas gives inline rich text props.
   */
  private const FORMAT = 'canvas_html_inline';

  /**
   * The filter that holds the format's allowed HTML.
   */
  private const FILTER = 'filter_html';

  /**
   * The classed span tag, which Source Editing keeps.
   */
  private const SPAN = '<span class>';

  /**
   * The tags the format gains.
   */
  private const TAGS = ['<s>', self::SPAN];

  /**
   * The CKEditor 5 toolbar items the editor gains.
   *
   * Strikethrough writes `<s>`. Source Editing keeps the tags in
   * SOURCE_TAGS, which no toolbar item writes.
   */
  private const TOOLBAR_ITEMS = ['strikethrough', 'sourceEditing'];

  /**
   * The tags the Source Editing plugin keeps.
   */
  private const SOURCE_TAGS = [self::SPAN];

  /**
   * Adds the tags to the format and the matching plugins to the editor.
   */
  public static function apply(ConversionTransaction $tx, ConfigFactoryInterface $configFactory): void {
    $format = $configFactory->getEditable(self::formatName());
    $key = 'filters.' . self::FILTER . '.settings.allowed_html';
    $allowed = $format->get($key);
    $missing = array_filter(self::TAGS, static fn (string $tag): bool => !str_contains($allowed, $tag));
    if ($missing !== []) {
      $tx->setConfig($format, $key, implode(' ', [$allowed, ...$missing]));
    }
    $editor = $configFactory->getEditable(self::editorName());
    $settings = self::editorSettings($editor->get('settings'));
    if ($settings !== $editor->get('settings')) {
      $tx->setConfig($editor, 'settings', $settings);
    }
  }

  /**
   * Returns the editor settings with the toolbar items and source tags.
   */
  private static function editorSettings(array $settings): array {
    $items = $settings['toolbar']['items'];
    $settings['toolbar']['items'] = [...$items, ...array_values(array_diff(self::TOOLBAR_ITEMS, $items))];
    $tags = $settings['plugins']['ckeditor5_sourceEditing']['allowed_tags'] ?? [];
    $missing = array_values(array_diff(self::SOURCE_TAGS, $tags));
    $settings['plugins']['ckeditor5_sourceEditing']['allowed_tags'] = [...$tags, ...$missing];
    return $settings;
  }

  /**
   * Returns the Site recipe config actions that repeat apply().
   */
  public static function recipeActions(ConfigFactoryInterface $configFactory): array {
    return [
      self::editorName() => [
        'set' => [
          'property_name' => 'settings',
          'value' => $configFactory->get(self::editorName())->get('settings'),
        ],
      ],
      self::formatName() => [
        'setFilterConfig' => [
          'instance_id' => self::FILTER,
          'configuration' => $configFactory->get(self::formatName())->get('filters.' . self::FILTER),
        ],
      ],
    ];
  }

  /**
   * Returns the format's config name.
   */
  private static function formatName(): string {
    return 'filter.format.' . self::FORMAT;
  }

  /**
   * Returns the format's editor config name.
   */
  private static function editorName(): string {
    return 'editor.editor.' . self::FORMAT;
  }

}
