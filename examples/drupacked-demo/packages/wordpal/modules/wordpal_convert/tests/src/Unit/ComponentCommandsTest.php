<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Component\DefinitionGenerator;
use Drupal\wordpal_convert\Drush\Commands\ComponentCommands;
use Drupal\wordpal_convert\WordPress\WordPressRelease;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests component generation, apart from its runtime-prop overlay.
 */
#[CoversClass(ComponentCommands::class)]
#[Group('wordpal')]
final class ComponentCommandsTest extends UnitTestCase {

  /**
   * Tests that the alter hook receives the built definition and slug.
   */
  public function testAltersComponentDefinitionThroughModuleHandler(): void {
    $definition = ['name' => 'Button'];
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->expects(self::once())
      ->method('alter')
      ->with('wordpal_component_definition', $definition, 'button');
    $method = new \ReflectionMethod(ComponentCommands::class, 'alteredDefinition');
    $method->setAccessible(TRUE);

    $method->invoke($this->commandsWithModuleHandler($moduleHandler), $definition, 'button');
  }

  /**
   * Tests that the copy rewrites the runtime import and replaces the directory.
   */
  public function testCopiesScriptModulesWithRelativeRuntimeImport(): void {
    $base = sys_get_temp_dir() . '/wordpal-script-modules-' . bin2hex(random_bytes(4));
    $runtime = "export { store };\n";
    $view = "import { store } from \"@wordpress/interactivity\";\nimport(\"@wordpress/interactivity-router\");\n";
    self::writeFiles("$base/source", [
      'interactivity/index.js' => $runtime,
      'block-library/accordion/view.js' => $view,
      'block-library/navigation/view.js' => $view,
      'block-library/search/view.js' => $view,
      'block-editor/utils/fit-text-frontend.js' => $view,
    ]);
    self::writeFiles("$base/destination", ['stale.js' => 'old']);
    try {
      self::copyMethod()->invoke($this->commandsWithModuleHandler($this->createMock(ModuleHandlerInterface::class)), "$base/source", "$base/destination", '7.1.2');

      self::assertSame($runtime, file_get_contents("$base/destination/interactivity/index.js"));
      self::assertSame(
        "import { store } from \"../../interactivity/index.js?ver=7.1.2\";\nimport(\"@wordpress/interactivity-router\");\n",
        file_get_contents("$base/destination/block-library/navigation/view.js"),
      );
      self::assertFileDoesNotExist("$base/destination/stale.js");
    }
    finally {
      (new Filesystem())->remove($base);
    }
  }

  /**
   * Tests that a bad release fails before the old copies are removed.
   *
   * @param array<string, string> $files
   *   The source files, keyed by path.
   * @param string $message
   *   The expected exception message.
   */
  #[DataProvider('badScriptModuleSources')]
  public function testRefusesBadScriptModuleSource(array $files, string $message): void {
    $base = sys_get_temp_dir() . '/wordpal-script-modules-' . bin2hex(random_bytes(4));
    self::writeFiles("$base/source", $files);
    self::writeFiles("$base/destination", ['interactivity/index.js' => 'kept']);
    try {
      self::copyMethod()->invoke($this->commandsWithModuleHandler($this->createMock(ModuleHandlerInterface::class)), "$base/source", "$base/destination", '7.1.2');
      self::fail('The copy accepted a bad release.');
    }
    catch (\RuntimeException $exception) {
      self::assertSame($message, $exception->getMessage());
      self::assertSame('kept', file_get_contents("$base/destination/interactivity/index.js"));
    }
    finally {
      (new Filesystem())->remove($base);
    }
  }

  /**
   * Provides bad script module sources and the message each raises.
   */
  public static function badScriptModuleSources(): array {
    return [
      'missing view module' => [
        ['interactivity/index.js' => 'export {};'],
        'WordPress 7.1.2 has no script module block-library/accordion/view.js.',
      ],
      'view module without the runtime import' => [
        [
          'interactivity/index.js' => 'export {};',
          'block-library/accordion/view.js' => 'import { a } from "./a.js";',
          'block-library/navigation/view.js' => 'import { a } from "./a.js";',
          'block-library/search/view.js' => 'import { a } from "./a.js";',
          'block-editor/utils/fit-text-frontend.js' => 'import { a } from "./a.js";',
        ],
        'WordPress 7.1.2 script module block-library/accordion/view.js does not import @wordpress/interactivity.',
      ],
    ];
  }

  /**
   * Tests that the copy carries style.css but leaves theme.css out.
   *
   * WordPress bundles theme.css into wp-block-library-theme.css, enqueued
   * only for a classic theme with no theme.json. Every theme wordpal
   * converts has one, so the Reference render never loads it.
   */
  public function testCopiesBlockStyleCssButNotThemeCss(): void {
    $base = sys_get_temp_dir() . '/wordpal-block-css-' . bin2hex(random_bytes(4));
    self::writeFiles("$base/source/quote", [
      'style.css' => '.wp-block-quote { box-sizing: border-box; }',
      'theme.css' => '.wp-block-quote cite { font-size: 0.8125em; }',
    ]);
    try {
      self::blockCssMethod()->invoke($this->commandsWithModuleHandler($this->createMock(ModuleHandlerInterface::class)), "$base/source/quote", "$base/quote.css");

      self::assertSame("/* WordPress style.css */\n.wp-block-quote { box-sizing: border-box; }\n", file_get_contents("$base/quote.css"));
    }
    finally {
      (new Filesystem())->remove($base);
    }
  }

  /**
   * Tests that a block with no style.css removes a stale destination file.
   */
  public function testRemovesStaleBlockCssWithNoStyleCss(): void {
    $base = sys_get_temp_dir() . '/wordpal-block-css-' . bin2hex(random_bytes(4));
    self::writeFiles("$base/source/query", ['theme.css' => '.wp-block-query { display: block; }']);
    self::writeFiles($base, ['query.css' => 'stale']);
    try {
      self::blockCssMethod()->invoke($this->commandsWithModuleHandler($this->createMock(ModuleHandlerInterface::class)), "$base/source/query", "$base/query.css");

      self::assertFileDoesNotExist("$base/query.css");
    }
    finally {
      (new Filesystem())->remove($base);
    }
  }

  /**
   * Returns the private script module copy method.
   */
  private static function copyMethod(): \ReflectionMethod {
    return new \ReflectionMethod(ComponentCommands::class, 'copyScriptModules');
  }

  /**
   * Returns the private block CSS copy method.
   */
  private static function blockCssMethod(): \ReflectionMethod {
    return new \ReflectionMethod(ComponentCommands::class, 'copyBlockCss');
  }

  /**
   * Writes files under a directory, creating their parents.
   *
   * @param string $directory
   *   The directory.
   * @param array<string, string> $files
   *   File contents keyed by relative path.
   */
  private static function writeFiles(string $directory, array $files): void {
    foreach ($files as $path => $contents) {
      (new Filesystem())->dumpFile("$directory/$path", $contents);
    }
  }

  /**
   * Returns a ComponentCommands wired to $moduleHandler, no HTTP response.
   */
  private function commandsWithModuleHandler(ModuleHandlerInterface $moduleHandler): ComponentCommands {
    $release = new WordPressRelease(new Client(['handler' => HandlerStack::create(new MockHandler())]), new NullLogger());
    return new ComponentCommands($release, new DefinitionGenerator(), new ComponentSet($this->root, $this->createMock(ModuleExtensionList::class)), $moduleHandler);
  }

}
