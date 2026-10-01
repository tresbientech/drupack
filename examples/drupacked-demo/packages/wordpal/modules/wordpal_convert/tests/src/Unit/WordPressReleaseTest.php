<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\WordPress\WordPressRelease;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the verified WordPress release download.
 */
#[CoversClass(WordPressRelease::class)]
#[Group('wordpal')]
final class WordPressReleaseTest extends UnitTestCase {

  /**
   * Tests that a well-formed checksum response is accepted verbatim.
   */
  public function testChecksumAcceptsValidHash(): void {
    $hash = str_repeat('a', 40);
    self::assertSame($hash, self::checksumMethod()->invoke(self::release(new MockHandler([new Response(200, [], $hash . "\n")])), '7.1.1'));
  }

  /**
   * Tests that a malformed checksum response fails closed.
   *
   * The checksum arrives over the network, so a response that isn't a
   * 40-character hex hash must stop the download rather than accept it.
   */
  public function testChecksumRejectsMalformedResponse(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('wordpress.org published no checksum for WordPress 7.1.1.');
    self::checksumMethod()->invoke(self::release(new MockHandler([new Response(200, [], '<html>not found</html>')])), '7.1.1');
  }

  /**
   * Tests a verified extract lands private and replaces a shared one.
   */
  public function testRootExtractsPrivately(): void {
    $version = '0.0.' . random_int(1000, 9999);
    $zipPath = sys_get_temp_dir() . "/wordpal-test-$version.zip";
    $zip = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('wordpress/wp-includes/blocks/paragraph/block.json', '{}');
    $zip->close();
    $body = (string) file_get_contents($zipPath);
    unlink($zipPath);
    $checksum = sha1($body);
    $directory = sys_get_temp_dir() . "/wordpal-wordpress-$version-$checksum";
    mkdir("$directory/wordpress/wp-includes", 0777, TRUE);
    chmod($directory, 0777);
    $handler = new MockHandler([
      new Response(200, [], $checksum),
      new Response(200, [], $body),
      new Response(200, [], $checksum),
    ]);
    $release = self::release($handler);

    try {
      $root = $release->root($version);
      self::assertFileExists("$root/wp-includes/blocks/paragraph/block.json", 'A shared, writable extract must be replaced.');
      self::assertSame(0, fileperms($directory) & 0077);
      self::assertSame($root, $release->root($version));
      self::assertCount(0, $handler, 'A private extract is reused without a download.');
      self::assertSame([], glob("$directory.*"), 'No staging directory is left behind.');
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Returns the private checksum-fetching method, made accessible.
   */
  private static function checksumMethod(): \ReflectionMethod {
    $method = new \ReflectionMethod(WordPressRelease::class, 'checksum');
    $method->setAccessible(TRUE);
    return $method;
  }

  /**
   * Returns a release whose HTTP client answers from $handler.
   */
  private static function release(MockHandler $handler): WordPressRelease {
    return new WordPressRelease(new Client(['handler' => HandlerStack::create($handler)]), new NullLogger());
  }

}
