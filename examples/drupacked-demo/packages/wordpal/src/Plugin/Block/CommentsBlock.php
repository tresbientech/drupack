<?php

declare(strict_types=1);

namespace Drupal\wordpal\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wordpal\Comment\CommentRuntime;
use Drupal\wordpal\Layout\LayoutRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a complete WordPress-compatible comment thread.
 */
#[Block(id: 'wordpal_comments', admin_label: new TranslatableMarkup('WordPal comments'))]
final class CommentsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The comments wrapper's props: its class and core/comments' supports.
   */
  public const ROOT_PROPS = [
    'css_class', 'align',
    'background_color', 'text_color', 'gradient', 'link_color', 'heading_color',
    'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
    'margin_top', 'margin_right', 'margin_bottom', 'margin_left',
    'font_size', 'line_height', 'font_family', 'font_weight', 'font_style',
    'text_transform', 'text_decoration', 'letter_spacing',
  ];

  /**
   * The comments title's props: its class and core/comments-title's supports.
   */
  public const TITLE_PROPS = [
    'css_class', 'align', 'background_color', 'text_color', 'gradient',
    'font_size', 'line_height', 'font_family', 'font_weight', 'font_style',
    'text_transform', 'text_decoration', 'letter_spacing',
    'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
    'margin_top', 'margin_right', 'margin_bottom', 'margin_left',
    'border_radius', 'border_color', 'border_width', 'border_style',
    'border_top_width', 'border_top_color', 'border_right_width', 'border_right_color',
    'border_bottom_width', 'border_bottom_color', 'border_left_width', 'border_left_color',
  ];

  /**
   * The comment content's props: its class and its block supports.
   */
  public const CONTENT_PROPS = [
    'css_class', 'text_align', 'background_color', 'text_color', 'gradient', 'link_color',
    'font_size', 'line_height', 'font_family', 'font_weight', 'font_style',
    'text_transform', 'text_decoration', 'letter_spacing',
    'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
    'border_radius', 'border_color', 'border_width', 'border_style',
    'border_top_width', 'border_top_color', 'border_right_width', 'border_right_color',
    'border_bottom_width', 'border_bottom_color', 'border_left_width', 'border_left_color',
  ];

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected CommentRuntime $runtime) {
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
      $container->get(CommentRuntime::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    // Author name and date share every typography and color support their
    // own block.json declares, alongside their own settings.
    $typography = [
      'font_size' => '',
      'font_family' => '',
      'font_style' => '',
      'font_weight' => '',
      'text_transform' => '',
      'text_decoration' => '',
      'letter_spacing' => '',
      'line_height' => '',
      'text_color' => '',
      'background_color' => '',
      'gradient' => '',
      'link_color' => '',
    ];
    return array_fill_keys(self::ROOT_PROPS, '') + [
      'field_name' => '',
      'comments_title' => ['level' => 2, 'show_post_title' => TRUE] + array_fill_keys(self::TITLE_PROPS, ''),
      'comment_template' => [],
      'avatar' => ['size' => 40, 'is_link' => FALSE, 'link_target' => '_self'],
      'comment_author_name' => ['is_link' => FALSE, 'link_target' => '', 'text_align' => ''] + $typography,
      'comment_date' => ['format' => CommentRuntime::DEFAULT_DATE_FORMAT, 'is_link' => TRUE] + $typography,
      'comment_content' => array_fill_keys(self::CONTENT_PROPS, ''),
      'comment_edit_link' => ['label' => 'Edit', 'text_align' => ''] + $typography,
      'comment_reply_link' => ['label' => 'Reply', 'text_align' => ''] + $typography,
      'comments_pagination' => [
        'layout_type' => 'flex',
        'justify_content' => 'space-between',
        'orientation' => 'horizontal',
        'flex_wrap' => 'wrap',
        'block_gap' => '',
      ],
      'comments_pagination_previous' => ['label' => 'Older Comments'],
      'comments_pagination_next' => ['label' => 'Newer Comments'],
      'post_comments_form' => ['padding_top' => '', 'padding_bottom' => ''],
      'layout' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if ($this->configuration['field_name'] === '') {
      throw new \UnexpectedValueException('Comments require a mapped comment field.');
    }
    $state = $this->runtime->build($this->configuration['field_name'], $this->configuration);
    // WordPress prints nothing when comments are closed and there are none.
    if (!$state['open'] && $state['count'] === 0) {
      return ['#cache' => $state['#cache']];
    }
    $pagination = [];
    if ($state['pager']) {
      $pagination = $this->runtime->fragment('wordpal_comments_pagination', [
        'pager' => LayoutRenderer::build(
          $this->runtime->markerChildren($this->configuration['layout'], 'comments-pagination'),
          [
            'comments-pagination-previous' => $this->runtime->pagerDirection('previous', $this->configuration['comments_pagination_previous']['label']),
            'comments-pagination-next' => $this->runtime->pagerDirection('next', $this->configuration['comments_pagination_next']['label']),
            'comments-pagination-numbers' => $this->runtime->pagerNumbers(),
          ],
        ),
        'settings' => $this->configuration['comments_pagination'],
      ]);
    }
    $content = LayoutRenderer::build($this->configuration['layout'], [
      'comments-title' => $this->runtime->fragment('wordpal_comments_title', [
        'node' => $state['node'],
        'count' => $state['count'],
        'settings' => $this->configuration['comments_title'],
      ]),
      'comment-template' => $this->runtime->fragment('wordpal_comment_template', ['comments' => $state['comments']]),
      'comments-pagination' => $pagination,
      'post-comments-form' => $this->runtime->fragment('wordpal_post_comments_form', [
        'comment_form' => $state['form'],
        'settings' => $this->configuration['post_comments_form'],
      ]),
    ]);
    return [
      '#theme' => 'wordpal_comments',
      '#settings' => $this->configuration,
      '#content' => $content,
      '#cache' => $state['#cache'],
    ];
  }

}
