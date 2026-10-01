<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_convert\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Content\ContentMappingLoader;
use Drupal\wordpal_convert\Drush\Commands\ConvertCommands;
use Drupal\wordpal_convert\WordPress\PlaygroundRunner;
use Drupal\wordpal_convert\WordPress\SourceResolver;
use Drupal\wordpal_convert\WordPress\ThemeSource;
use Drupal\wordpal_convert\WriterInterface;
use Drupal\wordpal_convert\WriterSelector;
use Drush\Drush;
use Drush\Log\DrushLoggerManager;
use GuzzleHttp\Client;
use Symfony\Component\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the --target, --mapping and license checks that run before Playground.
 */
#[CoversClass(ConvertCommands::class)]
#[Group('wordpal')]
final class ConvertCommandsTest extends UnitTestCase {

  /**
   * Tests a missing --target fails when both targets are enabled.
   */
  public function testMissingTargetWithBothEnabledAsksForIt(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Pass --target=canvas or --target=display_builder');

    $this->checkTarget($this->enabled(['wordpal_canvas', 'wordpal_display_builder']), NULL);
  }

  /**
   * Tests a missing --target picks the only enabled target.
   */
  public function testMissingTargetPicksTheEnabledTarget(): void {
    self::assertSame('display_builder', $this->checkTarget($this->enabled(['wordpal_display_builder']), NULL));
  }

  /**
   * Tests a missing --target with no target enabled names both modules.
   */
  public function testMissingTargetWithNoneEnabledNamesTheModules(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('wordpal_canvas or wordpal_display_builder');

    $this->checkTarget($this->enabled([]), NULL);
  }

  /**
   * Tests an unknown --target value is rejected.
   */
  public function testUnknownTargetIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);

    $this->checkTarget($this->createMock(ModuleHandlerInterface::class), 'wordpress');
  }

  /**
   * Tests a disabled target module fails and names the module.
   */
  public function testDisabledTargetModuleNamesTheModule(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('wordpal_display_builder')->willReturn(FALSE);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('wordpal_display_builder');

    $this->checkTarget($moduleHandler, 'display_builder');
  }

  /**
   * Tests an enabled target's module returns the target unchanged.
   */
  public function testEnabledTargetModulePasses(): void {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('wordpal_canvas')->willReturn(TRUE);

    self::assertSame('canvas', $this->checkTarget($moduleHandler, 'canvas'));
  }

  /**
   * Tests an unreadable --mapping fails before the theme source is read.
   *
   * The instance has no theme source or Playground runner, so reaching the
   * snapshot step throws an Error instead of returning.
   */
  public function testUnreadableMappingFailsBeforeSnapshot(): void {
    $commands = (new \ReflectionClass(ConvertCommands::class))->newInstanceWithoutConstructor();
    $this->setProperty($commands, 'moduleHandler', $this->enabled(['wordpal_canvas']));
    $this->setProperty($commands, 'mappingLoader', new ContentMappingLoader());
    $logger = $this->createMock(DrushLoggerManager::class);
    $logger->expects($this->once())->method('error')->with('Content mapping file is not readable: /no/such/mapping.yml.');
    $commands->setLogger($logger);

    $exit = $commands->convert('twentytwentyfour', [
      'target' => NULL,
      'mapping' => '/no/such/mapping.yml',
      'plugin' => [],
      'refresh' => FALSE,
      'replace' => FALSE,
      'demo-content' => TRUE,
      'activate' => TRUE,
    ]);

    self::assertSame(ConvertCommands::EXIT_FAILURE, $exit);
  }

  /**
   * Tests a zip theme with an unknown license fails before Playground runs.
   *
   * The instance has no Playground runner, so reaching the snapshot step
   * throws an Error instead of returning.
   */
  public function testUnknownLicenseZipFailsBeforeSnapshot(): void {
    // The command logs through dt(), which Drush loads outside Composer.
    require_once dirname((new \ReflectionClass(Drush::class))->getFileName(), 2) . '/includes/output.inc';
    $directory = sys_get_temp_dir() . '/wordpal-convert-' . bin2hex(random_bytes(6));
    mkdir($directory . '/wordpal/components', 0777, TRUE);
    file_put_contents($directory . '/wordpal/components/wordpress-version.json', '{"version": "7.1.1"}');
    $zip = new \ZipArchive();
    $zip->open($directory . '/theme.zip', \ZipArchive::CREATE);
    $zip->addFromString('acme/style.css', "/*\nTheme Name: Acme\nLicense: Envato Regular License\n*/");
    $zip->addFromString('acme/templates/index.html', '');
    $zip->close();

    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->willReturn('wordpal');
    $componentSet = new ComponentSet($directory, $moduleList);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn($directory);
    $themeSource = new ThemeSource(new Client());
    $writer = $this->createMock(WriterInterface::class);
    $writer->method('label')->willReturn('Drupal Canvas');
    $writerSelector = new WriterSelector();
    $writerSelector->addWriter($writer, 'canvas');

    $commands = (new \ReflectionClass(ConvertCommands::class))->newInstanceWithoutConstructor();
    $this->setProperty($commands, 'moduleHandler', $this->enabled(['wordpal_canvas']));
    $this->setProperty($commands, 'componentSet', $componentSet);
    $this->setProperty($commands, 'fileSystem', $fileSystem);
    $this->setProperty($commands, 'themeSource', $themeSource);
    $this->setProperty($commands, 'writerSelector', $writerSelector);
    $this->setProperty($commands, 'sourceResolver', new SourceResolver($themeSource, new PlaygroundRunner(), $componentSet, $fileSystem));
    $logger = $this->createMock(DrushLoggerManager::class);
    $logger->expects($this->once())->method('error')->with(self::stringStartsWith('The theme states the license "Envato Regular License" in style.css'));
    $commands->setLogger($logger);

    try {
      $exit = $commands->convert($directory . '/theme.zip', [
        'target' => NULL,
        'mapping' => NULL,
        'plugin' => [],
        'refresh' => TRUE,
        'replace' => TRUE,
        'demo-content' => TRUE,
        'activate' => TRUE,
        'accept-license' => FALSE,
      ]);
      self::assertSame(ConvertCommands::EXIT_FAILURE, $exit);
    }
    finally {
      (new Filesystem())->remove($directory);
    }
  }

  /**
   * Builds a module handler with only the given modules enabled.
   *
   * @param string[] $modules
   *   The enabled module names.
   */
  private function enabled(array $modules): ModuleHandlerInterface {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(static fn (string $module): bool => in_array($module, $modules, TRUE));
    return $moduleHandler;
  }

  /**
   * Invokes ConvertCommands::checkTarget() on an instance built for this test.
   *
   * The other constructor dependencies never run: checkTarget() reads only
   * the module handler, and this fails before Playground would touch them.
   */
  private function checkTarget(ModuleHandlerInterface $moduleHandler, ?string $target): string {
    $commands = (new \ReflectionClass(ConvertCommands::class))->newInstanceWithoutConstructor();
    $this->setProperty($commands, 'moduleHandler', $moduleHandler);
    $method = new \ReflectionMethod(ConvertCommands::class, 'checkTarget');
    $method->setAccessible(TRUE);
    return $method->invoke($commands, $target);
  }

  /**
   * Sets a private constructor property on an instance built for this test.
   */
  private function setProperty(ConvertCommands $commands, string $name, object $value): void {
    $property = new \ReflectionProperty(ConvertCommands::class, $name);
    $property->setAccessible(TRUE);
    $property->setValue($commands, $value);
  }

}
