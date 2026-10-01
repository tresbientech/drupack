<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\WordPress;

/**
 * A resolved theme or plugin that Playground installs and activates.
 */
final readonly class Source {

  /**
   * The shape of a theme or plugin slug.
   *
   * The slug names the snapshot directory, the generated theme and the
   * directory Playground installs into.
   */
  public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

  /**
   * Constructs a source.
   *
   * @param \Drupal\wordpal_convert\WordPress\SourceType $type
   *   Whether this is a theme or a plugin.
   * @param string $slug
   *   The directory WordPress installs it into.
   * @param string $requires
   *   The "Requires at least" WordPress version, or '' when none is stated.
   * @param string|null $zip
   *   The local zip to install, or NULL to install the slug from
   *   WordPress.org.
   * @param string|null $sha256
   *   The SHA-256 hash of the zip, or NULL for a slug.
   * @param string $license
   *   A theme zip's License header, or '' when none is stated. '' for a
   *   slug or a plugin.
   * @param string $licenseUri
   *   A theme zip's License URI header, or '' as for $license.
   */
  public function __construct(
    public SourceType $type,
    public string $slug,
    public string $requires,
    public ?string $zip,
    public ?string $sha256,
    public string $license,
    public string $licenseUri,
  ) {}

  /**
   * Returns the key a snapshot records a source under, such as "plugin:acme".
   */
  public static function key(SourceType $type, string $slug): string {
    return $type->value . ':' . $slug;
  }

  /**
   * Returns the file name Playground reads a zip source from.
   */
  public function zipName(): string {
    return $this->type->value . '-' . $this->slug . '.zip';
  }

  /**
   * Returns the blueprint step installing and activating this source.
   *
   * @param string $zipDirectory
   *   The Playground directory holding zipName(), for a zip source.
   */
  public function installStep(string $zipDirectory): array {
    $resource = $this->zip === NULL
      ? ['resource' => 'wordpress.org/' . $this->type->value . 's', 'slug' => $this->slug]
      : ['resource' => 'vfs', 'path' => $zipDirectory . '/' . $this->zipName()];
    return [
      'step' => 'install' . $this->type->label(),
      $this->type->value . 'Data' => $resource,
      'options' => ['activate' => TRUE],
    ];
  }

}
