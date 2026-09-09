/**
 * Checkout page. Powers /checkout/ (loaded there as the page's only script).
 *
 * Everything on this page works with this file absent: the page is one plain
 * HTML form, and each sub-action (bump, promo, plan) is a submit button that
 * redirects it with `formaction`, so every one of them posts what is typed in
 * the boxes right now with no script involved ([8.7], [27.10]). What this
 * script adds on top is two things:
 *
 * - the credit-card fields show and hide with the selected payment method,
 *   which is the mockup's own behaviour kept verbatim;
 * - a submitted button goes busy and stops answering a second click, which is
 *   the content-loader state cart.js already establishes as this theme's
 *   pattern.
 *
 * The busy state is comfort, not correctness. It removes the "did that click
 * register?" second click and nothing else: a reload, a browser-level retry,
 * a second tab, or two requests minutes apart all still reach the server
 * without any click at all, and the server is the only place that can refuse
 * the duplicate. Nothing here is load-bearing for money.
 */
(function () {
  'use strict';

  // Only the "Credit card" payment method has expanded fields to show/hide;
  // PayPal/Afterpay are single-line rows with no extra inputs. Absent entirely
  // when the active adapter hosts its own card surface, which is why this
  // returns rather than assuming the group is there.
  var group = document.getElementById('payment-method-group');
  if (group) {
    var options = Array.prototype.slice.call(group.querySelectorAll('[data-select-option]'));
    var panel = group.querySelector('[data-payment-panel="card"]');

    var sync = function () {
      var selected = options.filter(function (o) { return o.getAttribute('aria-pressed') === 'true'; })[0];
      panel.classList.toggle('hidden', !selected || selected.getAttribute('data-payment') !== 'card');
    };

    options.forEach(function (option) {
      option.addEventListener('click', sync);
    });
    sync();
  }

  var form = document.querySelector('form[data-checkout-form]');
  if (!form) return;

  // The one button that places the order, told apart from the sub-actions by
  // its own hook attribute rather than by position or class — the template
  // owns which button that is, and tests/Http/CheckoutControllerTest.php pins
  // the contract so an edit there cannot quietly switch this off.
  var pay = form.querySelector('[data-checkout-submit]');
  var idleLabel = pay ? (pay.textContent || '').trim() : '';
  var busyLabel = (pay && pay.getAttribute('data-busy-label')) || idleLabel;
  var placing = false;

  /**
   * Hand every control back to the buyer.
   *
   * A permanently dead Pay Now is a worse failure than the double click this
   * guards against: it strands someone in front of a payment that never
   * happened, with no way to retry. So the busy state is treated as temporary
   * in every direction — see the listeners at the bottom of this file.
   */
  function release() {
    placing = false;
    form.removeAttribute('aria-busy');

    if (pay) {
      pay.removeAttribute('aria-busy');
      pay.removeAttribute('aria-disabled');
      pay.textContent = idleLabel;
    }

    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
      button.disabled = false;
    });
  }

  // How long the placement button stays busy with nothing having happened
  // before it is handed back. A submit that never navigates leaves no event to
  // listen for — the buyer pressed Stop, or the connection dropped in a way
  // the browser did not turn into its own error page — so the only honest
  // answer is to give up waiting. Long enough that a slow card authorization
  // finishes first; short enough to be under the point where someone starts
  // hunting for a way to retry.
  var RELEASE_AFTER_MS = 30000;

  form.addEventListener('submit', function (event) {
    // Which button was pressed is the whole question here, and `submitter` is
    // the only thing that answers it. Where it is missing the guard sits out
    // entirely rather than guessing: no enhancement is the correct degradation,
    // and guessing wrong would either dead-end a sub-action or let a keystroke
    // trip the placement state.
    var submitter = event.submitter;
    if (!submitter) return;

    if (submitter === pay) {
      // Second click on an already-busy Pay Now: swallowed here rather than by
      // a `disabled` attribute, because disabling the button moves focus off
      // it and takes the announcement of its new label with it.
      if (placing) {
        event.preventDefault();
        return;
      }

      placing = true;
      form.setAttribute('aria-busy', 'true');
      pay.setAttribute('aria-busy', 'true');

      // `aria-disabled`, not `disabled`: the button keeps focus and keeps a
      // real accessible name, so the swapped-in label is what a screen reader
      // reads out, and `aria-busy` styles the same state visually from the
      // same attribute. The refusal above is what actually blocks the click.
      pay.setAttribute('aria-disabled', 'true');
      pay.textContent = busyLabel;

      window.setTimeout(release, RELEASE_AFTER_MS);
      return;
    }

    // A sub-action. Only the button actually pressed goes dead — Pay Now and
    // the other sub-actions stay live, because a buyer waiting on a promo
    // round-trip may well want to change plan instead, and the old blanket
    // version of this took the whole page with it.
    //
    // Deferred by one tick, and it has to be. A sub-action names its target on
    // the button itself (which bump, which variant), and the browser builds
    // the request body *after* this handler returns, skipping any control that
    // is disabled by then — disabling here and now would post the form with no
    // idea which button was pressed. A double click is still swallowed: the
    // timeout runs long before a second click can land. It matters most for
    // the bump, which toggles: posted twice, it adds and then removes itself.
    form.setAttribute('aria-busy', 'true');
    window.setTimeout(function () {
      submitter.disabled = true;
    }, 0);
  });

  // Restoring this page from the back/forward cache replays the DOM exactly as
  // it was left — mid-submit, busy, refusing clicks — while the buyer is
  // looking at a live page and expecting to be able to act on it. The same
  // goes for a history move that never rebuilds the document. Both hand
  // everything back; on a first, ordinary load this is a no-op.
  window.addEventListener('pageshow', release);
  window.addEventListener('popstate', release);
})();
