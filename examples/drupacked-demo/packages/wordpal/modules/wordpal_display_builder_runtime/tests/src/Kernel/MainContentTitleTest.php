<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal_display_builder_runtime\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\wordpal\Plugin\Block\PageTitleBlock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the main content's own title is recorded for the page title block.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class MainContentTitleTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = ['system', 'user', 'wordpal_display_builder_runtime'];

  /**
   * Tests the main content's title reaches the request only when it has one.
   */
  public function testRecordsTheMainContentTitle(): void {
    $request = Request::create('/user/logout/confirm');
    $event = new ViewEvent($this->container->get('kernel'), $request, HttpKernelInterface::MAIN_REQUEST, ['#title' => 'Log out?']);
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::VIEW);

    self::assertSame('Log out?', $request->attributes->get(PageTitleBlock::MAIN_CONTENT_TITLE));

    $request = Request::create('/user/login');
    $event = new ViewEvent($this->container->get('kernel'), $request, HttpKernelInterface::MAIN_REQUEST, ['#markup' => 'Content']);
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::VIEW);

    self::assertFalse($request->attributes->has(PageTitleBlock::MAIN_CONTENT_TITLE), 'Main content with no title of its own leaves the route title.');
  }

}
