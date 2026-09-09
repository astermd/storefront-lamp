/**
 * Mode A glue: mounts the hosted form engine and owns the two round trips.
 *
 * The engine owns rendering and its own conditional logic. This module owns
 * nothing else, because the server is what decides whether a journey may
 * proceed: the engine's eligibility alerts are display-only and its navigation
 * consults no eligibility state, so a hard rule is enforced when `onSubmit`
 * reaches the server and not before ([10.45]).
 *
 * Two consequences of the engine's public surface, both deliberate rather than
 * overlooked:
 *
 * - It exposes `onChange` and `onSubmit` and no page-change hook, so a
 *   progressive save is debounced off changes rather than fired per step, and
 *   the position is omitted rather than guessed ([10.25]).
 * - It is a third-party script. If it fails to load there is no form, so the
 *   page states that instead of leaving an empty panel.
 */
(function () {
  'use strict';

  var mount = document.querySelector('[data-intake-engine]');
  if (!mount) return;

  var failure = document.querySelector('[data-intake-engine-error]');

  function fail() {
    if (failure) failure.classList.remove('hidden');
  }

  function readJson(selector, fallback) {
    var node = document.querySelector(selector);
    if (!node) return fallback;
    try {
      return JSON.parse(node.textContent || '') || fallback;
    } catch (e) {
      return fallback;
    }
  }

  var definition = readJson('[data-intake-definition]', null);
  var initialValues = readJson('[data-intake-values]', {});

  if (!definition || typeof FormEngine === 'undefined' || typeof FormEngine.mountForm !== 'function') {
    fail();
    return;
  }

  var saveUrl = mount.getAttribute('data-save-url');
  var submitUrl = mount.getAttribute('data-submit-url');
  var captureUrl = mount.getAttribute('data-capture-url');
  var csrf = mount.getAttribute('data-csrf');
  // The save and submit endpoints are shared by both questionnaire steps, so
  // every post has to name which one it belongs to.
  var step = mount.getAttribute('data-step');

  function post(url, answers) {
    var body = new FormData();
    body.set('_csrf', csrf || '');
    body.set('step', step || '');
    Object.keys(answers || {}).forEach(function (name) {
      var value = answers[name];
      if (Array.isArray(value)) {
        value.forEach(function (one) { body.append(name + '[]', one); });
      } else if (typeof value === 'boolean') {
        if (value) body.set(name, '1');
      } else if (value !== null && value !== undefined) {
        body.set(name, value);
      }
    });

    return fetch(url, {
      method: 'POST',
      body: body,
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin',
      redirect: 'follow'
    });
  }

  var saveTimer = null;
  var captured = false;

  function scheduleSave(answers) {
    // Debounced because the engine reports every keystroke and a save per
    // character would be a round trip per character. A dropped save never
    // blocks the visitor ([10.26]), so failures here are ignored on purpose.
    if (saveTimer) window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(function () {
      post(saveUrl, answers).catch(function () {});
      attemptCapture(answers);
    }, 800);
  }

  function attemptCapture(answers) {
    // Fires at most once per page load, and re-arms on failure so a network
    // blip does not permanently lose an abandoner's lead ([9.5]).
    if (captured) return;
    var first = String(answers.first_name || '').trim();
    var email = String(answers.email || '').trim();
    if (!first || !email) return;

    captured = true;
    post(captureUrl, answers).then(function (response) {
      if (!response || !response.ok) captured = false;
    }).catch(function () { captured = false; });
  }

  try {
    FormEngine.mountForm(definition, mount, {
      initialValues: initialValues,
      onChange: function (field, value, answers) {
        scheduleSave(answers);
      },
      onSubmit: function (answers) {
        post(submitUrl, answers).then(function (response) {
          // The server decides where this goes: onward when the journey may
          // proceed, and to the terminal page when a hard rule matched.
          if (response && response.redirected) {
            window.location.href = response.url;
            return;
          }
          window.location.reload();
        }).catch(function () {
          window.location.reload();
        });
      }
    });
  } catch (e) {
    fail();
  }
})();
