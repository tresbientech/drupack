<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\wordpal\Traits\ThemeSettingsTrait;
use Drupal\wordpal\Plugin\Block\PageTitleBlock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Tests the page title block and Post Content's page content slot.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class DrupalRouteBlocksTest extends KernelTestBase {

  use ThemeSettingsTrait;

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'system', 'user', 'field', 'file', 'image', 'filter', 'text', 'node', 'taxonomy', 'comment', 'wordpal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('theme.manager')->resetActiveTheme();
    $this->writeThemeSettings(['block_gap_support' => TRUE, 'use_root_padding_aware_alignments' => TRUE]);
  }

  /**
   * Tests the block shows the title a page variant sets, as Post Title.
   */
  public function testPageTitleShowsTheSetTitle(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('wordpal_page_title', [
      'level' => 1,
      'text_align' => 'center',
    ]);
    $block->setTitle('Log in');

    $html = $this->renderBuild($block->build());

    self::assertMatchesRegularExpression('#^<h1[^>]*class="[^"]*wp-block-post-title[^"]*has-text-align-center[^"]*"[^>]*>Log in</h1>$#', trim($html));

    // A user page's title is a render array.
    $block->setTitle(['#markup' => '<em>admin</em>']);
    self::assertStringContainsString('><em>admin</em></h1>', $this->renderBuild($block->build()));
  }

  /**
   * Tests the block resolves the route's title when none is set.
   */
  public function testPageTitleResolvesTheRouteTitle(): void {
    $request = Request::create('/user/password');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/user/password', ['_title' => 'Reset your password']));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'user.pass');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $html = $this->renderBuild($this->container->get('plugin.manager.block')->createInstance('wordpal_page_title', [])->build());

    self::assertStringContainsString('<h2', $html, 'Post Title defaults to level 2.');
    self::assertStringContainsString('>Reset your password</h2>', $html);

    // A confirm form sets its title on the main content, which wins.
    $request->attributes->set(PageTitleBlock::MAIN_CONTENT_TITLE, 'Are you sure you want to log out?');
    $html = $this->renderBuild($this->container->get('plugin.manager.block')->createInstance('wordpal_page_title', [])->build());
    self::assertStringContainsString('>Are you sure you want to log out?</h2>', $html);
  }

  /**
   * Tests Post Content wraps the page content slot as it wraps a post body.
   */
  public function testPostContentHoldsThePageContent(): void {
    $html = $this->renderBuild([
      '#type' => 'component',
      '#component' => 'wordpal:post-content',
      '#props' => ['layout_type' => 'constrained'],
      '#slots' => ['page_content' => ['#markup' => '<p>Log in form</p>']],
    ]);

    self::assertMatchesRegularExpression('#^<div[^>]*class="[^"]*entry-content wp-block-post-content[^"]*is-layout-constrained[^"]*"[^>]*><p>Log in form</p></div>$#', trim($html));
  }

  /**
   * Returns a render array's markup.
   */
  private function renderBuild(array $build): string {
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

}
