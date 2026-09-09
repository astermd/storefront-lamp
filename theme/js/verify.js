/**
 * Identity-verification step. Powers /verify/ (loaded there as the page's
 * only script).
 *
 * Progressive enhancement only: the page is a plain form and submits, posts
 * and records its outcome with scripting switched off. All this adds is a
 * guard against a doubled click, which matters here rather than cosmetically —
 * each submit runs the configured identity checks, and those are metered calls
 * to an outside provider.
 *
 * The button carries no name, so disabling it after the submit has started
 * cannot remove a field from the request.
 *
 * DEVIATION from the static design mockup: the mockup's script was not a
 * submit at all. It assigned window.location to a hardcoded 'checkout.html',
 * which is why the page collected nothing and persisted nothing.
 */
(function () {
  var button = document.getElementById('verify-submit');
  var label = document.getElementById('verify-submit-label');
  if (!button || !button.form) {
    return;
  }

  button.form.addEventListener('submit', function () {
    // Deferred so the browser has already collected the form's fields.
    window.setTimeout(function () {
      button.disabled = true;
      if (label) {
        label.textContent = 'Submitting...';
      }
    }, 0);
  });
})();
