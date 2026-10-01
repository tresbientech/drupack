<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\WordPress;

/**
 * Checks a theme's stated license against the GPL-compatible allowlist.
 *
 * The allowlist holds the licenses a GPL-2.0-or-later Drupal site can
 * combine with. WordPress.org accepts GPL-compatible themes only, so a slug
 * theme passes on provenance. The same allowlist labels the Plugin license
 * line, which never refuses a plugin.
 */
final class ThemeLicense {

  /**
   * Known License header phrasings, normalized, and their SPDX ids.
   */
  private const PHRASINGS = [
    'gnu general public license v2 or later' => 'GPL-2.0-or-later',
    'gnu general public license v2.0 or later' => 'GPL-2.0-or-later',
    'gnu general public license version 2 or later' => 'GPL-2.0-or-later',
    'gnu general public license, version 2 or later' => 'GPL-2.0-or-later',
    'gnu gpl v2 or later' => 'GPL-2.0-or-later',
    'gnu gplv2 or later' => 'GPL-2.0-or-later',
    'gplv2 or later' => 'GPL-2.0-or-later',
    'gpl v2 or later' => 'GPL-2.0-or-later',
    'gpl 2.0 or later' => 'GPL-2.0-or-later',
    'gpl-2.0 or later' => 'GPL-2.0-or-later',
    'gpl-2.0+' => 'GPL-2.0-or-later',
    'gplv2+' => 'GPL-2.0-or-later',
    'gpl-2.0-or-later' => 'GPL-2.0-or-later',
    'gnu general public license v2' => 'GPL-2.0-only',
    'gnu general public license v2.0' => 'GPL-2.0-only',
    'gnu general public license version 2' => 'GPL-2.0-only',
    'gplv2' => 'GPL-2.0-only',
    'gpl v2' => 'GPL-2.0-only',
    'gpl-2.0' => 'GPL-2.0-only',
    'gpl-2.0-only' => 'GPL-2.0-only',
    'gnu general public license v3 or later' => 'GPL-3.0-or-later',
    'gnu general public license v3.0 or later' => 'GPL-3.0-or-later',
    'gnu general public license version 3 or later' => 'GPL-3.0-or-later',
    'gnu general public license, version 3 or later' => 'GPL-3.0-or-later',
    'gplv3 or later' => 'GPL-3.0-or-later',
    'gpl v3 or later' => 'GPL-3.0-or-later',
    'gpl-3.0 or later' => 'GPL-3.0-or-later',
    'gpl-3.0+' => 'GPL-3.0-or-later',
    'gplv3+' => 'GPL-3.0-or-later',
    'gpl-3.0-or-later' => 'GPL-3.0-or-later',
    'gnu general public license v3' => 'GPL-3.0-only',
    'gnu general public license v3.0' => 'GPL-3.0-only',
    'gnu general public license version 3' => 'GPL-3.0-only',
    'gplv3' => 'GPL-3.0-only',
    'gpl v3' => 'GPL-3.0-only',
    'gpl-3.0' => 'GPL-3.0-only',
    'gpl-3.0-only' => 'GPL-3.0-only',
    'lgpl-2.1' => 'LGPL-2.1',
    'lgplv2.1' => 'LGPL-2.1',
    'lgpl-2.1-only' => 'LGPL-2.1',
    'lgpl-2.1-or-later' => 'LGPL-2.1',
    'lgpl-3.0' => 'LGPL-3.0',
    'lgplv3' => 'LGPL-3.0',
    'lgpl-3.0-only' => 'LGPL-3.0',
    'lgpl-3.0-or-later' => 'LGPL-3.0',
    'mit' => 'MIT',
    'mit license' => 'MIT',
    'bsd-2-clause' => 'BSD-2-Clause',
    'bsd-3-clause' => 'BSD-3-Clause',
    'apache-2.0' => 'Apache-2.0',
    'apache license 2.0' => 'Apache-2.0',
    'apache license, version 2.0' => 'Apache-2.0',
    'mpl-2.0' => 'MPL-2.0',
    'mozilla public license 2.0' => 'MPL-2.0',
    'isc' => 'ISC',
    'isc license' => 'ISC',
  ];

