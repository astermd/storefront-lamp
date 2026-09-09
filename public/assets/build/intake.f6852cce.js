/**
 * The server-rendered intake stepper.
 *
 * Every page of the questionnaire is already in the document ([10.6a]); this
 * script decides which one is visible, keeps conditional questions in step
 * with the answers ([10.17]), computes the BMI composite, and posts each
 * advance so a half-finished form is not lost.
 *
 * It holds no authority. Every gate it appears to enforce -- a required
 * question, a page boundary, an eligibility rule -- is evaluated again on the
 * server on each save and on submit ([10.45]), because a visitor with scripts
 * disabled or tampered with must not be able to reach a step the server would
 * refuse. Treat anything here as presentation: if this file and the server
 * ever disagree, the server is right.
 *
 * The conditional-logic rules it evaluates are the same ones the server reads
 * out of the form definition, embedded per field as `data-intake-conditions`.
 * They are duplicated here rather than fetched because a round trip per
 * keystroke would make a follow-up question feel broken.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-intake-form]');
  if (!form) return;

  var pages = Array.prototype.slice.call(form.querySelectorAll('[data-intake-page]'));
  if (!pages.length) return;

  var wrappers = Array.prototype.slice.call(form.querySelectorAll('[data-intake-field]'));
  var pageInput = form.querySelector('[data-intake-page-input]');
  var nav = form.querySelector('[data-intake-nav]');
  var interstitial = form.querySelector('[data-intake-interstitial]');
  var interstitialText = form.querySelector('[data-intake-interstitial-text]');
  var blocked = form.getAttribute('data-intake-blocked') === '1';
  var current = 0;
  var terminated = false;

  // ---- reading and writing answers ---------------------------------------

  function wrapperFor(name) {
    for (var i = 0; i < wrappers.length; i++) {
      if (wrappers[i].getAttribute('data-intake-field') === name) return wrappers[i];
    }
    return null;
  }

  function valueOf(name) {
    var wrapper = wrapperFor(name);
    if (!wrapper) return null;

    var checkboxes = wrapper.querySelectorAll('input[type="checkbox"][name$="[]"]');
    if (checkboxes.length) {
      return Array.prototype.filter.call(checkboxes, function (box) { return box.checked; })
        .map(function (box) { return box.value; });
    }

    var radio = wrapper.querySelector('input[type="radio"]:checked');
    if (radio) return radio.value;
    if (wrapper.querySelector('input[type="radio"]')) return '';

    var single = wrapper.querySelector('input[type="checkbox"]');
    if (single) return single.checked;

    var control = wrapper.querySelector('input, select, textarea');
    return control ? control.value : null;
  }

  function answers() {
    var state = {};
    wrappers.forEach(function (wrapper) {
      var name = wrapper.getAttribute('data-intake-field');
      if (name) state[name] = valueOf(name);
    });
    return state;
  }

  // ---- rule evaluation ----------------------------------------------------
  // Mirrors the server's operator set. An unrecognised operator evaluates
  // false rather than throwing ([10.18]), which is what keeps a form authored
  // against a newer vocabulary usable rather than broken.

  var ALIASES = {
    notEquals: 'not_equals', notContains: 'not_contains',
    isEmpty: 'is_empty', isNotEmpty: 'is_not_empty', notIn: 'not_in'
  };

  function isEmptyValue(value) {
    if (value === null || value === undefined) return true;
    if (typeof value === 'string') return value.trim() === '';
    if (Array.isArray(value)) return value.length === 0;
    return false;
  }

  function comparands(raw) {
    if (Array.isArray(raw)) return raw.map(String);
    if (typeof raw === 'string') return raw.split(',').map(function (part) { return part.trim(); });
    if (raw === null || raw === undefined) return [];
    return [String(raw)];
  }

  function numeric(value) {
    if (typeof value === 'number') return isNaN(value) ? null : value;
    if (typeof value !== 'string') return null;
    var text = value.trim();
    if (text === '') return null;
    if (text !== '' && !isNaN(Number(text))) return Number(text);
    if (/[-/:Ta-zA-Z]/.test(text)) {
      var parsed = Date.parse(text);
      return isNaN(parsed) ? null : parsed;
    }
    return null;
  }

  function evaluateRule(rule, state) {
    var operator = ALIASES[rule.operator] || rule.operator || 'equals';
    var answer = state[rule.field];

    if (operator === 'is_empty') return isEmptyValue(answer);
    if (operator === 'is_not_empty') return !isEmptyValue(answer);

    var wanted = comparands(rule.value);

    if (Array.isArray(answer)) {
      var present = wanted.some(function (one) { return answer.map(String).indexOf(String(one)) !== -1; });
      if (operator === 'equals' || operator === 'contains' || operator === 'in') return present;
      if (operator === 'not_equals' || operator === 'not_contains' || operator === 'not_in') return !present;
      return false;
    }

    var text = answer === null || answer === undefined ? '' : String(answer);

    switch (operator) {
      case 'equals': return wanted.indexOf(text) !== -1;
      case 'not_equals': return wanted.indexOf(text) === -1;
      case 'contains': return wanted.some(function (one) { return text.indexOf(one) !== -1; });
      case 'not_contains': return !wanted.some(function (one) { return text.indexOf(one) !== -1; });
      case 'in': return wanted.indexOf(text) !== -1;
      case 'not_in': return wanted.indexOf(text) === -1;
      case 'greater_than':
      case 'less_than':
      case 'greater_than_or_equal':
      case 'less_than_or_equal': {
        var left = numeric(answer);
        var right = numeric(wanted[0]);
        if (left === null || right === null) return false;
        if (operator === 'greater_than') return left > right;
        if (operator === 'less_than') return left < right;
        if (operator === 'greater_than_or_equal') return left >= right;
        return left <= right;
      }
      default: return false;
    }
  }

  function evaluateGroup(condition, state) {
    var rules = condition.rules || [];
    if (!rules.length) return false;

    var logic = condition.logic;
    var result = evaluateRule(rules[0], state);
    for (var i = 1; i < rules.length; i++) {
      // logic[i-1] joins the accumulated result with rule i: the combinator
      // list is one shorter than the rule list.
      var joiner = (Array.isArray(logic) ? logic[i - 1] : logic) || 'and';
      var next = evaluateRule(rules[i], state);
      result = joiner === 'or' ? (result || next) : (result && next);
    }
    return result;
  }

  // ---- applying field state ----------------------------------------------

  function conditionsFor(wrapper) {
    var raw = wrapper.getAttribute('data-intake-conditions');
    if (!raw) return [];
    try { return JSON.parse(raw) || []; } catch (e) { return []; }
  }

  function applyConditions() {
    var state = answers();

    wrappers.forEach(function (wrapper) {
      var conditions = conditionsFor(wrapper);
      if (!conditions.length) return;

      var visible = wrapper.getAttribute('data-intake-hidden') !== '1';
      var disabled = false;
      var required = wrapper.getAttribute('data-intake-required') === '1';

      // Inverted defaults, matching the server: a field carrying any `show`
      // condition starts hidden, and any `enable` condition starts disabled.
      if (conditions.some(function (c) { return c.action === 'show'; })) visible = false;
      if (conditions.some(function (c) { return c.action === 'enable'; })) disabled = true;

      conditions.forEach(function (condition) {
        if (!evaluateGroup(condition, state)) return;
        switch (condition.action) {
          case 'show': visible = true; break;
          case 'hide': visible = false; break;
          case 'enable': disabled = false; break;
          case 'disable': disabled = true; break;
          case 'require': case 'make_required': required = true; break;
          case 'optional': case 'make_optional': required = false; break;
          default: break;
        }
      });

      wrapper.hidden = !visible;
      Array.prototype.forEach.call(wrapper.querySelectorAll('input, select, textarea'), function (control) {
        control.disabled = disabled;
        // The required marker has to track visibility ([10.19]): a required
        // control on a hidden branch is unfocusable, and native validation
        // would block the submit with nothing on screen to explain it.
        if (visible && !disabled && required) {
          control.setAttribute('required', '');
        } else {
          control.removeAttribute('required');
        }
      });
    });

    computeBmi(state);
    checkTermination(state);
  }

  // ---- the BMI composite -------------------------------------------------

  function computeBmi(state) {
    Array.prototype.forEach.call(form.querySelectorAll('[data-intake-bmi]'), function (composite) {
      var target = composite.getAttribute('data-intake-bmi-target');
      var scoreField = composite.querySelector('[data-intake-bmi-score]');
      var result = composite.querySelector('[data-intake-bmi-result]');
      var unit = String(state[target + '_unit_system'] || state['bmi_unit_system'] || '').toLowerCase();
      var metric = /metric|kg|cm/.test(unit);
      var height = numeric(state[target === 'bmi_measurement' ? 'bmi_height' : target + '_height'] || state['bmi_height']);
      var weight = numeric(state[target === 'bmi_measurement' ? 'bmi_weight' : target + '_weight'] || state['bmi_weight']);

      if (height === null || weight === null || height <= 0) {
        // Absent, never zero: a zero score would satisfy a "below 27"
        // eligibility rule and stop someone who is still typing.
        if (scoreField) scoreField.value = '';
        if (result) { result.textContent = ''; result.hidden = true; }
        state[target] = null;
        return;
      }

      var score = metric
        ? weight / Math.pow(height / 100, 2)
        : (weight / Math.pow(height, 2)) * 703;
      score = Math.round(score * 10) / 10;

      var band = score < 18.5 ? 'Underweight' : score < 25 ? 'Normal' : score < 30 ? 'Overweight' : 'Obese';
      if (scoreField) scoreField.value = String(score);
      if (result) { result.textContent = 'BMI ' + score + ' · ' + band; result.hidden = false; }
      state[target] = score;
    });
  }

  // ---- eligibility -------------------------------------------------------

  function checkTermination(state) {
    var rules = [];
    try { rules = JSON.parse(form.getAttribute('data-intake-rules') || '[]') || []; } catch (e) { rules = []; }

    var fired = null;
    for (var i = 0; i < rules.length; i++) {
      if (rules[i].mode !== 'hard') continue;
      if ((rules[i].conditions || []).some(function (c) { return evaluateGroup(c, state); })) {
        fired = rules[i];
        break;
      }
    }

    terminated = fired !== null;
    if (interstitial) {
      interstitial.hidden = !terminated;
      if (terminated && interstitialText) interstitialText.textContent = fired.message || '';
    }
    setAdvanceEnabled(!terminated && !blocked);
  }

  function setAdvanceEnabled(enabled) {
    Array.prototype.forEach.call(
      form.querySelectorAll('[data-intake-action="next_page"], [data-intake-action="submit"]'),
      function (button) { button.disabled = !enabled; }
    );
  }

  // ---- navigation --------------------------------------------------------

  function visibleFieldsOf(page) {
    return Array.prototype.slice.call(page.querySelectorAll('[data-intake-field]'))
      .filter(function (wrapper) { return !wrapper.hidden; });
  }

  function validatePage(page) {
    var ok = true;
    visibleFieldsOf(page).forEach(function (wrapper) {
      var control = wrapper.querySelector('[required]');
      if (!control) return;
      if (!control.checkValidity()) {
        showError(wrapper, control.validationMessage);
        ok = false;
      } else {
        clearError(wrapper);
      }
    });
    return ok;
  }

  function showError(wrapper, message) {
    var node = wrapper.querySelector('[data-intake-error]');
    if (!node) return;
    node.textContent = message;
    node.hidden = false;
    node.classList.remove('hidden');
  }

  function clearError(wrapper) {
    var node = wrapper.querySelector('[data-intake-error]');
    if (!node) return;
    node.textContent = '';
    node.hidden = true;
    node.classList.add('hidden');
  }

  function goToPage(index) {
    current = Math.max(0, Math.min(pages.length - 1, index));
    pages.forEach(function (page, i) { page.hidden = i !== current; });
    if (pageInput) pageInput.value = String(current);
    updateProgress();
    // A client-side step change is invisible to a screen reader unless it is
    // announced ([25.14]), and the new step's heading is what the visitor
    // needs read to them.
    var heading = pages[current].querySelector('h1, h2, legend, label');
    if (heading) {
      heading.setAttribute('tabindex', '-1');
      heading.focus({ preventScroll: true });
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function updateProgress() {
    var percent = Math.round(((current + 1) / pages.length) * 100);
    var fill = document.getElementById('intake-progress-fill');
    var label = document.getElementById('intake-progress-label');
    if (fill) fill.style.width = percent + '%';
    if (label) label.textContent = 'Question ' + (current + 1) + ' of ' + pages.length;
    Array.prototype.forEach.call(form.querySelectorAll('[data-intake-progress-fill]'), function (node) {
      node.style.width = percent + '%';
    });
    Array.prototype.forEach.call(form.querySelectorAll('[data-intake-progress-label]'), function (node) {
      node.textContent = 'Question ' + (current + 1) + ' of ' + pages.length;
    });
  }

  function post(url, extra) {
    var data = new FormData(form);
    Object.keys(extra || {}).forEach(function (key) { data.set(key, extra[key]); });
    return fetch(url, {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    });
  }

  function save(pageIndex) {
    // A failed save must never stop the visitor ([10.26]): losing a save is
    // recoverable, trapping someone in a medical questionnaire is not. So the
    // advance happens regardless of what this returns.
    return post(form.getAttribute('data-save-url'), { page: String(pageIndex) })
      .then(function (response) {
        // A save that trips a hard rule answers with a redirect to the
        // terminal page. `fetch` follows it, so the final URL is what says so.
        if (response && response.redirected && response.url.indexOf(window.location.pathname) === -1) {
          window.location.href = response.url;
        }
        return null;
      })
      .catch(function () { return null; });
  }

  form.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('[data-intake-action]') : null;
    if (!button || !form.contains(button)) return;
    var action = button.getAttribute('data-intake-action');

    if (action === 'previous_page') {
      event.preventDefault();
      goToPage(current - 1);
      return;
    }

    if (action === 'next_page') {
      event.preventDefault();
      if (terminated || blocked) return;
      if (!validatePage(pages[current])) return;
      // The server validates the page this names and reports the position of
      // the one after it, so this is the page being left rather than the one
      // being entered. Posting the destination instead validated an empty
      // page, which failed and silently suppressed both the progress event
      // and the lead write.
      save(current);
      var advancingTo = current + 1;
      if (advancingTo >= pages.length) {
        submit();
      } else {
        goToPage(advancingTo);
      }
      return;
    }

    if (action === 'submit') {
      event.preventDefault();
      submit();
    }
  });

  function submit() {
    if (terminated || blocked) return;
    for (var i = 0; i < pages.length; i++) {
      if (!validatePage(pages[i])) { goToPage(i); return; }
    }
    form.setAttribute('aria-busy', 'true');
    post(form.getAttribute('data-submit-url'), {}).then(function (response) {
      form.removeAttribute('aria-busy');
      if (response && response.redirected) { window.location.href = response.url; return; }
      // Not a redirect means the server refused and re-rendered: reload this
      // page to show what it said. Never navigate to the submit endpoint --
      // it answers POST only, so a GET there loses the form to an error page.
      window.location.reload();
    }).catch(function () {
      form.removeAttribute('aria-busy');
    });
  }

  // ---- early lead capture -------------------------------------------------

  var captured = false;
  function attemptCapture() {
    // Fires at most once per page load, and re-arms on failure so a network
    // blip does not permanently lose the lead ([9.5]).
    if (captured) return;
    var state = answers();
    var first = String(state.first_name || '').trim();
    var email = String(state.email || '').trim();
    if (!first || !email) return;

    captured = true;
    post(form.getAttribute('data-capture-url'), {}).then(function (response) {
      if (!response || !response.ok) captured = false;
    }).catch(function () { captured = false; });
  }

  form.addEventListener('input', applyConditions);
  form.addEventListener('change', applyConditions);
  form.addEventListener('blur', attemptCapture, true);

  applyConditions();
  goToPage(0);
})();
