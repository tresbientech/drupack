<?php

declare(strict_types=1);

namespace Drupal\wordpal_convert\WordPress;

/**
 * Class WpBlockParser
 *
 * Parses a document and constructs a list of parsed block objects
 *
 * @since 5.0.0
 * @since 4.0.0 returns arrays not objects, all attributes are arrays
 */
class WpBlockParser {

  /**
   * Input document being parsed
   *
   * @var string
   * @since 5.0.0
   * @example "Pre-text\n<!-- wp:paragraph -->This is inside a block!<!-- /wp:paragraph -->"
   *
   */
  public $document;

  /**
   * Tracks parsing progress through document
   *
   * @var int
   * @since 5.0.0
   */
  public $offset;

  /**
   * List of parsed blocks
   *
   * @var WpBlockParserBlock[]
   * @since 5.0.0
   */
  public $output;

  /**
   * Stack of partially-parsed structures in memory during parse
   *
   * @var WpBlockParserFrame[]
   * @since 5.0.0
   */
  public $stack;

  /**
   * Parses a document and returns a list of block structures
   *
   * When encountering an invalid parse will return a best-effort
   * parse. In contrast to the specification parser this does not
   * return an error on invalid inputs.
   *
   * @param string $document Input document being parsed.
   *
   * @return array[]
   * @since 5.0.0
   *
   */
  public function parse($document) {
    $this->document = $document;
    $this->offset = 0;
    $this->output = [];
    $this->stack = [];

    while ($this->proceed()) {
      continue;
    }

    return $this->output;
  }

  /**
   * Processes the next token from the input document
   * and returns whether to proceed eating more tokens
   *
   * This is the "next step" function that essentially
   * takes a token as its input and decides what to do
   * with that token before descending deeper into a
   * nested block tree or continuing along the document
   * or breaking out of a level of nesting.
   *
   * @return bool
   * @since 5.0.0
   * @internal
   */
  public function proceed() {
    $next_token = $this->next_token();
    [
      $token_type,
      $block_name,
      $attrs,
      $start_offset,
      $token_length
    ] = $next_token;
    $stack_depth = count($this->stack);

    // we may have some HTML soup before the next block.
    $leading_html_start = $start_offset > $this->offset ? $this->offset : NULL;

    switch ($token_type) {
      case 'no-more-tokens':
        // if not in a block then flush output.
        if (0 === $stack_depth) {
          $this->add_freeform();
          return FALSE;
        }

        /*
         * Otherwise we have a problem
         * This is an error
         *
         * we have options
         * - treat it all as freeform text
         * - assume an implicit closer (easiest when not nesting)
         */

        // for the easy case we'll assume an implicit closer.
        if (1 === $stack_depth) {
          $this->add_block_from_stack();
          return FALSE;
        }

        /*
         * for the nested case where it's more difficult we'll
         * have to assume that multiple closers are missing
         * and so we'll collapse the whole stack piecewise
         */
        while (0 < count($this->stack)) {
          $this->add_block_from_stack();
        }
        return FALSE;

      case 'void-block':
        /*
         * easy case is if we stumbled upon a void block
         * in the top-level of the document
         */
        if (0 === $stack_depth) {
          if (isset($leading_html_start)) {
            $this->output[] = (array) $this->freeform(
              substr(
                $this->document,
                $leading_html_start,
                $start_offset - $leading_html_start
              )
            );
          }

          $this->output[] = (array) new WpBlockParserBlock($block_name, $attrs, [], '', []);
          $this->offset = $start_offset + $token_length;
          return TRUE;
        }

        // otherwise we found an inner block.
        $this->add_inner_block(
          new WpBlockParserBlock($block_name, $attrs, [], '', []),
          $start_offset,
          $token_length
        );
        $this->offset = $start_offset + $token_length;
        return TRUE;

      case 'block-opener':
        // track all newly-opened blocks on the stack.
        array_push(
          $this->stack,
          new WpBlockParserFrame(
            new WpBlockParserBlock($block_name, $attrs, [], '', []),
            $start_offset,
            $token_length,
            $start_offset + $token_length,
            $leading_html_start
          )
        );
        $this->offset = $start_offset + $token_length;
        return TRUE;

      case 'block-closer':
        /*
         * if we're missing an opener we're in trouble
         * This is an error
         */
        if (0 === $stack_depth) {
          /*
           * we have options
           * - assume an implicit opener
           * - assume _this_ is the opener
           * - give up and close out the document
           */
          $this->add_freeform();
          return FALSE;
        }

        // if we're not nesting then this is easy - close the block.
        if (1 === $stack_depth) {
          $this->add_block_from_stack($start_offset);
          $this->offset = $start_offset + $token_length;
          return TRUE;
        }

        /*
         * otherwise we're nested and we have to close out the current
         * block and add it as a new innerBlock to the parent
         */
        $stack_top = array_pop($this->stack);
        $html = substr($this->document, $stack_top->prev_offset, $start_offset - $stack_top->prev_offset);
        $stack_top->block->innerHTML .= $html;
        $stack_top->block->innerContent[] = $html;
        $stack_top->prev_offset = $start_offset + $token_length;

        $this->add_inner_block(
          $stack_top->block,
          $stack_top->token_start,
          $stack_top->token_length,
          $start_offset + $token_length
        );
        $this->offset = $start_offset + $token_length;
        return TRUE;

      default:
        // This is an error.
        $this->add_freeform();
        return FALSE;
    }
  }

