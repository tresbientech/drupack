<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\system\Entity\Menu;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the standalone WordPress navigation-link block.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class NavigationLinkBlockTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'link',
    'node',
    'menu_link_content',
    'comment',
    'text',
    'wordpal',
  ];

  /**
   * Tests link values, access filtering, and cache metadata.
   */
  public function testBuildsAccessCheckedMenuLink(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'system']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    Menu::create(['id' => 'wordpal_main', 'label' => 'WordPal main'])->save();
    $visible = MenuLinkContent::create([
      'title' => 'Visible',
      'link' => ['uri' => 'internal:/visible'],
      'menu_name' => 'wordpal_main',
      'enabled' => TRUE,
    ]);
    $visible->save();
    $hiddenNode = Node::create(['type' => 'page', 'title' => 'Hidden', 'status' => 0]);
    $hiddenNode->save();
    $hidden = MenuLinkContent::create([
      'title' => 'Hidden',
      'link' => ['uri' => 'entity:node/' . $hiddenNode->id()],
      'menu_name' => 'wordpal_main',
      'enabled' => TRUE,
    ]);
    $hidden->save();

    $manager = $this->container->get('plugin.manager.block');
    $build = $manager->createInstance('wordpal_navigation_link', [
      'menu_link' => $visible->getPluginId(),
      'css_class' => 'fixture-link',
    ])->build();
    self::assertSame('Visible', $build['#title']);
    self::assertSame('/visible', $build['#url']);
    self::assertSame('fixture-link', $build['#css_class']);
    self::assertContains('config:system.menu.wordpal_main', $build['#cache']['tags']);

    $denied = $manager->createInstance('wordpal_navigation_link', [
      'menu_link' => $hidden->getPluginId(),
    ])->build();
    self::assertArrayNotHasKey('#theme', $denied);
    self::assertContains('user.permissions', $denied['#cache']['contexts']);
  }

}