  /**
   * Known License URI values, without scheme, "www." or trailing slash.
   *
   * A GNU URI names a version but not "or later", so it maps to the "only"
   * id.
   */
  private const URIS = [
    'gnu.org/licenses/gpl-2.0.html' => 'GPL-2.0-only',
    'gnu.org/licenses/gpl-2.0.txt' => 'GPL-2.0-only',
    'gnu.org/licenses/old-licenses/gpl-2.0.html' => 'GPL-2.0-only',
    'gnu.org/licenses/old-licenses/gpl-2.0.txt' => 'GPL-2.0-only',
    'gnu.org/licenses/gpl-3.0.html' => 'GPL-3.0-only',
    'gnu.org/licenses/gpl-3.0.txt' => 'GPL-3.0-only',
    'gnu.org/licenses/gpl.html' => 'GPL-3.0-only',
    'gnu.org/licenses/old-licenses/lgpl-2.1.html' => 'LGPL-2.1',
    'gnu.org/licenses/lgpl-3.0.html' => 'LGPL-3.0',
    'opensource.org/licenses/gpl-2.0' => 'GPL-2.0-only',
    'opensource.org/licenses/gpl-2.0.php' => 'GPL-2.0-only',
    'opensource.org/licenses/gpl-3.0' => 'GPL-3.0-only',
    'opensource.org/licenses/mit' => 'MIT',
    'opensource.org/licenses/mit-license.php' => 'MIT',
    'opensource.org/licenses/bsd-2-clause' => 'BSD-2-Clause',
    'opensource.org/licenses/bsd-3-clause' => 'BSD-3-Clause',
    'opensource.org/licenses/isc' => 'ISC',
    'apache.org/licenses/license-2.0' => 'Apache-2.0',
    'apache.org/licenses/license-2.0.html' => 'Apache-2.0',
    'apache.org/licenses/license-2.0.txt' => 'Apache-2.0',
    'mozilla.org/mpl/2.0' => 'MPL-2.0',
  ];

  /**
   * Returns the SPDX id of an allowed license, or NULL.
   *
   * @param string $license
   *   The License header, '' when not stated.
   * @param string $licenseUri
   *   The License URI header, '' when not stated.
   */
  public static function spdx(string $license, string $licenseUri): ?string {
    $phrasing = strtolower(trim((string) preg_replace('/\s+/', ' ', $license), " \t."));
    $uri = strtolower(rtrim((string) preg_replace('#^(?:https?://)?(?:www\.)?#i', '', trim($licenseUri)), '/'));
    return self::PHRASINGS[$phrasing] ?? self::URIS[$uri] ?? NULL;
  }

  /**
   * Checks a theme's license and returns the line wordpal:convert prints.
   *
   * @param bool $fromWordPressOrg
   *   Whether the theme was installed from its WordPress.org slug.
   * @param string $license
   *   The License header, '' when not stated.
   * @param string $licenseUri
   *   The License URI header, '' when not stated.
   * @param bool $accepted
   *   Whether the site builder passed --accept-license.
   *
   * @throws \RuntimeException
   *   When a zip or URL theme's license is not allowed and not accepted.
   */
  public static function check(bool $fromWordPressOrg, string $license, string $licenseUri, bool $accepted): string {
    $stated = self::printable($license !== '' ? $license : $licenseUri);
    if ($fromWordPressOrg) {
      return sprintf('License: %s (WordPress.org)', $stated === '' ? 'not stated' : $stated);
    }
    $spdx = self::spdx($license, $licenseUri);
    if ($spdx !== NULL) {
      return "License: $spdx (style.css)";
    }
    if ($accepted) {
      return $stated === '' ? 'License: none stated, accepted with --accept-license' : "License: \"$stated\" accepted with --accept-license";
    }
    throw new \RuntimeException(sprintf(
      '%s, which is not on the GPL-compatible list. A conversion copies the theme\'s files into the Drupal site. Pass --accept-license if you hold the rights to do so.',
      $stated === '' ? 'The theme states no license in style.css' : "The theme states the license \"$stated\" in style.css",
    ));
  }

  /**
   * Returns the line wordpal:convert prints for a plugin whose files ship.
   *
   * A plugin's license is reported and never refused.
   *
   * @param string $plugin
   *   The plugin directory.
   * @param array{version: string, license: string, license_uri: string} $headers
   *   The plugin headers, '' when not stated.
   * @param bool $fromWordPressOrg
   *   Whether WordPress installed the plugin from its WordPress.org slug.
   */
  public static function pluginLine(string $plugin, array $headers, bool $fromWordPressOrg): string {
    $name = trim($plugin . ' ' . self::printable($headers['version']));
    $stated = self::printable($headers['license'] !== '' ? $headers['license'] : $headers['license_uri']);
    if ($fromWordPressOrg) {
      return sprintf('%s: %s (WordPress.org)', $name, $stated === '' ? 'not stated' : $stated);
    }
    $spdx = self::spdx($headers['license'], $headers['license_uri']);
    if ($spdx !== NULL) {
      return "$name: $spdx (plugin header)";
    }
    return $stated === '' ? "$name: not stated" : "$name: \"$stated\", not on the GPL-compatible list";
  }

  /**
   * Removes control characters from a header a zip supplied.
   *
   * A header value from an untrusted zip reaches the terminal, where an
   * escape sequence would act on it.
   */
  private static function printable(string $value): string {
    return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);
  }

}
