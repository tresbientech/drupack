<?php

declare(strict_types=1);

namespace Drupal\Tests\wordpal\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Template\Attribute;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\wordpal\Twig\WordpalExtension;
use Drupal\wordpal_field_access_test\Hook\FieldAccessTestHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the author and avatar builders against profile and field access.
 */
#[Group('wordpal')]
#[RunTestsInSeparateProcesses]
final class WordpalExtensionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'node',
    'comment',
    'text',
    'wordpal',
    'wordpal_field_access_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['user']);
    // Viewing a public file needs "access content", as core's image formatter.
    user_role_grant_permissions('anonymous', ['access content']);
  }

  /**
   * Tests the byline links the name only when the viewer can view the profile.
   */
  public function testAuthor(): void {
    $author = User::create(['name' => 'Morgan Reed', 'status' => 1]);
    $author->save();
    $extension = $this->container->get(WordpalExtension::class);
    $renderer = $this->container->get('renderer');

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $denied = $extension->author((int) $author->id(), TRUE);
    self::assertContains('user.permissions', $denied['#cache']['contexts']);
    self::assertStringNotContainsString('<a href', (string) $renderer->renderRoot($denied));

    $this->container->get('current_user')->setAccount($this->viewerWithProfileAccess());
    $allowed = $extension->author((int) $author->id(), TRUE);
    self::assertStringContainsString('<a href', (string) $renderer->renderRoot($allowed));
  }

  /**
   * Tests the Avatar block prints the bare image when access is denied.
   */
  public function testAvatar(): void {
    $author = $this->picturedAuthor();
    $extension = $this->container->get(WordpalExtension::class);
    $renderer = $this->container->get('renderer');

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $denied = $extension->avatar((int) $author->id(), 64, TRUE, '_self', new Attribute());
    self::assertContains('user.permissions', $denied['#cache']['contexts']);
    $deniedMarkup = (string) $renderer->renderRoot($denied);
    self::assertStringContainsString('<img', $deniedMarkup);
    self::assertStringNotContainsString('<a href', $deniedMarkup);

    $this->container->get('current_user')->setAccount($this->viewerWithProfileAccess());
    $allowed = $extension->avatar((int) $author->id(), 64, TRUE, '_self', new Attribute());
    self::assertStringContainsString('<a href', (string) $renderer->renderRoot($allowed));
  }

  /**
   * Tests both avatar builders print no image for a denied picture field.
   */
  public function testAvatarsLeaveOutDeniedPicture(): void {
    $author = $this->picturedAuthor();
    $this->container->get('state')->set(FieldAccessTestHooks::DENIED, ['user.user_picture']);
    $extension = $this->container->get(WordpalExtension::class);
    $renderer = $this->container->get('renderer');

    $avatar = $extension->avatar((int) $author->id(), 64, FALSE, '_self', new Attribute());
    $authorAvatar = $extension->authorAvatar((int) $author->id(), 48);

    foreach ([$avatar, $authorAvatar] as $build) {
      self::assertContains(FieldAccessTestHooks::TAG, $build['#cache']['tags']);
      self::assertStringNotContainsString('<img', (string) $renderer->renderRoot($build));
    }
  }

  /**
   * Tests both avatar builders print no image for a denied picture file.
   */
  public function testAvatarsLeaveOutDeniedPictureFile(): void {
    $author = $this->picturedAuthor();
    $this->container->get('state')->set(FieldAccessTestHooks::DENIED_FILES, [(int) $author->get('user_picture')->target_id]);
    $extension = $this->container->get(WordpalExtension::class);
    $renderer = $this->container->get('renderer');

    $avatar = $extension->avatar((int) $author->id(), 64, FALSE, '_self', new Attribute());
    $authorAvatar = $extension->authorAvatar((int) $author->id(), 48);

    foreach ([$avatar, $authorAvatar] as $build) {
      self::assertContains(FieldAccessTestHooks::FILE_TAG, $build['#cache']['tags']);
      self::assertStringNotContainsString('<img', (string) $renderer->renderRoot($build));
    }
  }

  /**
   * Tests the author avatar varies by both the picture file and the account.
   */
  public function testAuthorAvatarCarriesFileAndAccountTags(): void {
    $author = $this->picturedAuthor();

    $build = $this->container->get(WordpalExtension::class)->authorAvatar((int) $author->id(), 48);

    self::assertContains('file:' . $author->get('user_picture')->target_id, $build['#cache']['tags']);
    self::assertContains('user:' . $author->id(), $build['#cache']['tags']);
  }

  /**
   * Creates the user_picture field and an author with a picture.
   */
  private function picturedAuthor(): User {
    FieldStorageConfig::create([
      'field_name' => 'user_picture',
      'entity_type' => 'user',
      'type' => 'image',
      'settings' => ['target_type' => 'file', 'uri_scheme' => 'public'],
    ])->save();
    FieldConfig::create(['field_name' => 'user_picture', 'entity_type' => 'user', 'bundle' => 'user'])->save();
    $file = File::create(['uri' => 'public://morgan.png', 'status' => 1]);
    $file->save();
    $author = User::create(['name' => 'Morgan Reed', 'status' => 1]);
    $author->set('user_picture', ['target_id' => $file->id()]);
    $author->save();
    return $author;
  }

  /**
   * Creates an active user whose role holds 'access user profiles'.
   */
  private function viewerWithProfileAccess(): User {
    $role = Role::create(['id' => 'profile_viewer', 'label' => 'Profile viewer']);
    $role->grantPermission('access user profiles')->grantPermission('access content')->save();
    $viewer = User::create(['name' => 'viewer', 'status' => 1, 'roles' => [$role->id()]]);
    $viewer->save();
    return $viewer;
  }

}
