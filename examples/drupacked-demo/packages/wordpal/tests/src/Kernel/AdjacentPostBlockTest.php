<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeAccessRebuild;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\node\Traits\NodeAccessTrait;
use Drupal\user\Entity\User;
use Drupal\wordpal\Plugin\Block\AdjacentPostBlock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the adjacent post block with real node access and rendering.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class AdjacentPostBlockTest extends KernelTestBase {

  use NodeAccessTrait;

  /**
   * Modules needed to query and render adjacent nodes.
   *
   * @var string[]
   */
  protected static $modules = ['system', 'user', 'field', 'file', 'node', 'comment', 'text', 'wordpal'];

  /**
   * Tests direction, WordPress markup, and cacheability metadata.
   */
  public function testPreviousPostMarkupAndCacheability(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $previous = Node::create(['type' => 'article', 'title' => 'Previous post', 'status' => 1, 'created' => 100]);
    $previous->save();
    // Moves the revision ID away from the node ID.
    $previous->setNewRevision();
    $previous->save();
    $current = Node::create(['type' => 'article', 'title' => 'Current post', 'status' => 1, 'created' => 200]);
    $current->save();
    Node::create(['type' => 'article', 'title' => 'Next post', 'status' => 1, 'created' => 300])->save();

    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getParameter')->with('node')->willReturn($current);
    $block = new AdjacentPostBlock([
      'direction' => 'previous',
      'label' => 'Earlier',
      'show_title' => TRUE,
      'link_label' => TRUE,
      'arrow' => 'chevron',
      'supports' => ['text_align' => 'right', 'font_weight' => '600'],
    ], 'wordpal_adjacent_post', ['provider' => 'wordpal'], $this->container->get('entity_type.manager'), $routeMatch);
    $build = $block->build();

    self::assertContains('route', $build['#cache']['contexts']);
    self::assertContains('user.node_grants:view', $build['#cache']['contexts']);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
    self::assertContains('node_list:article', $build['#cache']['tags']);
    self::assertContains('node:' . $current->id(), $build['#cache']['tags']);
    self::assertContains('node:' . $previous->id(), $build['#cache']['tags']);

    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    self::assertStringContainsString('post-navigation-link-previous wp-block-post-navigation-link has-text-align-right', $markup);
    self::assertStringContainsString('font-weight:600', $markup);
    self::assertStringContainsString('is-arrow-chevron', $markup);
    self::assertStringContainsString('<span class="post-navigation-link__label">Earlier</span>', $markup);
    self::assertStringContainsString('<span class="post-navigation-link__title">Previous post</span>', $markup);
    self::assertStringContainsString('rel="prev"', $markup);

    $block = new AdjacentPostBlock([
      'direction' => 'previous',
      'label' => '',
      'show_title' => TRUE,
      'link_label' => FALSE,
      'arrow' => 'arrow',
    ], 'wordpal_adjacent_post', ['provider' => 'wordpal'], $this->container->get('entity_type.manager'), $routeMatch);
    $build = $block->build();
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    self::assertMatchesRegularExpression('#<a href="[^"]+" rel="prev">Previous post</a>#', $markup, 'A title with no linked label is the bare link text.');

    $listing = $this->createStub(RouteMatchInterface::class);
    $listing->method('getParameter')->willReturn(NULL);
    $block = new AdjacentPostBlock($block->getConfiguration(), 'wordpal_adjacent_post', ['provider' => 'wordpal'], $this->container->get('entity_type.manager'), $listing);
    $build = $block->build();
    self::assertSame('', (string) $this->container->get('renderer')->renderRoot($build), 'A page with no node prints nothing, as WordPress does on a listing.');
  }

  /**
   * Tests a neighbor denied by node grants is skipped, with cacheability.
   */
  public function testAdjacentPostBlock(): void {
    $this->enableModules(['node_access_test']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node']);
    $type = NodeType::create(['type' => 'article', 'name' => 'Article']);
    $type->save();
    // node_access_test denies a node only once it carries this field.
    $this->addPrivateField($type);
    \Drupal::state()->set('node_access_test.private', TRUE);
    $this->container->get(NodeAccessRebuild::class)->rebuild();
    // A distinct owner keeps the anonymous viewer out of the author realm.
    $owner = User::create(['name' => 'Nodes author', 'status' => 1]);
    $owner->save();
    $older = Node::create([
      'type' => 'article',
      'title' => 'Older post',
      'status' => 1,
      'created' => 100,
      'uid' => $owner->id(),
    ]);
    $older->save();
    Node::create([
      'type' => 'article',
      'title' => 'Hidden neighbor',
      'status' => 1,
      'created' => 150,
      'uid' => $owner->id(),
      'private' => 1,
    ])->save();
    $current = Node::create([
      'type' => 'article',
      'title' => 'Current post',
      'status' => 1,
      'created' => 200,
      'uid' => $owner->id(),
    ]);
    $current->save();
    $this->container->get(NodeAccessRebuild::class)->rebuild();

    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getParameter')->with('node')->willReturn($current);
    $block = new AdjacentPostBlock([
      'direction' => 'previous',
      'label' => '',
      'show_title' => TRUE,
      'link_label' => FALSE,
      'arrow' => 'none',
    ], 'wordpal_adjacent_post', ['provider' => 'wordpal'], $this->container->get('entity_type.manager'), $routeMatch);
    $build = $block->build();
    self::assertContains('user.node_grants:view', $build['#cache']['contexts']);
    self::assertContains('user.permissions', $build['#cache']['contexts']);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    self::assertStringContainsString('Older post', $markup);
    self::assertStringNotContainsString('Hidden neighbor', $markup);
  }

  /**
   * Tests that only this plugin receives the wrapper-free block template.
   */
  public function testBlockThemeSuggestion(): void {
    $suggestions = [];
    wordpal_theme_suggestions_block_alter($suggestions, ['elements' => ['#plugin_id' => 'wordpal_adjacent_post']]);
    self::assertSame(['block__wordpal_runtime'], $suggestions);

    $suggestions = [];
    wordpal_theme_suggestions_block_alter($suggestions, ['elements' => ['#plugin_id' => 'system_branding_block']]);
    self::assertSame([], $suggestions);
  }

}
