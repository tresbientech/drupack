<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Content\ContentFixtureLoader;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests shared fixture input validation.
 */
#[CoversClass(ContentFixtureLoader::class)]
#[Group('wordpal')]
final class ContentFixtureLoaderTest extends UnitTestCase {

  /**
   * Tests the committed cross-runtime fixture.
   */
  public function testLoadsSharedFixture(): void {
    $fixture = (new ContentFixtureLoader())->load(DemoContentSeeder::FIXTURE);

    self::assertSame('a-walk-through-the-city', $fixture['post']['slug']);
    self::assertSame(['City', 'Walking'], $fixture['post']['tags']);
    self::assertCount(8, $fixture['older_posts']);
    self::assertSame('2024-01-15T12:30:00+00:00', $fixture['comments'][1]['date']);
    self::assertLessThan(strtotime($fixture['post']['date']), strtotime($fixture['neighbors']['previous']['date']));
    self::assertGreaterThan(strtotime($fixture['post']['date']), strtotime($fixture['neighbors']['next']['date']));
  }

  /**
   * Tests that fixture errors are aggregated before seeding.
   */
  public function testReportsAllErrors(): void {
    $path = sys_get_temp_dir() . '/wordpal-invalid-fixture-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($path, json_encode([
      'author' => ['username' => ''],
      'post' => ['tags' => [], 'date' => 'never', 'featured_image' => ['base64' => '!']],
    ], JSON_THROW_ON_ERROR));

    try {
      (new ContentFixtureLoader())->load($path);
      self::fail('An incomplete fixture should fail.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('author.username must be a non-empty string.', $exception->getMessage());
      self::assertStringContainsString('post.title must be a non-empty string.', $exception->getMessage());
      self::assertStringContainsString('neighbors.previous.title must be a non-empty string.', $exception->getMessage());
      self::assertStringContainsString('older_posts must be a non-empty list.', $exception->getMessage());
      self::assertStringContainsString('post.tags must be a non-empty list.', $exception->getMessage());
      self::assertStringContainsString('post.featured_image.base64 must be valid base64.', $exception->getMessage());
      self::assertStringContainsString('comments must be a non-empty list.', $exception->getMessage());
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Tests comment dates, identities, and ordered parent references.
   */
  public function testRejectsInvalidComments(): void {
    $fixture = json_decode(
      (string) file_get_contents(DemoContentSeeder::FIXTURE),
      TRUE,
      flags: JSON_THROW_ON_ERROR,
    );
    $fixture['comments'] = [
      [
        'id' => 'duplicate',
        'author' => 'One',
        'email' => 'bad',
        'body' => 'Body',
        'date' => 'never',
        'parent' => 'duplicate',
      ],
      [
        'id' => 'duplicate',
        'author' => 'Two',
        'email' => 'two@example.com',
        'body' => 'Body',
        'date' => '2024-01-01T00:00:00Z',
        'parent' => NULL,
      ],
      [
        'id' => 'missing-parent',
        'author' => 'Three',
        'email' => 'three@example.com',
        'body' => 'Body',
        'date' => '2024-01-01T01:00:00Z',
      ],
    ];
    $path = sys_get_temp_dir() . '/wordpal-invalid-comments-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));

    try {
      (new ContentFixtureLoader())->load($path);
      self::fail('Invalid comment fixtures should fail.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('comments.0.email must be a valid email address.', $exception->getMessage());
      self::assertStringContainsString('comments.0.date must be a valid date.', $exception->getMessage());
      self::assertStringContainsString('comments.0.parent must reference an earlier comment.', $exception->getMessage());
      self::assertStringContainsString('comments.1.id must be unique.', $exception->getMessage());
      self::assertStringContainsString('comments.2.parent must be present.', $exception->getMessage());
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Tests that image paths and content cannot escape the public fixture area.
   */
  public function testRejectsUnsafeImageInput(): void {
    $fixture = json_decode((string) file_get_contents(DemoContentSeeder::FIXTURE), TRUE, flags: JSON_THROW_ON_ERROR);
    $fixture['post']['featured_image'] = [
      'filename' => '../payload.php',
      'mime_type' => 'application/x-php',
      'alt' => 'Payload',
      'base64' => base64_encode('<?php'),
    ];
    $path = sys_get_temp_dir() . '/wordpal-unsafe-fixture-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));

    try {
      (new ContentFixtureLoader())->load($path);
      self::fail('An unsafe image fixture should fail.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('filename must be a safe image basename', $exception->getMessage());
      self::assertStringContainsString('MIME type must match its image extension', $exception->getMessage());
      self::assertStringContainsString('bytes must match its declared image MIME type', $exception->getMessage());
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Tests that navigation accepts only root-relative links and the page.
   */
  public function testRejectsUnsafeNavigationInput(): void {
    $fixture = json_decode((string) file_get_contents(DemoContentSeeder::FIXTURE), TRUE, flags: JSON_THROW_ON_ERROR);
    $fixture['navigation']['items'] = [
      ['title' => '', 'path' => 'https://example.com'],
      ['title' => 'Unsafe', 'path' => "/safe\nHeader: value"],
      ['title' => 'Scheme relative', 'path' => '//example.com/path'],
      ['title' => 'Backslash', 'path' => '/safe\\unsafe'],
      ['title' => 'Other target', 'target' => 'post'],
      ['title' => 'Both', 'target' => 'page', 'path' => '/'],
    ];
    $path = sys_get_temp_dir() . '/wordpal-unsafe-navigation-' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));

    try {
      (new ContentFixtureLoader())->load($path);
      self::fail('Unsafe navigation fixture paths should fail.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('navigation.items.0.title must be a non-empty string.', $exception->getMessage());
      self::assertStringContainsString('navigation.items.0.path must be a root-relative path.', $exception->getMessage());
      self::assertStringContainsString('navigation.items.1.path must be a root-relative path.', $exception->getMessage());
      self::assertStringContainsString('navigation.items.2.path must be a root-relative path.', $exception->getMessage());
      self::assertStringContainsString('navigation.items.3.path must be a root-relative path.', $exception->getMessage());
      self::assertStringContainsString('navigation.items.4.target must be "page", without a path.', $exception->getMessage());
      self::assertStringContainsString('navigation.items.5.target must be "page", without a path.', $exception->getMessage());
    }
    finally {
      unlink($path);
    }
  }

}
