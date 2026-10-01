<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\Core\Config\Config;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\wordpal_convert\ConversionOwnership;
use Drupal\wordpal_convert\Write\ConversionTransaction;
use Drupal\wordpal_convert\Content\DemoContentSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ConversionTransaction's config, takeover and View-replace undo.
 *
 * Also tests the RuntimeException an undo failure wraps around the body's
 * own exception.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ConversionTransactionTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'wordpal_convert', 'wordpal_canvas', 'wordpal_canvas_runtime', 'system', 'user', 'field',
    'node', 'text', 'file', 'image', 'views', 'path', 'path_alias', 'block',
    'comment',
  ];

  /**
   * Builds a transaction with the module's own ConversionOwnership service.
   */
  private function transaction(): ConversionTransaction {
    return new ConversionTransaction(
      $this->container->get(ConversionOwnership::class),
      $this->container->get('entity_type.manager'),
      'wordpal_fixture',
    );
  }

  /**
   * Builds a manifest with the fixed shape ConversionOwnership::save() writes.
   */
  private static function manifest(array $entities): array {
    return [
      'entities' => $entities + array_fill_keys(ConversionOwnership::ENTITY_TYPES, []),
      'config' => [],
      'files' => [],
      'pages' => [],
      'demo_content' => array_fill_keys(DemoContentSeeder::ENTITY_TYPES, []),
      'recipe' => 'wordpal_wordpal_fixture',
      'replaced_views' => [],
    ];
  }

  /**
   * Returns a page variant's one required "Page content" marker placement.
   */
  private static function marker(): array {
    return [
      'uuid' => \Drupal::service('uuid')->generate(),
      'component_id' => Marker::PAGE_CONTENT_COMPONENT_ID,
      'component_version' => Component::load(Marker::PAGE_CONTENT_COMPONENT_ID)->getActiveVersion(),
      'inputs' => [],
    ];
  }

  /**
   * Tests run() restores the theme, front, 404 and frame config on a throw.
   *
   * These are the four writes ConversionRunner::write() makes through the
   * transaction: the default theme, the front and 404 pages, and the Canvas
   * default page variant. The frame starts unset, as on a fresh site.
   */
  public function testRunRestoresConfigOnThrow(): void {
    PageVariant::create(['id' => 'new_variant', 'label' => 'New', 'component_tree' => [self::marker()]])->save();
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->config('system.site')->set('page.front', '/user/login')->set('page.404', '/user/password')->save();

    $tx = $this->transaction();
    $tx->setConfig($this->config('system.theme'), 'default', 'wordpal_fixture');
    $tx->setConfig($this->config('system.site'), 'page.front', '/node/1');
    $tx->setConfig($this->config('system.site'), 'page.404', '/node/2');
    $tx->setConfig($this->config('canvas.settings'), 'default_page_variant', 'new_variant');

    try {
      $tx->run(static function (): void {
        throw new \RuntimeException('write failed');
      });
      self::fail('A throwing body must rethrow.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('write failed', $exception->getMessage());
    }

    self::assertSame('stark', $this->config('system.theme')->get('default'));
    self::assertSame('/user/login', $this->config('system.site')->get('page.front'));
    self::assertSame('/user/password', $this->config('system.site')->get('page.404'));
    self::assertNull($this->config('canvas.settings')->get('default_page_variant'));
  }

  /**
   * Tests run() returns the body's value and keeps its writes on success.
   */
  public function testRunReturnsBodyResultAndKeepsWrites(): void {
    $tx = $this->transaction();
    $tx->setConfig($this->config('system.theme'), 'default', 'wordpal_fixture');

    $result = $tx->run(static fn (): string => 'ok');

    self::assertSame('ok', $result);
    self::assertSame('wordpal_fixture', $this->config('system.theme')->get('default'));
  }

  /**
   * Tests run() undoes three writes to the same key in reverse order.
   *
   * Restoring in forward order would leave the middle value; only reverse
   * order restores the value from before any of the three writes.
   */
  public function testRunUnwindsInReverseOrder(): void {
    $config = $this->config('system.theme');
    $config->set('default', 'stark')->save();

    $tx = $this->transaction();
    $tx->setConfig($config, 'default', 'a');
    $tx->setConfig($config, 'default', 'b');
    $tx->setConfig($config, 'default', 'c');

    try {
      $tx->run(static function (): void {
        throw new \RuntimeException('write failed');
      });
      self::fail('A throwing body must rethrow.');
    }
    catch (\RuntimeException) {
    }

    self::assertSame('stark', $config->get('default'));
  }

  /**
   * Tests a later undo still runs after an earlier one throws.
   *
   * "Earlier" in run order: the one registered last, since undo runs in
   * reverse. Its failure must not stop the undo registered before it.
   */
  public function testRunContinuesUnwindingAfterAnUndoThrows(): void {
    $restored = $this->config('system.theme');
    $restored->set('default', 'stark')->save();

    $failing = $this->createMock(Config::class);
    $failing->method('get')->willReturn('previous');
    $failing->method('set')->willReturnSelf();
    $saves = 0;
    $failing->method('save')->willReturnCallback(function () use (&$saves): void {
      $saves++;
      if ($saves > 1) {
        throw new \RuntimeException('undo failed');
      }
    });

    $tx = $this->transaction();
    $tx->setConfig($restored, 'default', 'a');
    $tx->setConfig($failing, 'key', 'value');

    try {
      $tx->run(static function (): void {
        throw new \RuntimeException('write failed');
      });
      self::fail('A throwing body must rethrow.');
    }
    catch (\RuntimeException) {
    }

    self::assertSame('stark', $restored->get('default'), 'The undo registered before the failing one still ran.');
  }

  /**
   * Tests takeOver() then a throw recreates the entity.
   *
   * Undo removes its id from the manifest, since the recreated entity is
   * the site's again.
   */
  public function testTakeOverRestoresEntityAndManifestOnThrow(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['field', 'node', 'system', 'user', 'views']);
    NodeType::create(['type' => 'wordpal_post', 'name' => 'WordPal post'])->save();
    $ownership = $this->container->get(ConversionOwnership::class);
    $ownership->save('wordpal_fixture', self::manifest(['content_template' => ['node.wordpal_post.full']]));
    $template = ContentTemplate::create([
      'id' => 'node.wordpal_post.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'wordpal_post',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
    $template->setStatus(FALSE)->save();

    $tx = $this->transaction();
    $tx->takeOver('content_template', 'node.wordpal_post.full');
    self::assertNull(ContentTemplate::load('node.wordpal_post.full'), 'takeOver() deletes the site entity.');

    // The Conversion writes its own template at the taken-over id, so
    // undo's delete-then-create runs against a live entity.
    $written = ContentTemplate::create([
      'id' => 'node.wordpal_post.full',
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'wordpal_post',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
    $written->save();

    try {
      $tx->run(static function (): void {
        throw new \RuntimeException('write failed');
      });
      self::fail('A throwing body must rethrow.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('write failed', $exception->getMessage());
    }

    self::assertNull($this->container->get('entity.repository')->loadEntityByUuid('content_template', $written->uuid()), "Undo deletes the entity the Conversion wrote at the taken-over id.");
    $restored = ContentTemplate::load('node.wordpal_post.full');
    self::assertNotNull($restored, 'Undo recreates the taken-over entity.');
    self::assertSame($template->uuid(), $restored->uuid());
    self::assertFalse($restored->status());
    self::assertSame([], $ownership->load('wordpal_fixture')['entities']['content_template'], 'Undo removes the id from the manifest.');
  }

  /**
   * Tests replaceView() then a throw re-enables the View.
   *
   * Undo removes its id from the manifest, since the re-enabled View is
   * the site's again.
   */
  public function testReplaceViewRestoresViewAndManifestOnThrow(): void {
    $ownership = $this->container->get(ConversionOwnership::class);
    $ownership->save('wordpal_fixture', self::manifest([]));
    View::create([
      'id' => 'site_terms',
      'label' => 'Site terms',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [],
        ],
      ],
    ])->save();

    $tx = $this->transaction();
    $tx->replaceView('site_terms');
    self::assertFalse(View::load('site_terms')->status(), 'replaceView() disables the site View.');
    self::assertSame(['site_terms'], $ownership->load('wordpal_fixture')['replaced_views']);

    try {
      $tx->run(static function (): void {
        throw new \RuntimeException('write failed');
      });
      self::fail('A throwing body must rethrow.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('write failed', $exception->getMessage());
    }

    self::assertTrue(View::load('site_terms')->status(), 'Undo re-enables the replaced View.');
    self::assertSame([], $ownership->load('wordpal_fixture')['replaced_views'], 'Undo removes the id from the manifest.');
  }

  /**
   * Tests enableView() then a throw disables the View again.
   */
  public function testEnableViewDisablesOnThrow(): void {
    View::create([
      'id' => 'site_terms',
      'label' => 'Site terms',
      'status' => FALSE,
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [],
        ],
      ],
    ])->save();

    $tx = $this->transaction();

    try {
      $tx->run(function () use ($tx): void {
        $tx->enableView('site_terms');
        self::assertTrue(View::load('site_terms')->status(), 'enableView() enables the View.');
        throw new \RuntimeException('write failed');
      });
      self::fail('A throwing body must rethrow.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('write failed', $exception->getMessage());
    }

    self::assertFalse(View::load('site_terms')->status(), 'Undo disables the View again.');
  }

  /**
   * Tests an undo that throws still surfaces the body's original exception.
   *
   * The thrown RuntimeException names the undo failure's class, file and
   * line and its message. The body's own exception instance stays
   * reachable through getPrevious().
   */
  public function testRunKeepsOriginalExceptionWhenUndoThrows(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturn('previous');
    $config->method('set')->willReturnSelf();
    $saves = 0;
    $config->method('save')->willReturnCallback(function () use (&$saves): void {
      $saves++;
      if ($saves > 1) {
        throw new \RuntimeException('undo failed');
      }
    });

    $tx = $this->transaction();
    $tx->setConfig($config, 'key', 'value');

    $original = new \RuntimeException('write failed');
    try {
      $tx->run(function () use ($original): void {
        throw $original;
      });
      self::fail('A throwing body must rethrow.');
    }
    catch (\RuntimeException $exception) {
      self::assertMatchesRegularExpression('/^Undo failed: RuntimeException \(.+ConversionTransactionTest\.php:\d+\): undo failed$/', $exception->getMessage());
      self::assertSame($original, $exception->getPrevious());
    }
  }

  /**
   * Tests abandon() then a throw drops its deleted id from the manifest.
   *
   * The candidate is absent from $created, so abandon() deletes it. Undo
   * finds it still gone from storage and removes it from the manifest.
   */
  public function testAbandonDropsDeletedIdFromManifestOnThrow(): void {
    $ownership = $this->container->get(ConversionOwnership::class);
    $ownership->save('wordpal_fixture', self::manifest(['pattern' => ['wordpal_fixture_outer']]));
    Pattern::create(['id' => 'wordpal_fixture_outer', 'label' => 'Outer', 'component_tree' => []])->save();

    $tx = $this->transaction();
    $candidates = array_fill_keys(ConversionOwnership::ENTITY_TYPES, []);
    $candidates['pattern'][] = 'wordpal_fixture_outer';

    try {
      $tx->run(function () use ($tx, $candidates): void {
        $tx->abandon($candidates, []);
        throw new \RuntimeException('write failed');
      });
      self::fail('A throwing body must rethrow.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('write failed', $exception->getMessage());
    }

    self::assertNull(Pattern::load('wordpal_fixture_outer'), 'abandon() deletion is not undone.');
    self::assertSame([], $ownership->load('wordpal_fixture')['entities']['pattern'], 'Undo drops the deleted id from the manifest.');
  }

}