  /**
   * Scans the document from where we last left off
   * and finds the next valid token to parse if it exists
   *
   * Returns the type of the find: kind of find, block information, attributes
   *
   * @return array
   * @since 5.0.0
   * @since 4.6.1 fixed a bug in attribute parsing which caused catastrophic backtracking on invalid block comments
   * @internal
   */
  public function next_token() {
    $matches = NULL;

    /*
     * aye the magic
     * we're using a single RegExp to tokenize the block comment delimiters
     * we're also using a trick here because the only difference between a
     * block opener and a block closer is the leading `/` before `wp:` (and
     * a closer has no attributes). we can trap them both and process the
     * match back in PHP to see which one it was.
     */
    $has_match = preg_match(
      '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s',
      $this->document,
      $matches,
      PREG_OFFSET_CAPTURE,
      $this->offset
    );

    // if we get here we probably have catastrophic backtracking or out-of-memory in the PCRE.
    if (FALSE === $has_match) {
      return ['no-more-tokens', NULL, NULL, NULL, NULL];
    }

    // we have no more tokens.
    if (0 === $has_match) {
      return ['no-more-tokens', NULL, NULL, NULL, NULL];
    }

    [$match, $started_at] = $matches[0];

    $length = strlen($match);
    $is_closer = isset($matches['closer']) && -1 !== $matches['closer'][1];
    $is_void = isset($matches['void']) && -1 !== $matches['void'][1];
    $namespace = $matches['namespace'];
    $namespace = (isset($namespace) && -1 !== $namespace[1]) ? $namespace[0] : 'core/';
    $name = $namespace . $matches['name'][0];
    $has_attrs = isset($matches['attrs']) && -1 !== $matches['attrs'][1];

    /*
     * Fun fact! It's not trivial in PHP to create "an empty associative array" since all arrays
     * are associative arrays. If we use `array()` we get a JSON `[]`
     */
    $attrs = $has_attrs
      ? self::decodeAttrs($matches['attrs'][0])
      : [];

    /*
     * This state isn't allowed
     * This is an error
     */
    if ($is_closer && ($is_void || $has_attrs)) {
      // we can ignore them since they don't hurt anything.
    }

    if ($is_void) {
      return ['void-block', $name, $attrs, $started_at, $length];
    }

    if ($is_closer) {
      return ['block-closer', $name, NULL, $started_at, $length];
    }

    return ['block-opener', $name, $attrs, $started_at, $length];
  }

