<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\wordpal\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests mapped categories, search, and author-biography runtime blocks.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class DynamicContentBlockTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'filter',
    'text',
    'node',
    'taxonomy',
    'comment',
    'wordpal',
  ];

  /**
   * Tests real values, request semantics, access, and cacheability.
   */
  public function testDynamicContentBlocks(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'taxonomy']);
    $theme = $this->container->get('theme.manager')->getActiveTheme()->getName();
    $this->container->get('config.factory')->getEditable(ThemeSettings::configName($theme))->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    $term = Term::create(['vid' => 'topics', 'name' => 'Travel']);
    $term->save();
    $filteredParent = Term::create(['vid' => 'topics', 'name' => 'Filtered parent']);
    $filteredParent->save();
    $visibleChild = Term::create([
      'vid' => 'topics',
      'name' => 'Visible child',
      'parent' => $filteredParent->id(),
    ]);
    $visibleChild->save();
    $author = User::create(['name' => 'writer', 'status' => 1]);
    $author->save();
    $this->container->get('current_user')->setAccount($author);
    $this->createBiographyField();
    $author = User::load($author->id());
    $author->set('field_biography', ['value' => '<p>Writes about places.</p>', 'format' => 'plain_text']);
    $author->set('field_biography_plain', 'Plain biography')->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Mapped content',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();
    $this->container->get('database')->insert('taxonomy_index')->fields([
      'nid' => $node->id(),
      'tid' => $visibleChild->id(),
      'status' => 1,
      'sticky' => 0,
      'created' => $node->getCreatedTime(),
    ])->execute();

    $manager = $this->container->get('plugin.manager.block');
    self::assertSame(['#cache' => ['tags' => ['taxonomy_term_list']]], $manager->createInstance('wordpal_categories')->build());
    $categories = $manager->createInstance('wordpal_categories', [
      'vocabulary' => 'topics',
      'show_count' => TRUE,
      'show_hierarchy' => FALSE,
      'show_empty' => TRUE,
      'supports' => ['font_size' => 'small'],
    ]);
    $categoriesBuild = $categories->build();
    self::assertContains('taxonomy_term_list:topics', $categoriesBuild['#cache']['tags']);
    self::assertContains('user.permissions', $categoriesBuild['#cache']['contexts']);
    $categoriesMarkup = (string) $this->container->get('renderer')->renderRoot($categoriesBuild);
    self::assertStringContainsString('wp-block-categories-taxonomy-category', $categoriesMarkup);
    self::assertStringContainsString('has-small-font-size', $categoriesMarkup);
    self::assertStringContainsString('>Travel</a> (0)', $categoriesMarkup);
    $hierarchy = $manager->createInstance('wordpal_categories', [
      'vocabulary' => 'topics',
      'show_count' => FALSE,
      'show_hierarchy' => TRUE,
      'show_empty' => FALSE,
    ]);
    $hierarchyBuild = $hierarchy->build();
    $hierarchyMarkup = (string) $this->container->get('renderer')->renderRoot($hierarchyBuild);
    self::assertStringContainsString('>Visible child</a>', $hierarchyMarkup);
    self::assertStringNotContainsString('Filtered parent', $hierarchyMarkup);

    self::assertSame([], $manager->createInstance('wordpal_search')->build());
    $this->container->get('request_stack')->getCurrentRequest()->query->set('keys', 'mapped phrase');
    $search = $manager->createInstance('wordpal_search', [
      'path' => '/search/node',
      'parameter' => 'keys',
      'label' => 'Find content',
      'show_label' => FALSE,
      'placeholder' => 'Search...',
      'button_text' => 'Find',
      'button_position' => 'button-outside',
      'button_use_icon' => FALSE,
      'width' => '100%',
    ]);
    $searchBuild = $search->build();
    self::assertContains('url.query_args:keys', $searchBuild['#cache']['contexts']);
    $searchMarkup = (string) $this->container->get('renderer')->renderRoot($searchBuild);
    self::assertStringContainsString('method="get" action="/search/node"', $searchMarkup);
    self::assertStringContainsString('name="keys"', $searchMarkup);
    self::assertStringContainsString('value="mapped phrase"', $searchMarkup);
    self::assertStringContainsString('wp-block-search__button-outside', $searchMarkup);
    self::assertStringContainsString('<div class="wp-block-search__inside-wrapper" style="width:100%">', $searchMarkup);
    self::assertStringContainsString('wp-block-search__label screen-reader-text', $searchMarkup, 'show_label:false hides a non-empty label from sight, not from screen readers.');
    $maliciousConfig = $search->getConfiguration();
    $maliciousConfig['width'] = '100%;color:red';
    $maliciousSearch = $manager->createInstance('wordpal_search', $maliciousConfig);
    try {
      $maliciousSearch->build();
      self::fail('Expected an UnexpectedValueException for a hostile search width.');
    }
    catch (\UnexpectedValueException $exception) {
      self::assertStringContainsString('100%;color:red', $exception->getMessage());
    }
    $noButtonConfig = ['button_position' => 'no-button', 'button_use_icon' => TRUE] + $search->getConfiguration();
    $noButtonBuild = $manager->createInstance('wordpal_search', $noButtonConfig)->build();
    self::assertStringNotContainsString('<button', (string) $this->container->get('renderer')->renderRoot($noButtonBuild), 'search.php prints no button for no-button.');
    $iconConfig = $search->getConfiguration();
    $iconConfig['button_use_icon'] = TRUE;
    $iconConfig['supports'] = ['align' => 'center'];
    $iconBuild = $manager->createInstance('wordpal_search', $iconConfig)->build();
    $iconMarkup = (string) $this->container->get('renderer')->renderRoot($iconBuild);
    self::assertStringContainsString('aligncenter', $iconMarkup);
    self::assertStringContainsString('wp-block-search__button has-icon wp-element-button', $iconMarkup);
    // The icon WordPress 7.1.2 search.php prints, with no aria attributes.
    self::assertStringContainsString('<svg class="search-icon" viewBox="0 0 24 24" width="24" height="24"><path d="M13 5c-3.3', $iconMarkup);
    // search.php prints typography on the label, input and button, keeps the
    // preset class off a hidden label, and keeps text decoration off the
    // input.
    $typographyConfig = $search->getConfiguration();
    $typographyConfig['supports'] = [
      'font_size' => 'small',
      'line_height' => '1.8',
      'text_decoration' => 'underline',
      'margin_top' => '12px',
    ];
    $typographyDocument = new \DOMDocument();
    @$typographyDocument->loadHTML('<body>' . $this->container->get('renderer')->renderRoot($manager->createInstance('wordpal_search', $typographyConfig)->build()) . '</body>');
    $typographyXpath = new \DOMXPath($typographyDocument);
    $element = static fn (string $query): \DOMElement => $typographyXpath->query($query)->item(0);
    self::assertSame('wp-block-search__label screen-reader-text', $element('//label')->getAttribute('class'));
    self::assertSame('line-height:1.8', $element('//label')->getAttribute('style'), 'A hidden label takes no text decoration.');
    self::assertSame('wp-block-search__input has-small-font-size', $element('//input')->getAttribute('class'));
    self::assertSame('line-height:1.8', $element('//input')->getAttribute('style'));
    self::assertSame('line-height:1.8;text-decoration:underline', $element('//button')->getAttribute('style'));
    self::assertSame('margin-top:12px', $element('//form')->getAttribute('style'));
    $this->container->get('request_stack')->getCurrentRequest()->query->set('keys', ['nested']);
    $arrayQueryBuild = $search->build();
    $arrayQueryMarkup = (string) $this->container->get('renderer')->renderRoot($arrayQueryBuild);
    self::assertStringContainsString('value=""', $arrayQueryMarkup);

    // search.php hides the label with no visible text to show, even when
    // show_label itself stays TRUE.
    $emptyLabelConfig = $search->getConfiguration();
    $emptyLabelConfig['label'] = '';
    $emptyLabelConfig['show_label'] = TRUE;
    $emptyLabelBuild = $manager->createInstance('wordpal_search', $emptyLabelConfig)->build();
    $emptyLabelMarkup = (string) $this->container->get('renderer')->renderRoot($emptyLabelBuild);
    self::assertStringContainsString('wp-block-search__label screen-reader-text', $emptyLabelMarkup);

    $biography = $manager->createInstance('wordpal_post_author_biography', [
      'field_name' => 'field_biography',
      'supports' => ['font_size' => 'small'],
    ]);
    self::assertSame(['#cache' => ['contexts' => ['route']]], $biography->build());
    $biography->setContext('node', new Context(new EntityContextDefinition('entity:node', required: FALSE), $node));
    $biographyBuild = $biography->build();
    self::assertContains('node:' . $node->id(), $biographyBuild['#cache']['tags']);
    self::assertContains('user.permissions', $biographyBuild['#cache']['contexts']);
    $biographyMarkup = (string) $this->container->get('renderer')->renderRoot($biographyBuild);
    self::assertStringContainsString('wp-block-post-author-biography has-small-font-size', $biographyMarkup);
    self::assertStringContainsString('&lt;p&gt;Writes about places.&lt;/p&gt;', $biographyMarkup);
    $plainBiography = $manager->createInstance('wordpal_post_author_biography', [
      'field_name' => 'field_biography_plain',
    ]);
    $plainBiography->setContext('node', new Context(new EntityContextDefinition('entity:node', required: FALSE), $node));
    $plainBuild = $plainBiography->build();
    self::assertSame('Plain biography', $plainBuild['#biography']['#plain_text']);

    $node->setUnpublished()->save();
    $anonymous = User::getAnonymousUser();
    $this->container->get('current_user')->setAccount($anonymous);
    $denied = $biography->build();
    self::assertArrayNotHasKey('#theme', $denied);
    self::assertContains('user.node_grants:view', $denied['#cache']['contexts']);
  }

  /**
   * Tests that a term's count excludes nodes the viewer cannot access.
   *
   * The node_access_test module grants view access only to a node's
   * author and to users with 'node test view'. Anonymous, granted only
   * 'access content' so the term itself stays visible, is neither, so the
   * per-node grant layer alone must drop the count to zero.
   */
  public function testCategoryCountsRespectNodeAccess(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node', 'taxonomy']);
    $this->enableModules(['node_access_test']);
    $theme = $this->container->get('theme.manager')->getActiveTheme()->getName();
    $this->container->get('config.factory')->getEditable(ThemeSettings::configName($theme))->save();
    user_role_grant_permissions('anonymous', ['access content']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    $term = Term::create(['vid' => 'topics', 'name' => 'Travel']);
    $term->save();
    $author = User::create(['name' => 'writer', 'status' => 1]);
    $author->save();
    $node = Node::create(['type' => 'article', 'title' => 'Restricted content', 'uid' => $author->id(), 'status' => 1]);
    $node->save();
    $this->container->get('database')->insert('taxonomy_index')->fields([
      'nid' => $node->id(),
      'tid' => $term->id(),
      'status' => 1,
      'sticky' => 0,
      'created' => $node->getCreatedTime(),
    ])->execute();

    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    $manager = $this->container->get('plugin.manager.block');
    $categories = $manager->createInstance('wordpal_categories', [
      'vocabulary' => 'topics',
      'show_count' => TRUE,
      'show_empty' => TRUE,
    ]);
    $build = $categories->build();
    $markup = (string) $this->container->get('renderer')->renderRoot($build);
    self::assertStringContainsString('>Travel</a> (0)', $markup);
  }

  /**
   * Creates the mapped long-text biography field.
   */
  private function createBiographyField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_biography',
      'entity_type' => 'user',
      'type' => 'text_long',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_biography',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Biography',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_biography_plain',
      'entity_type' => 'user',
      'type' => 'string_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_biography_plain',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Plain biography',
    ])->save();
  }

}
