<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\WordPress;

use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves a theme or plugin reference into a source Playground installs.
 *
 * A reference is a WordPress.org slug, a local zip path or an https URL to a
 * zip. Everything read here comes from outside the site, so each value is
 * validated before it names a path or a blueprint step.
 */
final class ThemeSource {

  /**
   * How many bytes of a file WordPress reads for its headers.
   */
  private const HEADER_BYTES = 8192;

  public function __construct(
    #[Autowire(service: 'http_client')]
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Resolves one reference.
   *
   * @param string $reference
   *   A slug, a zip path or an https URL.
   * @param \Drupal\wordpal_convert\WordPress\SourceType $type
   *   Whether the reference names a theme or a plugin.
   * @param string $downloadDirectory
   *   Where a URL reference is downloaded to.
   *
   * @throws \RuntimeException
   *   When the reference cannot be read or holds no usable theme or plugin.
   */
  public function resolve(string $reference, SourceType $type, string $downloadDirectory): Source {
    if (str_contains($reference, '://')) {
      return $this->fromZip($this->download($reference, $downloadDirectory), $reference, $type);
    }
    if (str_ends_with(strtolower($reference), '.zip')) {
      if (!is_file($reference)) {
        throw new \RuntimeException("No zip file at $reference.");
      }
      return $this->fromZip($reference, $reference, $type);
    }
    return $this->fromSlug($reference, $type);
  }

  /**
   * Identifies a reference without the network, for a snapshot cache check.
   *
   * @param string $reference
   *   A slug, a zip path or an https URL.
   * @param \Drupal\wordpal_convert\WordPress\SourceType $type
   *   Whether the reference names a theme or a plugin.
   * @param string $downloadDirectory
   *   Where resolve() downloads a URL reference to.
   *
   * @return array{0: string, 1: ?string}|null
   *   The slug and the zip's SHA-256 hash, NULL as the hash for a slug. NULL
   *   for a URL resolve() has not downloaded.
   */
  public function identify(string $reference, SourceType $type, string $downloadDirectory): ?array {
    if (str_contains($reference, '://')) {
      $path = self::downloadPath($reference, $downloadDirectory);
      if (!is_file($path)) {
        return NULL;
      }
      $source = $this->fromZip($path, $reference, $type);
    }
    elseif (str_ends_with(strtolower($reference), '.zip')) {
      $source = $this->resolve($reference, $type, $downloadDirectory);
    }
    else {
      return [self::slug($reference, $type), NULL];
    }
    return [$source->slug, $source->sha256];
  }

  /**
   * Reads the required WordPress version of a WordPress.org slug.
   */
  private function fromSlug(string $slug, SourceType $type): Source {
    $label = $type->value;
    self::slug($slug, $type);
    try {
      $response = $this->httpClient->request('GET', $type->informationUrl($slug));
      $information = json_decode((string) $response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException("Could not read WordPress.org metadata for $label $slug.", 0, $exception);
    }
    if (!is_array($information) || !isset($information['name'])) {
      throw new \RuntimeException("WordPress.org has no $label named $slug.");
    }
    return new Source($type, $slug, self::version((string) ($information['requires'] ?? ''), $slug), NULL, NULL, '', '');
  }

  /**
   * Returns a slug the site builder typed, once it has a slug's shape.
   */
  private static function slug(string $slug, SourceType $type): string {
    if (!preg_match(Source::SLUG, $slug)) {
      throw new \RuntimeException("Invalid WordPress $type->value slug: $slug");
    }
    return $slug;
  }

  /**
   * Downloads an https URL into the download directory.
   */
  private function download(string $url, string $directory): string {
    if (!str_starts_with($url, 'https://')) {
      throw new \RuntimeException("Only https URLs are accepted: $url");
    }
    if (!is_dir($directory)) {
      mkdir($directory, 0777, TRUE);
    }
    $path = self::downloadPath($url, $directory);
    // identify() reads a file at $path as a finished download.
    $partial = "$path.part";
    try {
      $this->httpClient->request('GET', $url, [
        'sink' => $partial,
        'allow_redirects' => ['protocols' => ['https']],
      ]);
    }
    catch (\Throwable $exception) {
      // A failure before the first byte creates no file.
      if (is_file($partial)) {
        unlink($partial);
      }
      throw new \RuntimeException("Could not download $url.", 0, $exception);
    }
    rename($partial, $path);
    return $path;
  }

  /**
   * Returns where a URL is downloaded to.
   */
  private static function downloadPath(string $url, string $directory): string {
    return $directory . '/' . hash('sha256', $url) . '.zip';
  }

  /**
   * Reads the slug, required WordPress version and theme license from a zip.
   *
   * @param string $path
   *   The local zip file.
   * @param string $reference
   *   What the site builder named, for messages.
   * @param \Drupal\wordpal_convert\WordPress\SourceType $type
   *   Whether the zip holds a theme or a plugin.
   */
  private function fromZip(string $path, string $reference, SourceType $type): Source {
    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::RDONLY) !== TRUE) {
      throw new \RuntimeException("$reference is not a zip file.");
    }
    try {
      $slug = self::directory($zip, $reference);
      if ($type === SourceType::Theme) {
        if ($zip->locateName("$slug/templates/index.html") === FALSE) {
          throw new \RuntimeException("$reference holds no block theme: $slug/templates/index.html is missing.");
        }
        if ($zip->locateName("$slug/style.css") === FALSE) {
          throw new \RuntimeException("$reference holds no theme: $slug/style.css is missing.");
        }
        $style = (string) $zip->getFromName("$slug/style.css", self::HEADER_BYTES);
        $requires = self::header($style, 'Requires at least');
        $license = self::header($style, 'License');
        $licenseUri = self::header($style, 'License URI');
      }
      else {
        $requires = self::pluginRequires($zip, $slug, $reference);
        $license = '';
        $licenseUri = '';
      }
    }
    finally {
      $zip->close();
    }
    return new Source($type, $slug, self::version($requires, $reference), $path, hash_file('sha256', $path), $license, $licenseUri);
  }

