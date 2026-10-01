<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\views\Entity\View;
use Drupal\wordpal\Theme\ViewTags;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the container suggestion a converted Template's Views page adds.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class ContainerTemplateSuggestionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'wordpal', 'system', 'user', 'field', 'text', 'filter', 'comment', 'views',
  ];

  /**
   * A Template-tagged View's page display adds the container suggestion.
   *
   * Core wraps its render element in a container themed as a <div>, one
   * level above the Template's own <main>; the suggestion drops that
   * wrapper so the <main> stands alone, matching WordPress.
   */
  public function testTemplateViewAddsContainerSuggestion(): void {
    View::create(['id' => 'wordpal_template_test', 'tag' => ViewTags::TEMPLATE])->save();

    $suggestions = [];
    wordpal_theme_suggestions_container_alter($suggestions, [
      'element' => ['#view_id' => 'wordpal_template_test'],
    ]);

    self::assertSame(['container__wordpal_template'], $suggestions);
  }

  /**
   * A View without the Template tag leaves the default <div> in place.
   */
  public function testUntaggedViewAddsNoSuggestion(): void {
    View::create(['id' => 'wordpal_query_test', 'tag' => ViewTags::QUERY])->save();

    $suggestions = [];
    wordpal_theme_suggestions_container_alter($suggestions, [
      'element' => ['#view_id' => 'wordpal_query_test'],
    ]);

    self::assertSame([], $suggestions);
  }

  /**
   * A container render element outside any View adds no suggestion.
   */
  public function testNonViewContainerAddsNoSuggestion(): void {
    $suggestions = [];
    wordpal_theme_suggestions_container_alter($suggestions, ['element' => []]);

    self::assertSame([], $suggestions);
  }

}
