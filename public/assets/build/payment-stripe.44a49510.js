/**
 * Stripe's card form on the checkout page. Loaded only when the channel's
 * payment provider is Stripe (pages/checkout.twig), after Stripe.js and after
 * checkout.js.
 *
 * Two jobs, and neither ever touches a card number: Stripe's Payment Element
 * collects the card in Stripe's own frames.
 *
 * - **Paying.** Pay Now is held while Stripe turns what the buyer entered into
 *   a one-time confirmation token, which goes into the form's `payment_token`
 *   field; the form is then posted as it always is, and the server confirms
 *   the payment with the token. Every other check -- the contact details, the
 *   consents, the state the order ships to -- is still the server's.
 * - **Verifying.** When the buyer's bank asks them to verify the payment, the
 *   server renders this page again with the verification it needs
 *   (`data-challenge`). Stripe runs it, and the page then goes to the confirm
 *   step, where the server asks Stripe how it ended. The result here is never
 *   trusted: success and failure both go to the server to find out.
 *
 * The sub-action buttons (discount, plan, bump) are left alone, so they post
 * exactly as they do without this file.
 */
(function () {
  'use strict';

  var surface = document.getElementById('payment-provider-surface');
  if (!surface || surface.getAttribute('data-provider') !== 'stripe') return;

  var form = document.querySelector('form[data-checkout-form]');
  var tokenField = form && form.querySelector('[data-payment-token]');
  var errorBox = form && form.querySelector('[data-payment-error]');
  var pay = form && form.querySelector('[data-checkout-submit]');
  if (!form || !tokenField) return;

  function showError(message) {
    if (!errorBox) return;
    errorBox.textContent = message || 'We could not read your card details. Please check them and try again.';
    errorBox.classList.remove('hidden');
  }

  function clearError() {
    if (!errorBox) return;
    errorBox.textContent = '';
    errorBox.classList.add('hidden');
  }

  // Hands Pay Now back to the buyer (checkout.js owns the busy state).
  function release() {
    form.dispatchEvent(new CustomEvent('checkout:release'));
  }

  if (typeof window.Stripe !== 'function') {
    showError('The secure card form could not load. Please refresh the page and try again.');
    return;
  }

  var config = {};
  try {
    config = JSON.parse(surface.getAttribute('data-client-config') || '{}');
  } catch (e) {
    config = {};
  }

  var stripe = window.Stripe(config.publishable_key || '');
  var confirmUrl = surface.getAttribute('data-confirm-url') || '/checkout/confirm/';

  // ---- Verifying ---------------------------------------------------------
  var challenge = surface.getAttribute('data-challenge');
  if (challenge) {
    if (pay) pay.hidden = true;
    surface.textContent = '';

    stripe.handleNextAction({ clientSecret: challenge }).then(function () {
      // Whatever the step answered, the server finds out from Stripe.
      window.location.assign(confirmUrl);
    });

    return;
  }

  // ---- Paying ------------------------------------------------------------
  var amount = parseInt(surface.getAttribute('data-amount') || '0', 10);
  var currency = surface.getAttribute('data-currency') || 'usd';

  // Deferred-intent mode: the PaymentIntent is created by the server when the
  // order is placed, so the Element is told the amount and how the card will
  // be kept. `setupFutureUsage` must match what the server asks Stripe for,
  // or Stripe refuses the token -- the card is saved for renewals and for an
  // upsell on this journey.
  var elements = stripe.elements({
    mode: 'payment',
    amount: amount > 0 ? amount : 50,
    currency: currency,
    setupFutureUsage: 'off_session',
    paymentMethodTypes: ['card'],
  });

  // The buyer's name, email, phone and address are already on this form, so
  // the Element does not ask again; they are handed to Stripe with the card.
  var paymentElement = elements.create('payment', {
    fields: { billingDetails: { name: 'never', email: 'never', phone: 'never', address: 'never' } },
  });
  paymentElement.mount(surface);
  paymentElement.on('change', clearError);

  function field(name) {
    var input = form.elements.namedItem(name);
    return input && typeof input.value === 'string' ? input.value.trim() : '';
  }

  function billingDetails() {
    return {
      name: (field('first_name') + ' ' + field('last_name')).trim(),
      email: field('email'),
      phone: field('phone'),
      address: {
        line1: field('address_line'),
        line2: '',
        city: field('city'),
        state: field('territory'),
        postal_code: field('postal_code'),
        country: 'US',
      },
    };
  }

  form.addEventListener('submit', function (event) {
    // Only Pay Now places the order; a sub-action posts untouched.
    if (!pay || event.submitter !== pay) return;

    // A second press while the token is being made is checkout.js's to refuse.
    if (tokenField.value) return;

    event.preventDefault();
    clearError();

    elements.submit().then(function (submitted) {
      if (submitted.error) {
        showError(submitted.error.message);
        release();
        return null;
      }

      return stripe.createConfirmationToken({
        elements: elements,
        params: { payment_method_data: { billing_details: billingDetails() } },
      });
    }).then(function (result) {
      if (!result) return;

      if (result.error) {
        showError(result.error.message);
        release();
        return;
      }

      tokenField.value = result.confirmationToken.id;
      // `submit()` rather than `requestSubmit()`: checkout.js already holds
      // this page as placing, and would refuse a second submit event.
      form.submit();
    }, function () {
      showError();
      release();
    });
  });

  // A page restored from the back/forward cache must not post a token that
  // was already used.
  window.addEventListener('pageshow', function () {
    tokenField.value = '';
  });
})();
