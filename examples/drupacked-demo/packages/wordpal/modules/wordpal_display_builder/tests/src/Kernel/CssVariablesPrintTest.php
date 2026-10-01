<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder\Kernel;

use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ui_skins\CssVariable\CssVariablePluginManagerInterface;
use Drupal\ui_skins\Hook\PageTop;
use Drupal\wordpal_convert\Theme\DesignTokens;
use Drupal\wordpal_display_builder\Theme\CssVariables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Component\Serialization\Yaml;

/**
 * Tests the generated CSS variables file reaches ui_skins and the page.
 *
 * Installs a fixture theme holding the file CssVariables::yaml() generates,
 * then checks ui_skins discovers each preset as a variable and that page_top
 * holds a theme setting override.
 */
#[CoversClass(CssVariables::class)]
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class CssVariablesPrintTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = ['system', 'ui_skins'];

  /**
   * Installs the fixture theme carrying the generated variables file.
   */
  private function installGeneratedTheme(): void {
    $presets = [
      'color' => [['slug' => 'base', 'name' => 'Base', 'color' => '#f9f9f9']],
      'gradient' => [],
      'font-size' => [],
      'font-family' => [],
      'spacing' => [],
    ];
    // The fixture theme's checked-in file must equal what the generator
    // writes, so this test proves the real output.
    self::assertSame(
      CssVariables::yaml(Yaml::decode(DesignTokens::yaml($presets))),
      file_get_contents(__DIR__ . '/../../themes/wordpal_generated/wordpal_generated.ui_skins.css_variables.yml'),
    );
    $this->container->get(ThemeInstallerInterface::class)->install(['wordpal_generated']);
    $this->config('system.theme')->set('default', 'wordpal_generated')->save();
  }

  /**
   * Tests ui_skins lists the preset under WordPress's own variable name.
   */
  public function testUiSkinsDiscoversTheGeneratedVariable(): void {
    $this->installGeneratedTheme();

    $definition = $this->container->get(CssVariablePluginManagerInterface::class)->getDefinition('wp--preset--color--base');

    self::assertSame(['#f9f9f9'], array_values($definition->getDefaultValues()));
    self::assertSame('Base', (string) $definition->getLabel());
  }

  /**
   * Tests a theme setting override prints as an inline style in page_top.
   */
  public function testThemeSettingOverridePrintsInPageTop(): void {
    $this->installGeneratedTheme();
    $this->config('wordpal_generated.settings')
      ->set('third_party_settings.ui_skins.css_variables', ['wp--preset--color--base' => [':root' => '#123456']])
      ->save();

    $pageTop = [];
    $this->container->get(PageTop::class)->alter($pageTop);

    self::assertSame('style', $pageTop['ui_skins_css_variables']['#tag']);
    self::assertSame(':root{--wp--preset--color--base:#123456;}', $pageTop['ui_skins_css_variables']['#value']);
  }

}
