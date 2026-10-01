<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\WordPress;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Downloads and unpacks a verified WordPress release.
 */
final class WordPressRelease {

  public function __construct(
    #[Autowire(service: 'http_client')]
    private readonly ClientInterface $httpClient,
    #[Autowire(service: 'logger.channel.wordpal_convert')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the root of an unpacked release, the directory holding wp-includes.
   *
   * The cached extract is keyed on the release's verified checksum, so a
   * corrupt or re-tagged release under the same version number re-downloads
   * rather than reusing a stale extract.
   */
  public function root(string $version): string {
    $checksum = $this->checksum($version);
    $directory = sys_get_temp_dir() . '/wordpal-wordpress-' . $version . '-' . $checksum;
    $root = $directory . '/wordpress';
    // The shared temp directory is writable by other accounts. Only a
    // private extract, one no other account can write, is reused.
    if (is_dir("$root/wp-includes") && (fileperms($directory) & 0077) === 0) {
      return $root;
    }

    $filesystem = new Filesystem();
    $staging = $directory . '.' . bin2hex(random_bytes(8));
    if (!mkdir($staging, 0700)) {
      throw new \RuntimeException("Could not create the private directory $staging.");
    }
    try {
      $this->unpackVerified($version, $checksum, $staging);
      if (file_exists($directory)) {
        $filesystem->remove($directory);
      }
      if (!rename("$staging/extract", $directory)) {
        throw new \RuntimeException("Could not move WordPress $version into $directory.");
      }
    }
    finally {
      $filesystem->remove($staging);
    }
    if (!is_dir("$root/wp-includes")) {
      throw new \RuntimeException("WordPress $version holds no wp-includes at $root.");
    }
    return $root;
  }

  /**
   * Returns the directory holding one block.json directory per core block.
   */
  public function blocks(string $version): string {
    return $this->root($version) . '/wp-includes/blocks';
  }

  /**
   * Downloads, verifies and unpacks one release into "$staging/extract".
   */
  private function unpackVerified(string $version, string $checksum, string $staging): void {
    $archive = "$staging/wordpress.zip";
    $this->logger->notice('Downloading WordPress @version.', ['@version' => $version]);
    $this->httpClient->request('GET', "https://wordpress.org/wordpress-$version.zip", ['sink' => $archive]);
    if (!hash_equals($checksum, (string) sha1_file($archive))) {
      throw new \RuntimeException("WordPress $version failed checksum verification against wordpress.org.");
    }
    $zip = new \ZipArchive();
    if ($zip->open($archive) !== TRUE) {
      throw new \RuntimeException("WordPress $version could not be unpacked.");
    }
    mkdir("$staging/extract", 0700);
    $zip->extractTo("$staging/extract");
    $zip->close();
  }

  /**
   * Returns wordpress.org's published SHA-1 checksum for a release zip.
   *
   * The release zip arrives over the network, so its checksum is verified
   * before anything extracts it.
   */
  private function checksum(string $version): string {
    try {
      $response = $this->httpClient->request('GET', "https://wordpress.org/wordpress-$version.zip.sha1");
    }
    catch (\Throwable $exception) {
      throw new \RuntimeException("Could not read the WordPress $version checksum from wordpress.org.", 0, $exception);
    }
    $checksum = trim((string) $response->getBody());
    if (!preg_match('/^[0-9a-f]{40}\z/', $checksum)) {
      throw new \RuntimeException("wordpress.org published no checksum for WordPress $version.");
    }
    return $checksum;
  }

}
