<?php

declare(strict_types=1);

namespace Drupal\wordpal_canvas_runtime\Plugin\Adapter;

use Drupal\canvas\Plugin\Adapter\Adapter;
use Drupal\canvas\Plugin\Adapter\AdapterBase;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Wraps a single evaluated value into a one-item list.
 *
 * Canvas's expression evaluator resolves an "every delta" expression to an
 * array only for a multiple-cardinality field; for a single-cardinality
 * field the same expression collapses to a scalar, regardless of the
 * expression itself.
 *
 * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::doEvaluate()
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Ensure list'),
  inputs: [
    'value' => ['type' => ['string', 'array']],
  ],
  requiredInputs: ['value'],
  output: ['type' => 'array', 'items' => ['type' => 'string']],
)]
final class EnsureArrayAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'ensure_array';

  /**
   * The evaluated field value, before it is guaranteed to be a list.
   */
  protected string|array $value;

  /**
   * {@inheritdoc}
   */
  public function adapt(): EvaluationResult {
    return new EvaluationResult(is_array($this->value) ? array_values($this->value) : [$this->value]);
  }

}
