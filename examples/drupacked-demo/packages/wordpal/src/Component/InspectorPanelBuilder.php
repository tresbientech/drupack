<?php

declare(strict_types=1);

namespace Drupal\wordpal\Component;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wordpal\Support\PresetShape;
use Drupal\wordpal\Support\PresetSupport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the WordPress inspector's Content, Settings and Styles tabs.
 *
 * Both form alters bucket a component's field renders by panel, then hand
 * the buckets here to lay out as WordPress does: one collapsible panel per
 * inspector panel, grouped under its tab, in inspector order. Both also hand
 * a preset prop here for its select of the default theme's tokens.
 */
final class InspectorPanelBuilder {

  use StringTranslationTrait;

  /**
   * The #weight a nested panel needs to sort after any of its tab's fields.
   *
   * A field a source plugin builds can carry a fractional #weight under 1
   * to preserve its declared order; a nested panel's own weight must clear
   * that range, since it can sit beside such a field directly in a tab
   * (Settings holds its own fields alongside the nested Advanced panel).
   */
  private const NESTED_PANEL_WEIGHT = 1000;

  /**
   * The value of the select option that keeps the paired field's own value.
   */
  private const CUSTOM = '__custom';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeExtensionList $themeList,
    #[Autowire(param: 'app.root')]
    private readonly string $appRoot,
  ) {}

  /**
   * Builds the tab elements from a component's fields, bucketed by panel.
   *
   * @param array<string, array<string, array>> $panels
   *   Field render arrays keyed by panel, then by field key.
   *
   * @return array<string, array>
   *   Tab elements keyed "wordpal_<tab>", each a details element wrapping
   *   its panels; a tab's own-named panel sits directly in the tab instead
   *   of a nested details. Empty when $panels holds no known panel.
   */
  public function tabs(array $panels): array {
    $tabs = [];
    foreach (InspectorPanel::TABS as $tab => $tabPanels) {
      $details = [];
      foreach ($tabPanels as $index => $panel) {
        if (!isset($panels[$panel])) {
          continue;
        }
        $details += $panel === $tab
          ? $panels[$panel]
          : ["wordpal_$panel" => $this->details($panel, FALSE, self::NESTED_PANEL_WEIGHT + $index) + $panels[$panel]];
      }
      if ($details !== []) {
        $tabs["wordpal_$tab"] = $this->details($tab, $tab === 'content') + $details;
      }
    }
    return $tabs;
  }

  /**
   * Builds the collapsible element of a tab or panel.
   */
  private function details(string $key, bool $open, ?int $weight = NULL): array {
    $details = ['#type' => 'details', '#title' => $this->title($key), '#open' => $open];
    return $weight === NULL ? $details : $details + ['#weight' => $weight];
  }

  /**
   * Builds a select of the theme's presets for a preset prop, or NULL.
   *
   * The select carries no name of its own: the caller's own field stays the
   * input that stores the prop's value, so a custom value already in it
   * stays there untouched. An inspector behavior pairs the two through
   * data-wordpal-preset(-for), copying the chosen preset into the field and
   * showing the field only for a custom value.
   *
   * @param string $name
   *   The prop name, paired to the field through a data attribute.
   * @param \Drupal\wordpal\Support\PresetSupport $preset
   *   The prop's preset group and stored shape.
   * @param array $tokens
   *   The default theme's tokens, keyed by group then slug.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   The paired field's own title, named in the select's aria-label.
   *
   * @return array|null
   *   The select render array, or NULL when the theme defines no preset of
   *   this prop's group.
   */
  public function presetSelect(string $name, PresetSupport $preset, array $tokens, TranslatableMarkup|string $title): ?array {
    if (!isset($tokens[$preset->group])) {
      return NULL;
    }
    $select = [
      '#type' => 'html_tag',
      '#tag' => 'select',
      '#attributes' => [
        'data-wordpal-preset-for' => $name,
        'data-wordpal-custom' => self::CUSTOM,
        'aria-label' => $this->t('@field preset', ['@field' => $title]),
      ],
      'default' => $this->option('', $this->t('Default')),
    ];
    foreach ($tokens[$preset->group] as $slug => $token) {
      $value = $preset->shape === PresetShape::Slug ? (string) $slug : "var:preset|{$preset->group}|$slug";
      // A theme's theme.json names the preset, and html_tag only filters
      // a string label.
      $select[] = $this->option($value, new FormattableMarkup('@label', ['@label' => $token['$description']]));
    }
    $select['custom'] = $this->option(self::CUSTOM, $this->t('Custom…'));
    return $select;
  }

  /**
   * Limits a select's options to the values the WordPress editor offers.
   *
   * @param array<string, mixed> $options
   *   The select's #options, keyed by value.
   * @param array $schema
   *   The prop's schema.
   * @param mixed $current
   *   The value the prop holds, which stays selectable so an edit never
   *   drops it.
   *
   * @return array<string, mixed>
   *   The empty options ("" and core's "_none"), the editor's values and
   *   the current value.
   */
  public function limitOptions(array $options, array $schema, mixed $current): array {
    if (!isset($schema[InspectorPanel::OPTIONS_KEY])) {
      return $options;
    }
    $kept = array_merge(['', '_none'], $schema[InspectorPanel::OPTIONS_KEY], [$current]);
    return array_filter(
      $options,
      static fn (string|int $value): bool => in_array((string) $value, $kept, TRUE),
      ARRAY_FILTER_USE_KEY,
    );
  }

  /**
   * Builds one option of a preset select.
   */
  private function option(string $value, MarkupInterface $label): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'option',
      '#attributes' => ['value' => $value],
      '#value' => $label,
    ];
  }

  /**
   * Reads the tokens file of the default theme, the theme the form renders.
   *
   * @return array
   *   Tokens keyed by group and slug, empty when the default theme is not a
   *   theme WordPal generated.
   */
  public function defaultThemeTokens(): array {
    $theme = $this->configFactory->get('system.theme')->get('default');
    $file = "$this->appRoot/{$this->themeList->getPath($theme)}/$theme.tokens.yml";
    return is_file($file) ? Yaml::decode((string) file_get_contents($file)) : [];
  }

  /**
   * Returns the label WordPress gives a tab or panel.
   */
  private function title(string $key): TranslatableMarkup {
    return match ($key) {
      'content' => $this->t('Content'),
      'settings' => $this->t('Settings'),
      'advanced' => $this->t('Advanced'),
      'styles' => $this->t('Styles'),
      'typography' => $this->t('Typography'),
      'color' => $this->t('Color'),
      'filter' => $this->t('Filters'),
      'layout' => $this->t('Layout'),
      'dimensions' => $this->t('Dimensions'),
      'border' => $this->t('Border & Shadow'),
    };
  }

}
