<?php

declare(strict_types=1);

namespace Drupack\Support;

use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

/**
 * Loads Twig templates stored as public files, named by their address.
 *
 * A module such as Site Studio compiles templates into public files and names
 * them relative to the Drupal root, as "sites/default/files/...". Public files
 * live in the files directory the start names, outside the application, so
 * this loader resolves those names there. Twig's own name validation keeps a
 * name inside that directory.
 */
final class SiteDataTemplateLoader extends FilesystemLoader {

  /**
   * The address public files carry, as SiteDataPublicStream serves them.
   */
  private const ADDRESS = 'sites/default/files/';

  public function __construct() {
    parent::__construct([getenv('DRUPACK_RUNTIME_FILES_DIR')]);
  }

  /**
   * {@inheritdoc}
   *
   * The parent answers from its cache first, which holds names without the
   * address, so every lookup goes through findTemplate() here.
   */
  public function exists(string $name) {
    return $this->findTemplate($name, FALSE) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function findTemplate(string $name, bool $throw = TRUE) {
    if (!str_starts_with($name, self::ADDRESS)) {
      if (!$throw) {
        return NULL;
      }
      throw new LoaderError(sprintf('Template "%s" is not a public file.', $name));
    }
    return parent::findTemplate(substr($name, strlen(self::ADDRESS)), $throw);
  }

}