  /**
   * Decodes the JSON of a block comment into attributes.
   *
   * WordPress decodes straight to associative arrays, which turns an empty
   * object into an empty array. This keeps `{}` as a \stdClass, so a value
   * such as `"style":{"layout":{}}` survives a round trip.
   *
   * @param string $json
   *   The JSON found between the block delimiters.
   *
   * @return array
   *   The attributes.
   */
  public static function decodeAttrs($json) {
    return (array) self::objectsToArrays(json_decode($json, FALSE));
  }

  /**
   * Turns decoded objects into arrays, apart from empty ones.
   */
  private static function objectsToArrays($value) {
    if ($value instanceof \stdClass) {
      $properties = get_object_vars($value);
      return $properties === [] ? $value : array_map([self::class, 'objectsToArrays'], $properties);
    }
    if (is_array($value)) {
      return array_map([self::class, 'objectsToArrays'], $value);
    }
    return $value;
  }

  /**
   * Returns a new block object for freeform HTML
   *
   * @param string $inner_html HTML content of block.
   *
   * @return WpBlockParserBlock freeform block object.
   * @internal
   * @since 3.9.0
   *
   */
  public function freeform($inner_html) {
    return new WpBlockParserBlock(NULL, [], [], $inner_html, [$inner_html]);
  }

  /**
   * Pushes a length of text from the input document
   * to the output list as a freeform block.
   *
   * @param null $length how many bytes of document text to output.
   *
   * @since 5.0.0
   * @internal
   */
  public function add_freeform($length = NULL) {
    $length = $length ? $length : strlen($this->document) - $this->offset;

    if (0 === $length) {
      return;
    }

    $this->output[] = (array) $this->freeform(substr($this->document, $this->offset, $length));
  }

  /**
   * Given a block structure from memory pushes
   * a new block to the output list.
   *
   * @param WpBlockParserBlock $block The block to add to the output.
   * @param int $token_start Byte offset into the document where the first token for the block starts.
   * @param int $token_length Byte length of entire block from start of opening token to end of closing token.
   * @param int|null $last_offset Last byte offset into document if continuing form earlier output.
   *
   * @internal
   * @since 5.0.0
   */
  public function add_inner_block(WpBlockParserBlock $block, $token_start, $token_length, $last_offset = NULL) {
    $parent = $this->stack[count($this->stack) - 1];
    $parent->block->innerBlocks[] = (array) $block;
    $html = substr($this->document, $parent->prev_offset, $token_start - $parent->prev_offset);

    if (!empty($html)) {
      $parent->block->innerHTML .= $html;
      $parent->block->innerContent[] = $html;
    }

    $parent->block->innerContent[] = NULL;
    $parent->prev_offset = $last_offset ? $last_offset : $token_start + $token_length;
  }

  /**
   * Pushes the top block from the parsing stack to the output list.
   *
   * @param int|null $end_offset byte offset into document for where we should stop sending text output as HTML.
   *
   * @since 5.0.0
   * @internal
   */
  public function add_block_from_stack($end_offset = NULL) {
    $stack_top = array_pop($this->stack);
    $prev_offset = $stack_top->prev_offset;

    $html = isset($end_offset)
      ? substr($this->document, $prev_offset, $end_offset - $prev_offset)
      : substr($this->document, $prev_offset);

    if (!empty($html)) {
      $stack_top->block->innerHTML .= $html;
      $stack_top->block->innerContent[] = $html;
    }

    if (isset($stack_top->leading_html_start)) {
      $this->output[] = (array) $this->freeform(
        substr(
          $this->document,
          $stack_top->leading_html_start,
          $stack_top->token_start - $stack_top->leading_html_start
        )
      );
    }

    $this->output[] = (array) $stack_top->block;
  }
}
