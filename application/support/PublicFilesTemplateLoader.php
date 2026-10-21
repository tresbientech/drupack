<?php

declare(strict_types=1);

namespace Drupack\Support;

use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

/**
 * Loads Twig templates stored as public files, named by their address. A module such as Site
 * Studio compiles templates into public files and names them relative to the Drupal root.
 * Those files live outside the application, in the files directory the start names, so this
 * loader resolves the names there. Twig's name validation keeps a name inside it.
 */
final class PublicFilesTemplateLoader extends FilesystemLoader {

  private const ADDRESS = 'sites/default/files/';

  public function __construct() {
    parent::__construct([getenv('DRUPACK_RUNTIME_FILES_DIR')]);
  }

  // The parent answers from its cache first, which holds names without the address.
  public function exists(string $name) {
    return $this->findTemplate($name, FALSE) !== NULL;
  }

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
