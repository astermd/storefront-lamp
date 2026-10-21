/**
 * Progressive enhancement over the server-owned cart: every control here is
 * a plain HTML form and works with this script absent. What this script
 * adds on top:
 *
 * - clicking a treatment-plan button (`[data-select-option][data-variant-id]`)
 *   writes its variant id into the product page's hidden `#product-variant-id`
 *   field, so the add-to-cart form posts the plan the buyer just chose rather
 *   than always the first one, and shows that plan's `data-price` in
 *   `#product-price`. `script.js`'s existing `data-select-group` handler keeps
 *   owning `aria-pressed`. The price is display only: the server charges the
 *   posted variant's own price whatever the page shows.
 * - submitting any form inside `#cart-panel`, or any add-to-cart form, marks itself `aria-busy` and disables its own submit
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
  var priceField = document.getElementById('product-price');
  if (variantField) {
    document.querySelectorAll('[data-select-option][data-variant-id]').forEach(function (option) {
      option.addEventListener('click', function () {
        variantField.value = option.getAttribute('data-variant-id') || '';

        var price = option.getAttribute('data-price');
        if (priceField && price) priceField.textContent = price;
      });
    });
  }

  function guardAgainstDoublePost(form) {
    form.addEventListener('submit', function () {
      form.setAttribute('aria-busy', 'true');

      // Deferred one tick: a button disabled before the body is built is not
      // posted, which drops the pressed button's `intent`.
      window.setTimeout(function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
          button.disabled = true;
        });
      }, 0);
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

  // The product page's form, and each card's "Get Started" on the listings.
  document.querySelectorAll('form[action$="/cart/add/"]').forEach(guardAgainstDoublePost);
})();
