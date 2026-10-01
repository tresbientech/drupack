<?php

declare(strict_types=1);

namespace Drupal\wordpal\Support;

/**
 * Reads the values WordPress stores for theme presets.
 *
 * A block stores a preset either as a slug on its own attribute, such as
 * `"fontSize":"x-large"`, or as a reference inside its style attribute, such
 * as `"var:preset|spacing|50"`.
 */
final class PresetValue {

  /**
   * The two-character sequence that opens a CSS comment.
   */
  private const COMMENT_OPEN = '/*';

  /**
   * The shape of a value WordPress writes into a block's style attribute.
   *
   * Lengths, percentages, bare numbers, colors (#hex and the comma-separated
   * arguments of rgb()/hsl()), keywords, and calc()/clamp()/min()/max()
   * expressions all stay inside this character set: WordPress never puts a
   * value here that needs '<', '>', '{', '}', ';', a backslash or a quote,
   * and those are exactly the characters that let a value break out of the
   * inline style attribute or the layout <style> element.
   */
  private const SAFE_VALUE = '/^[A-Za-z0-9#%.,+*\/() _-]+$/D';

  /**
   * The CSS functions a stored value may call.
   *
   * WordPress writes colors, math and gradients into style attributes. Any
   * other function, such as url() or image-set(), loads a resource.
   */
  private const FUNCTIONS = [
    'var', 'calc', 'clamp', 'min', 'max', 'rgb', 'rgba', 'hsl', 'hsla',
    'linear-gradient', 'radial-gradient', 'conic-gradient',
    'repeating-linear-gradient', 'repeating-radial-gradient',
    'repeating-conic-gradient',
  ];

  /**
   * The path inside a `var:preset|…` or `var:custom|…` reference.
   *
   * A `custom` path names a theme.json `settings.custom` property, which
   * WordPress prints as `--wp--custom--<path>`. WordPress kebab-cases those
   * names, so a segment holds lowercase letters, digits and inner hyphens.
   */
  private const PRESET_PATH = '/^(?:preset(\|[a-z0-9]+(?:-[a-z0-9]+)*){2}|custom(\|[a-z0-9]+(?:-[a-z0-9]+)*)+)$/D';

  /**
   * Turns a stored value into CSS.
   *
   * A preset or custom reference becomes a custom property, and anything
   * else stays as the author wrote it. Both are validated first: these
   * values come from page editors, and reach an inline style attribute or a
   * page-level <style> element, so an unrecognized value is a loud failure
   * rather than markup or CSS the author never wrote.
   */
  public static function css(string $value): string {
    if (str_starts_with($value, 'var:')) {
      $path = substr($value, strlen('var:'));
      $slugAt = strrpos($path, '|') + 1;
      if (str_starts_with($path, 'preset|') && preg_match('/^[A-Za-z0-9-]+$/D', substr($path, $slugAt))) {
        $path = substr($path, 0, $slugAt) . self::slug(substr($path, $slugAt));
      }
      if (!preg_match(self::PRESET_PATH, $path)) {
        throw new \UnexpectedValueException("Not a WordPress preset reference: \"$value\".");
      }
      return 'var(--wp--' . str_replace('|', '--', $path) . ')';
    }
    if ($value === '' || str_contains($value, self::COMMENT_OPEN) || !preg_match(self::SAFE_VALUE, $value)) {
      throw new \UnexpectedValueException("Not a valid CSS value: \"$value\".");
    }
    preg_match_all('/([A-Za-z0-9_-]*)\s*\(/', $value, $calls);
    foreach ($calls[1] as $function) {
      // A bare parenthesis groups terms inside calc() and its siblings.
      if ($function !== '' && !in_array(strtolower($function), self::FUNCTIONS, TRUE)) {
        throw new \UnexpectedValueException("Not an allowed CSS function in \"$value\".");
      }
    }
    return $value;
  }

  /**
   * Turns a stored block gap into the CSS WordPress's layout support prints.
   *
   * The layout support resolves only a `var:preset|spacing|` reference, and
   * prints any other reference as written (block-supports/layout.php), which
   * the browser then drops as an invalid declaration.
   */
  public static function layoutGap(string $value): string {
    if (str_starts_with($value, 'var:') && !str_starts_with($value, 'var:preset|spacing|')) {
      self::css($value);
      return $value;
    }
    return self::css($value);
  }

  /**
   * Returns TRUE when the value is a `var:custom|…` reference.
   *
   * WordPress's style engine, which builds the wrapper of a dynamic block,
   * resolves only `var:preset|` references and prints no declaration for a
   * custom one (WP_Style_Engine::get_css_declarations()).
   */
  public static function isCustomReference(string $value): bool {
    return str_starts_with($value, 'var:custom|');
  }

  /**
   * Returns the slug of a duotone preset reference, or NULL for another value.
   *
   * WP_Duotone::is_preset() accepts both reference forms.
   */
  public static function duotoneSlug(string $value): ?string {
    if (preg_match('/^(?:var:preset\|duotone\||var\(--wp--preset--duotone--)([a-z0-9-]+)\)?$/iD', $value, $matches)) {
      return $matches[1];
    }
    return NULL;
  }

  /**
   * Returns TRUE when the value is a preset slug rather than a CSS value.
   *
   * A slug holds letters, digits and dashes, and starts with a letter. A CSS
   * value starts with a digit, a hash or a function, such as `2rem`, `#fff`,
   * `var(...)` or `rgb(...)`.
   */
  public static function isSlug(string $value): bool {
    return (bool) preg_match('/^[a-z][a-z0-9-]*$/iD', $value);
  }

  /**
   * Returns a preset slug as WordPress prints it in classes and properties.
   *
   * Ports _wp_to_kebab_case(), which the style engine applies to every
   * stored slug: `Background` becomes `background`, `contrast2` becomes
   * `contrast-2` and `heavenlyBlue` becomes `heavenly-blue`.
   */
  public static function slug(string $value): string {
    $break = '[\x00-\x2f\x3a-\x40\x5b-\x60\x7b-\xbf\x{2000}-\x{206f} \t\x0b\f\xa0\x{feff}\n\r\x{2028}\x{2029}\x{1680}\x{180e}\x{2000}-\x{200a}\x{202f}\x{205f}\x{3000}]';
    $lower = '[a-z\xdf-\xf6\xf8-\xff]';
    $upper = '[A-Z\xc0-\xd6\xd8-\xde]';
    $misc = '[^\x00-\x2f\x3a-\x40\x5b-\x60\x7b-\xbf\x{2000}-\x{206f} \t\x0b\f\xa0\x{feff}\n\r\x{2028}\x{2029}\x{1680}\x{180e}\x{2000}-\x{200a}\x{202f}\x{205f}\x{3000}\d+a-z\xdf-\xf6\xf8-\xffA-Z\xc0-\xd6\xd8-\xde]';
    $miscLower = "(?:$lower|$misc)";
    $miscUpper = "(?:$upper|$misc)";
    $words = implode('|', [
      "$upper?$lower+(?=$break|$upper|$)",
      "$miscUpper+(?=$break|$upper$miscLower|$)",
      "$upper?$miscLower+",
      "$upper+",
      '\d*(?:1ST|2ND|3RD|(?![123])\dTH)(?=\b|[a-z_])',
      '\d*(?:1st|2nd|3rd|(?![123])\dth)(?=\b|[A-Z_])',
      '\d+',
    ]);
    preg_match_all("/$words/u", str_replace("'", '', $value), $matches);
    return strtolower(implode('-', $matches[0]));
  }

}
