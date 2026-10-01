<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\Js;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Parses raw WordPress block markup with a release's own editor scripts.
 *
 * WordPress's own `parse()` reads each block's saved markup the way its
 * editor does: deprecated saves migrate, custom class names and anchors come
 * off the saved root element, and a block that no longer matches its own
 * `save()` output comes back invalid. This runs that parser in Node, so
 * wordpal's own conversion does not re-derive what WordPress already knows.
 *
 * One `node parse.cjs` process serves every call this object gets, so Node
 * starts and WordPress's bundles load once. The process starts on first use
 * and stops on destruct; parse.cjs speaks one JSON request and one JSON
 * response per line.
 */
final class AttributeParser {

  /**
   * The running parse.cjs process, or NULL before the first call.
   */
  private ?Process $process = NULL;

  /**
   * The release root the running process loaded, or NULL before the first call.
   */
  private ?string $root = NULL;

  /**
   * The running process's stdin, open for as long as $process runs.
   */
  private ?InputStream $input = NULL;

  /**
   * The running process's stdout, as an incremental chunk iterator.
   */
  private ?\Generator $chunks = NULL;

  /**
   * Stdout bytes read but not yet split into a whole line.
   */
  private string $buffer = '';

  /**
   * The process's most recent stderr chunk, for a process-exited exception.
   *
   * The chunk iterator clears each stream's buffer as it yields, so stderr
   * is kept here as it is seen rather than read back from the process after
   * it has already exited.
   */
  private string $stderr = '';

  /**
   * Whether $chunks currently sits on a chunk already folded into $buffer.
   */
  private bool $chunkConsumed = FALSE;

  /**
   * Returns WordPress's own parsed forest for each block markup blob.
   *
   * @param string $root
   *   The root of an unpacked WordPress release.
   * @param string[] $blobs
   *   Complete saved block markup documents, such as a Template's, a
   *   Template part's, or a Pattern's own content.
   *
   * @return array[]
   *   One parsed forest per blob, in the given order. Each forest is a list
   *   of nested arrays shaped {name: string, attributes: array, isValid:
   *   bool, innerBlocks: array[]}, in document order. A purely whitespace
   *   freeform segment is absent, as `wp.blocks.parse()` itself omits it.
   *
   * @throws \UnexpectedValueException
   *   When parse.cjs's response does not decode to that shape.
   * @throws \RuntimeException
   *   When the parse.cjs process exits before answering.
   */
  public function parse(string $root, array $blobs): array {
    $this->ensureProcess($root);
    $this->input->write(json_encode(['blobs' => $blobs], JSON_THROW_ON_ERROR) . "\n");
    $forests = json_decode($this->readLine(), TRUE, flags: JSON_THROW_ON_ERROR);
    if (!is_array($forests) || count($forests) !== count($blobs)) {
      throw new \UnexpectedValueException('parse.cjs returned ' . count((array) $forests) . ' forests for ' . count($blobs) . ' blobs.');
    }
    array_walk($forests, [self::class, 'assertForest']);
    return $forests;
  }

  /**
   * Starts the parse.cjs process, if none runs yet for this release root.
   */
  private function ensureProcess(string $root): void {
    if ($this->process !== NULL && $this->root === $root && $this->process->isRunning()) {
      return;
    }
    $this->process?->stop();
    $this->input = new InputStream();
    $this->process = new Process(
      ['node', __DIR__ . '/parse.cjs'],
      env: ['NODE_PATH' => Jsdom::nodePath()],
      input: $this->input,
      timeout: NULL,
    );
    $this->process->start();
    $this->chunks = $this->process->getIterator();
    $this->buffer = '';
    $this->stderr = '';
    $this->chunkConsumed = FALSE;
    $this->root = $root;
    $this->input->write(json_encode(['scripts' => WordPressScripts::forRoot($root)], JSON_THROW_ON_ERROR) . "\n");
  }

  /**
   * Blocks for one newline-delimited line of the running process's stdout.
   *
   * $chunks is a live generator over the running process, so advancing past
   * a chunk blocks until more output exists. Once $buffer holds a whole
   * line, this stops pulling and leaves $chunks sitting on the chunk that
   * completed it, to advance past only when a further line is asked for.
   */
  private function readLine(): string {
    while (!str_contains($this->buffer, "\n")) {
      if ($this->chunkConsumed) {
        $this->chunks->next();
      }
      if (!$this->chunks->valid()) {
        throw new \RuntimeException('The parse.cjs process exited' . ($this->stderr === '' ? '.' : ": {$this->stderr}"));
      }
      $this->chunkConsumed = TRUE;
      if ($this->chunks->key() === Process::OUT) {
        $this->buffer .= $this->chunks->current();
      }
      else {
        $this->stderr = trim($this->chunks->current());
      }
    }
    [$line, $this->buffer] = explode("\n", $this->buffer, 2);
    return $line;
  }

  /**
   * Stops the parse.cjs process, so it does not outlive this object.
   */
  public function __destruct() {
    // phpcs:ignore Drupal.Semantics.FunctionAlias.FunctionAlias -- InputStream::close(), not dir's alias.
    $this->input?->close();
    $this->process?->stop();
  }

  /**
   * Validates one parsed forest's shape, recursively.
   *
   * `parse.cjs` runs in a separate process: its output is external input,
   * not data this codebase controls, so the shape is checked at this
   * boundary rather than trusted by callers.
   */
  private static function assertForest(mixed $forest): void {
    if (!is_array($forest)) {
      throw new \UnexpectedValueException('parse.cjs returned a non-array forest.');
    }
    foreach ($forest as $block) {
      if (!is_array($block)
        || !is_string($block['name'] ?? NULL)
        || !is_array($block['attributes'] ?? NULL)
        || !is_bool($block['isValid'] ?? NULL)
        || !is_array($block['innerBlocks'] ?? NULL)
      ) {
        throw new \UnexpectedValueException('parse.cjs returned a block without a name, attributes, isValid or innerBlocks.');
      }
      self::assertForest($block['innerBlocks']);
    }
  }

}
