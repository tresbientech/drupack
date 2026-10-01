<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder_runtime\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests a frame's routes condition matches its paths or its node bundles.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class FrameRoutesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = ['system', 'user', 'node', 'path_alias', 'wordpal_display_builder_runtime'];

  /**
   * Tests either a path or a bundle matches, and nothing else does.
   */
  public function testMatchesPathOrBundle(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    NodeType::create(['type' => 'post', 'name' => 'Post'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $manager = $this->container->get('plugin.manager.condition');
    $condition = $manager->createInstance('wordpal_frame_routes', [
      'pages' => "/search\n/taxonomy/term/*",
      'bundles' => ['post'],
    ]);

    $this->goTo('/taxonomy/term/3');
    self::assertTrue($condition->evaluate(), 'A listing path matches.');
    $this->goTo('/user/login');
    self::assertFalse($condition->evaluate(), 'A Drupal-owned route matches neither.');
    $condition->setContextValue('node', Node::create(['type' => 'post', 'title' => 'A post']));
    self::assertTrue($condition->evaluate(), 'A node of a listed bundle matches.');
    $condition->setContextValue('node', Node::create(['type' => 'page', 'title' => 'A page']));
    self::assertFalse($condition->evaluate(), 'A node of another bundle does not.');
  }

  /**
   * Makes a request to a path the current one.
   */
  private function goTo(string $path): void {
    $request = Request::create($path);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
  }

}
