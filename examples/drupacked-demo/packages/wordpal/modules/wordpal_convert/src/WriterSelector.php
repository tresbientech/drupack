<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert;

/**
 * Picks the WriterInterface implementation one conversion's target names.
 *
 * `wordpal_canvas` and `wordpal_display_builder` each tag their writer
 * service `wordpal_convert.writer` with the target they serve, so both can
 * be enabled at once without one module's service definition silently
 * replacing the other's. A `service_collector` tag on this class calls
 * addWriter() once per tagged writer at container build time. ConvertCommands
 * checks the target's module is enabled before ConversionRunner ever asks
 * this class for its writer.
 */
final class WriterSelector {

  /**
   * Writers tagged `wordpal_convert.writer`, keyed by their `target` attribute.
   *
   * @var array<string, \Drupal\wordpal_convert\WriterInterface>
   */
  private array $writers = [];

  /**
   * Adds one tagged writer, called once per writer by the service_collector.
   */
  public function addWriter(WriterInterface $writer, string $target): void {
    $this->writers[$target] = $writer;
  }

  /**
   * Returns the writer tagged for a Builder target.
   *
   * @throws \LogicException
   *   When no enabled module tagged a writer for the target.
   */
  public function forTarget(string $target): WriterInterface {
    return $this->writers[$target] ?? throw new \LogicException("No writer is tagged for the \"$target\" target.");
  }

}
