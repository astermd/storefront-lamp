/**
 * Post-order upsell offer. Powers /upsell/, where both actions are real form
 * posts and the page is fully usable with this script absent.
 *
 * All this adds is a double-submit courtesy. Accepting an offer charges a card,
 * so a second click during the round trip is the one click worth swallowing —
 * but the guard that actually protects the money is server-side (the checkout
 * attempt key), and it has to be, because a disabled button protects nobody
 * with JavaScript off, on a slow tab, or against a hand-made request. This is
 * only here so the common case never has to reach it.
 *
 * Both buttons go dead on either submit, not just the one pressed: the two
 * answers are mutually exclusive, and a buyer who clicks Add to Order and then
 * No Thanks while the first post is in flight has asked for two different
 * things at once.
 *
 * Transcribed from the static design mockup (upsell page)'s inline script,
 * which navigated to a hardcoded 'thankyou.html' from a click handler and did
 * the charging nowhere. Its label swaps ("Continuing...", "Added!") are gone
 * with it: the buttons submit and the page navigates, so there is no state to
 * narrate, and rewriting a button's text is how its accessible name gets lost
 * mid-announcement.
 */
(function () {
  'use strict';

  var decline = document.getElementById('offer-decline');
  var accept = document.getElementById('offer-accept');
  if (!decline || !accept) return;

  var answering = false;

  function release() {
    answering = false;
    decline.disabled = false;
    accept.disabled = false;
  }

  function guard(button) {
    var form = button.form;
    if (!form) return;

    form.addEventListener('submit', function (event) {
      // A second submit while the first is still in flight is the whole point
      // of this file, and refusing it here (rather than relying on the
      // `disabled` attribute alone) covers the keyboard path too.
      if (answering) {
        event.preventDefault();
        return;
      }

      answering = true;
      form.setAttribute('aria-busy', 'true');

      // Deferred by one tick, the way theme/js/checkout.js defers its own.
      // The browser builds the request body after this handler returns and
      // skips any control disabled by then; neither button carries a `name`,
      // so nothing would actually be dropped today, but a button that grows
      // one later must not silently stop posting it. A double click cannot
      // beat a zero-delay timeout.
      window.setTimeout(function () {
        decline.disabled = true;
        accept.disabled = true;
      }, 0);
    });
  }

  guard(decline);
  guard(accept);

  // Coming back to this page from the back/forward cache replays the DOM as it
  // was left — both answers dead — while the buyer is looking at a live offer.
  // On this step that would be a dead end: the page's own two buttons are the
  // only way forward through the funnel. On a first, ordinary load this is a
  // no-op.
  window.addEventListener('pageshow', release);
  window.addEventListener('popstate', release);
})();
