/**
 * Address suggestions under the checkout's Address field.
 *
 * Two sources behind one dropdown, chosen by the server in
 * `data-address-autocomplete`:
 *
 * - `google`: the deployment's own Maps key. Suggestions come from Places
 *   (`AutocompleteSuggestion`, US only), and choosing one reads its address
 *   components. If the key is refused -- not enabled for Places, or restricted
 *   to another API -- the field quietly switches to the second source.
 * - `emr`: the storefront's `/checkout/address/suggest/` and `/resolve/`
 *   endpoints, which ask the EMR. A suggestion there carries no ZIP, so
 *   choosing one asks `resolve` for the full address.
 *
 * Either way, choosing a suggestion fills the address line, city, state and
 * ZIP. A state the checkout does not ship to is left for the buyer to choose,
 * and a field the source could not supply keeps what the buyer typed.
 *
 * The field stays a plain text input throughout: the buyer can ignore the
 * dropdown and type, and with this script absent nothing changes at all.
 */
(function () {
  'use strict';

  var input = document.querySelector('[data-address-autocomplete]');
  var list = document.getElementById('checkout-address-suggestions');
  if (!input || !list) return;

  var source = input.getAttribute('data-address-autocomplete');
  var suggestUrl = input.getAttribute('data-suggest-url');
  var resolveUrl = input.getAttribute('data-resolve-url');
  var mapsKey = input.getAttribute('data-maps-key');

  var cityField = document.getElementById('checkout-city');
  var stateField = document.getElementById('checkout-state');
  var postalField = document.getElementById('checkout-postal');

  var MIN_LENGTH = 3;
  var DELAY_MS = 250;

  var items = [];
  var active = -1;
  var timer = null;
  var sequence = 0;
  var filling = false;

  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-controls', list.id);
  input.setAttribute('aria-expanded', 'false');
  // The browser's own address autofill would open over the suggestions.
  input.setAttribute('autocomplete', 'off');

  /* ---------- Sources ---------- */

  var emr = {
    suggest: function (query) {
      return fetch(suggestUrl + '?q=' + encodeURIComponent(query), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      })
        .then(function (response) { return response.ok ? response.json() : { suggestions: [] }; })
        .then(function (body) {
          return (body.suggestions || []).map(function (item) {
            return { label: item.address, main: item.main_text, address: item.address };
          });
        });
    },
    resolve: function (item) {
      return fetch(resolveUrl + '?address=' + encodeURIComponent(item.address), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      })
        .then(function (response) { return response.ok ? response.json() : { address: null }; })
        .then(function (body) { return body.address || null; });
    }
  };

  var places = null;
  var sessionToken = null;

  function loadGoogle() {
    if (places) return Promise.resolve(places);

    return new Promise(function (resolve, reject) {
      // Called by the Maps script when the key itself is refused.
      window.gm_authFailure = function () { fallBackToEmr(); };
      window.__asterAddressMapsReady = function () { resolve(); };

      var script = document.createElement('script');
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(mapsKey) +
        '&loading=async&v=weekly&callback=__asterAddressMapsReady';
      script.async = true;
      script.onerror = reject;
      document.head.appendChild(script);
    })
      .then(function () { return window.google.maps.importLibrary('places'); })
      .then(function (library) {
        places = library;
        return places;
      });
  }

  var google = {
    suggest: function (query) {
      return loadGoogle().then(function (library) {
        sessionToken = sessionToken || new library.AutocompleteSessionToken();

        return library.AutocompleteSuggestion.fetchAutocompleteSuggestions({
          input: query,
          includedRegionCodes: ['us'],
          sessionToken: sessionToken
        });
      }).then(function (result) {
        return (result.suggestions || [])
          .filter(function (suggestion) { return suggestion.placePrediction; })
          .map(function (suggestion) {
            var prediction = suggestion.placePrediction;
            return {
              label: prediction.text.toString(),
              main: prediction.mainText ? prediction.mainText.toString() : prediction.text.toString(),
              prediction: prediction
            };
          });
      });
    },
    resolve: function (item) {
      var place = item.prediction.toPlace();
      // A session ends with the place it chose; the next address starts another.
      sessionToken = null;

      return place.fetchFields({ fields: ['addressComponents'] }).then(function () {
        return fromComponents(place.addressComponents || []);
      });
    }
  };

  function fromComponents(components) {
    var part = function (type, short) {
      for (var i = 0; i < components.length; i += 1) {
        if (components[i].types.indexOf(type) !== -1) {
          return short ? components[i].shortText : components[i].longText;
        }
      }
      return '';
    };

    var line = [part('street_number', false), part('route', true)].filter(Boolean).join(' ');
    var subpremise = part('subpremise', false);

    return {
      address_line: subpremise ? line + ' #' + subpremise : line,
      city: part('locality', false) || part('sublocality', false) || part('postal_town', false) ||
        part('administrative_area_level_3', false),
      territory: part('administrative_area_level_1', true),
      postal_code: part('postal_code', false)
    };
  }

  var current = source === 'google' && mapsKey ? google : emr;

  function fallBackToEmr() {
    if (current === emr) return;
    current = emr;
    if (input.value.trim().length >= MIN_LENGTH) lookup(input.value.trim());
  }

  /* ---------- Dropdown ---------- */

  function close() {
    list.hidden = true;
    list.innerHTML = '';
    items = [];
    active = -1;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
  }

  function render(found) {
    items = found;
    active = -1;
    list.innerHTML = '';

    if (items.length === 0) {
      close();
      return;
    }

    items.forEach(function (item, index) {
      var option = document.createElement('li');
      option.id = 'checkout-address-option-' + index;
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');
      option.className = 'cursor-pointer px-4 py-2.5 text-sm text-heading aria-selected:bg-primary-light hover:bg-primary-light';

      var main = document.createElement('span');
      main.className = 'block font-medium';
      main.textContent = item.main;
      option.appendChild(main);

      if (item.label !== item.main) {
        var rest = document.createElement('span');
        rest.className = 'block text-xs text-body';
        rest.textContent = item.label;
        option.appendChild(rest);
      }

      // `mousedown` rather than `click`: it fires before the field's blur
      // closes the list.
      option.addEventListener('mousedown', function (event) {
        event.preventDefault();
        choose(index);
      });
      list.appendChild(option);
    });

    if (current === google) {
      var credit = document.createElement('li');
      credit.setAttribute('role', 'presentation');
      credit.className = 'px-4 pb-1 pt-2 text-right text-xs text-muted';
      credit.textContent = 'Powered by Google';
      list.appendChild(credit);
    }

    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function highlight(index) {
    var options = list.querySelectorAll('[role="option"]');
    if (options.length === 0) return;

    active = (index + options.length) % options.length;
    options.forEach(function (option, i) {
      option.setAttribute('aria-selected', i === active ? 'true' : 'false');
    });
    input.setAttribute('aria-activedescendant', options[active].id);
    options[active].scrollIntoView({ block: 'nearest' });
  }

  function lookup(query) {
    var ticket = ++sequence;

    current.suggest(query).then(function (found) {
      // A slower answer to an earlier keystroke must not replace a newer one.
      if (ticket === sequence && document.activeElement === input) render(found.slice(0, 5));
    }).catch(function () {
      if (current === google) {
        fallBackToEmr();
      } else if (ticket === sequence) {
        close();
      }
    });
  }

  /* ---------- Filling the form ---------- */

  function setField(field, value) {
    if (!field || !value) return;
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function fill(address) {
    if (!address) return;

    // The events the fill dispatches are for listeners such as the floating
    // labels, not for this script to look the address up again.
    filling = true;
    setField(input, address.address_line);
    setField(cityField, address.city);
    setField(postalField, address.postal_code);

    if (stateField && address.territory &&
        stateField.querySelector('option[value="' + address.territory.replace(/[^A-Z]/g, '') + '"]')) {
      setField(stateField, address.territory);
    }
    filling = false;
  }

  function choose(index) {
    var item = items[index];
    if (!item) return;

    sequence += 1;
    input.value = item.main;
    close();

    current.resolve(item).then(fill).catch(function () {
      // The street the buyer picked stays; the rest is theirs to type.
    });
  }

  /* ---------- Events ---------- */

  input.addEventListener('input', function () {
    if (filling) return;
    window.clearTimeout(timer);
    var query = input.value.trim();

    if (query.length < MIN_LENGTH) {
      sequence += 1;
      close();
      return;
    }

    timer = window.setTimeout(function () { lookup(query); }, DELAY_MS);
  });

  input.addEventListener('keydown', function (event) {
    if (list.hidden) return;

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      highlight(active + 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      highlight(active - 1);
    } else if (event.key === 'Enter' && active >= 0) {
      // Choosing a suggestion, not submitting the order.
      event.preventDefault();
      choose(active);
    } else if (event.key === 'Escape') {
      close();
    }
  });

  input.addEventListener('blur', function () {
    window.setTimeout(close, 150);
  });
})();
