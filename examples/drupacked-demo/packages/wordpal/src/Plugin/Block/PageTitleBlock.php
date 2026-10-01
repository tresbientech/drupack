<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the page title as a WordPress Post Title block.
 *
 * A Drupal-owned route shows no post, so its frame's Post Title shows the
 * route's title.
 */
#[Block(id: 'wordpal_page_title', admin_label: new TranslatableMarkup('WordPal page title'))]
final class PageTitleBlock extends BlockBase implements ContainerFactoryPluginInterface, TitleBlockPluginInterface {

  /**
   * The Post Title component props this block takes, all but the post's own.
   */
  public const PROPS = [
    'level', 'align', 'anchor', 'css_class', 'font_size', 'line_height', 'font_family',
    'font_weight', 'font_style', 'text_transform', 'text_decoration', 'letter_spacing',
    'text_align', 'background_color', 'text_color', 'gradient', 'link_color',
    'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
    'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'self_stretch', 'flex_size',
    'border_radius', 'border_color', 'border_width', 'border_style',
    'border_top_width', 'border_top_color', 'border_right_width', 'border_right_color',
    'border_bottom_width', 'border_bottom_color', 'border_left_width', 'border_left_color',
  ];

  /**
   * The request attribute holding the main content's own title.
   *
   * A builder runtime whose page display variant sets no block title stores
   * it here. Core's HtmlRenderer prefers it over the route's title.
   */
  public const MAIN_CONTENT_TITLE = '_wordpal_main_content_title';

  /**
   * The title a page display variant sets, if it sets one.
   */
  protected mixed $title = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected TitleResolverInterface $titleResolver,
    protected RequestStack $requestStack,
    protected RouteMatchInterface $routeMatch,
    protected RendererInterface $renderer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('title_resolver'),
      $container->get('request_stack'),
      $container->get('current_route_match'),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['level' => 2] + array_fill_keys(self::PROPS, '') + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle($title): static {
    $this->title = $title;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $request = $this->requestStack->getCurrentRequest();
    $title = $this->title
      ?? $request->attributes->get(self::MAIN_CONTENT_TITLE)
      ?? $this->titleResolver->getTitle($request, $this->routeMatch->getRouteObject());
    $props = array_filter(
      array_intersect_key($this->configuration, array_flip(self::PROPS)),
      static fn (mixed $value): bool => $value !== '',
    );
    return [
      '#type' => 'component',
      '#component' => 'wordpal:post-title',
      // A display variant can build the block outside any render context.
      '#props' => ['title' => is_array($title) ? $this->renderer->renderInIsolation($title) : $title ?? ''] + $props,
      '#cache' => ['contexts' => ['route']],
    ];
  }

}
