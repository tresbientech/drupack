/**
 * @file
 * Loads a WordPress release's editor scripts into a jsdom global.
 *
 * Shared by every Node entry point that runs WordPress's own JavaScript
 * against a pinned release: the block matrix's serializer and the
 * conversion pipeline's attribute parser.
 */

const fs = require('fs');
const vm = require('vm');
const { JSDOM, VirtualConsole } = require('jsdom');

/**
 * Builds a jsdom global with a WordPress release's scripts loaded.
 *
 * @param {string[]} scripts
 *   wp-includes/js/dist files, in load order.
 * @return {Window}
 *   The window carrying `wp.blocks` and `wp.blockLibrary`, core blocks
 *   registered.
 */
module.exports = function loadWordPress(scripts) {
  // jsdom reports every stylesheet the editor scripts inject; none matters.
  const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    runScripts: 'outside-only',
    pretendToBeVisual: true,
    url: 'http://localhost/',
    virtualConsole: new VirtualConsole(),
  });
  const context = dom.getInternalVMContext();
  // jsdom has no matchMedia, which the editor scripts call as they load.
  dom.window.matchMedia = () => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} });
  // A classic script, unlike eval(), defines its top-level var as a global.
  for (const file of scripts) {
    new vm.Script(fs.readFileSync(file, 'utf8'), { filename: file }).runInContext(context);
  }
  dom.window.wp.blockLibrary.registerCoreBlocks();
  return dom.window;
};
