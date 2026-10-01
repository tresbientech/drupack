/**
 * @file
 * Serves WordPress's own parse() over stdin/stdout, loading it once.
 *
 * Reads one JSON line on stdin: {"scripts": [path...]}, wp-includes/js/dist
 * files in load order. Loads WordPress once from them, then for each further
 * line, {"blobs": [html...]}, each blob one complete saved block markup
 * document (a Template, Template part or Pattern's full content), writes one
 * JSON line back: a parsed forest per blob, nested arrays of {name,
 * attributes, isValid} in document order, inner blocks under `innerBlocks`,
 * matching `wp.blocks.parse()`'s own structure. A purely whitespace freeform
 * segment is absent, as `wp.blocks.parse()` itself omits it.
 *
 * `wp.blocks.parse()` returns every attribute, block.json defaults included,
 * which is more than the comment WordPress's own serializer would save and
 * more than conversion needs. `attributes` here instead holds the attributes
 * that comment would carry (found by re-running the block through
 * `wp.blocks.serialize()` and reading the comment back with the same raw
 * grammar parser the comment came from) plus the attributes block.json
 * sources from the markup or the post: those never appear in the comment,
 * but conversion has no other way to reach them.
 *
 * One process serves every parse() call of a PHP request, so WordPress's
 * bundles load once.
 */

const readline = require('readline');
const loadWordPress = require('./wp-bootstrap.cjs');

let parse;
let serialize;
let getBlockType;
let parseRaw;

/**
 * Returns the attributes WordPress's serializer would write to the comment,
 * plus the attributes block.json sources from the markup or the post.
 */
function savedAttributes(block) {
  const [raw] = parseRaw(serialize([{ ...block, innerBlocks: [] }])).filter((entry) => entry.blockName !== null);
  const attributes = { ...(raw ? raw.attrs : {}) };
  const schema = getBlockType(block.name)?.attributes ?? {};
  for (const [key, attributeSchema] of Object.entries(schema)) {
    if (attributeSchema.source !== undefined) {
      attributes[key] = block.attributes[key];
    }
  }
  return attributes;
}

function describe(block) {
  return {
    name: block.name,
    attributes: savedAttributes(block),
    isValid: block.isValid,
    innerBlocks: block.innerBlocks.map(describe),
  };
}

const lines = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
lines.on('line', (line) => {
  if (parse === undefined) {
    const window = loadWordPress(JSON.parse(line).scripts);
    ({ parse, serialize, getBlockType } = window.wp.blocks);
    ({ parse: parseRaw } = window.wp.blockSerializationDefaultParser);
    return;
  }
  const { blobs } = JSON.parse(line);
  process.stdout.write(JSON.stringify(blobs.map((html) => parse(html).map(describe))) + '\n');
});
