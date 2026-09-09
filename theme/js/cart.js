/**
 * Progressive enhancement over the server-owned cart: every control here is
 * a plain HTML form and works with this script absent. What this script
 * adds on top:
 *
 * - clicking a treatment-plan button (`[data-select-option][data-variant-id]`)
 *   writes its variant id into the product page's hidden `#product-variant-id`
 *   field, so the add-to-cart form posts the plan the buyer just chose rather
 *   than always the first one. `script.js`'s existing `data-select-group`
 *   handler keeps owning `aria-pressed` — this only touches the hidden field.
 * - submitting any form inside `#cart-panel`, or the product page's
 *   add-to-cart form, marks itself `aria-busy` and disables its own submit
 *   buttons, so a double click during the round-trip to the server can't
 *   post the same mutation twice.
 * - if the cart panel carries `data-cart-notice` on load (the server flashed
 *   something worth reading — a refused add, a capped quantity), the panel
 *   opens automatically, so the buyer sees the cart the notice is about
 *   without hunting for it. The notice itself is rendered by the layout, not
 *   by the panel, and is readable whether or not this script ever runs; this
 *   only saves the click.
 */
(function () {
  'use strict';

  var variantField = document.getElementById('product-variant-id');
  if (variantField) {
    document.querySelectorAll('[data-select-option][data-variant-id]').forEach(function (option) {
      option.addEventListener('click', function () {
        variantField.value = option.getAttribute('data-variant-id') || '';
      });
    });
  }

  function guardAgainstDoublePost(form) {
    form.addEventListener('submit', function () {
      form.setAttribute('aria-busy', 'true');
      form.querySelectorAll('button[type="submit"]').forEach(function (button) {
        button.disabled = true;
      });
    });
  }

  var cartPanel = document.getElementById('cart-panel');
  if (cartPanel) {
    cartPanel.querySelectorAll('form').forEach(guardAgainstDoublePost);

    if (cartPanel.hasAttribute('data-cart-notice')) {
      var cartBtn = document.getElementById('cart-btn');
      if (cartBtn) cartBtn.setAttribute('aria-expanded', 'true');
      cartPanel.classList.remove('hidden');
    }
  }

  var addToCartForm = document.querySelector('form[action$="/cart/add/"]');
  if (addToCartForm) {
    guardAgainstDoublePost(addToCartForm);
  }
})();