  /**
   * Returns the one top-level directory of a zip, which is its slug.
   */
  private static function directory(\ZipArchive $zip, string $reference): string {
    $directories = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
      $name = (string) $zip->getNameIndex($index);
      if (str_starts_with($name, '__MACOSX/')) {
        continue;
      }
      $directories[strstr($name, '/', TRUE) ?: $name] = TRUE;
    }
    if (count($directories) !== 1) {
      throw new \RuntimeException("$reference must hold one top-level directory.");
    }
    $slug = (string) array_key_first($directories);
    if (!preg_match(Source::SLUG, $slug)) {
      throw new \RuntimeException("$reference holds the directory \"$slug\", which is not a valid slug.");
    }
    return $slug;
  }

  /**
   * Reads "Requires at least" from the plugin's main file.
   *
   * WordPress takes the main file to be the top-level PHP file whose headers
   * name the plugin.
   */
  private static function pluginRequires(\ZipArchive $zip, string $slug, string $reference): string {
    for ($index = 0; $index < $zip->numFiles; $index++) {
      $name = (string) $zip->getNameIndex($index);
      if (!preg_match('#^' . preg_quote($slug, '#') . '/[^/]+\.php\z#', $name)) {
        continue;
      }
      $headers = (string) $zip->getFromIndex($index, self::HEADER_BYTES);
      if (self::header($headers, 'Plugin Name') !== '') {
        return self::header($headers, 'Requires at least');
      }
    }
    throw new \RuntimeException("$reference holds no plugin: no PHP file in $slug/ names a plugin.");
  }

  /**
   * Reads one header the way WordPress's get_file_data() does.
   */
  private static function header(string $contents, string $name): string {
    if (!preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($name, '/') . ':(.*)$/mi', $contents, $match)) {
      return '';
    }
    return trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
  }

  /**
   * Checks a "Requires at least" value before it reaches version_compare().
   */
  private static function version(string $requires, string $reference): string {
    if ($requires !== '' && !preg_match('/^\d+(?:\.\d+)*\z/', $requires)) {
      throw new \RuntimeException("$reference states an unreadable required WordPress version: $requires");
    }
    return $requires;
  }

}
