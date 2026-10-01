/**
 * @file
 * Copies a chosen theme preset into the field a component form stores.
 *
 * @param {object} Drupal
 *   The Drupal global.
 * @param {Function} once
 *   The once() function.
 */
((Drupal, once) => {
  /**
   * Sets a text field's value the way typing would.
   *
   * The prototype setter and an input event let a framework's own form
   * state see the change, not only the DOM.
   *
   * @param {HTMLInputElement} input
   *   The text field.
   * @param {string} value
   *   The value to set.
   */
  function setValue(input, value) {
    Object.getOwnPropertyDescriptor(
      HTMLInputElement.prototype,
      'value',
    ).set.call(input, value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /**
   * Shows the paired field only while the select holds a custom value.
   *
   * A builder's own stylesheet may set the field's display, which overrides
   * the hidden attribute, so this sets the inline style.
   *
   * @param {HTMLSelectElement} select
   *   The preset select.
   * @param {HTMLInputElement} input
   *   The paired text field.
   */
  function toggle(select, input) {
    input.style.display =
      select.value === select.dataset.wordpalCustom ? '' : 'none';
  }

  Drupal.behaviors.wordpalInspectorPresets = {
    attach(context) {
      once(
        'wordpal-preset',
        'select[data-wordpal-preset-for]',
        context,
      ).forEach((select) => {
        const input = select.form.querySelector(
          `input[data-wordpal-preset="${select.dataset.wordpalPresetFor}"]`,
        );
        const presets = Array.from(select.options, (option) => option.value);
        select.value = presets.includes(input.value)
          ? input.value
          : select.dataset.wordpalCustom;
        toggle(select, input);
        select.addEventListener('change', () => {
          toggle(select, input);
          if (select.value === select.dataset.wordpalCustom) {
            input.focus();
          } else {
            setValue(input, select.value);
          }
        });
      });
    },
  };
})(Drupal, once);
