<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Js;

use Drupal\wordpal_convert\Component\ComponentSet;
use Drupal\wordpal_convert\Theme\AttributeSourceInterface;
use Drupal\wordpal_convert\WordPress\WordPressRelease;

/**
 * Runs WordPress's own parser, against the pinned release, in Node.
 *
 * A Pattern reachable from more than one Template part is parsed once: the
 * result is a pure function of the pinned release and the markup itself.
 */
final class WordPressAttributeSource implements AttributeSourceInterface {

  /**
   * Parsed forests already returned, keyed by their markup blob.
   *
   * @var array[]
   */
  private array $cache = [];

  public function __construct(
    private readonly ComponentSet $componentSet,
    private readonly WordPressRelease $release,
    private readonly AttributeParser $parser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function forest(string $html): array {
    if (!isset($this->cache[$html])) {
      $root = $this->release->root($this->componentSet->wordPressVersion());
      [$this->cache[$html]] = $this->parser->parse($root, [$html]);
    }
    return $this->cache[$html];
  }

}
