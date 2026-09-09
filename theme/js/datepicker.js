/**
 * Extracted verbatim from the static design mockup — unmodified. The mockup
 * is not part of this repository, so this file is the source now: edit it
 * directly.
 */
/**
 * Custom date picker — replaces the native <input type="date"> calendar, which
 * renders with browser/OS chrome (blue accents, system font) that CSS cannot
 * restyle. This version is plain HTML/CSS driven by our own design tokens, so
 * it always matches the active theme.
 *
 * Markup contract (see intake.html for a full example):
 *   <div data-datepicker>
 *     <input data-datepicker-input type="text" placeholder="MM / DD / YYYY">
 *     <button data-datepicker-toggle>...</button>
 *     <div data-datepicker-panel>
 *       <button data-datepicker-month-label></button>
 *       <button data-datepicker-prev></button>
 *       <button data-datepicker-next></button>
 *       <div data-datepicker-days></div>
 *       <button data-datepicker-clear></button>
 *       <button data-datepicker-today></button>
 *     </div>
 *   </div>
 */
(function () {
  'use strict';

  var MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function formatDate(date) {
    return pad(date.getMonth() + 1) + ' / ' + pad(date.getDate()) + ' / ' + date.getFullYear();
  }

  function isSameDay(a, b) {
    return !!a && !!b &&
      a.getFullYear() === b.getFullYear() &&
      a.getMonth() === b.getMonth() &&
      a.getDate() === b.getDate();
  }

  function parseInputValue(value) {
    var match = /^(\d{1,2})\s*\/\s*(\d{1,2})\s*\/\s*(\d{4})$/.exec((value || '').trim());
    if (!match) return null;
    var parsed = new Date(Number(match[3]), Number(match[1]) - 1, Number(match[2]));
    return isNaN(parsed.getTime()) ? null : parsed;
  }

  function initDatePicker(root) {
    var input = root.querySelector('[data-datepicker-input]');
    var toggle = root.querySelector('[data-datepicker-toggle]');
    var panel = root.querySelector('[data-datepicker-panel]');
    var monthLabel = root.querySelector('[data-datepicker-month-label]');
    var prevBtn = root.querySelector('[data-datepicker-prev]');
    var nextBtn = root.querySelector('[data-datepicker-next]');
    var daysGrid = root.querySelector('[data-datepicker-days]');
    var clearBtn = root.querySelector('[data-datepicker-clear]');
    var todayBtn = root.querySelector('[data-datepicker-today]');
    if (!input || !panel || !daysGrid) return;

    var today = new Date();
    today.setHours(0, 0, 0, 0);

    var selected = parseInputValue(input.value);
    var viewDate = selected ? new Date(selected.getFullYear(), selected.getMonth(), 1)
                             : new Date(today.getFullYear(), today.getMonth(), 1);

    function open() {
      panel.classList.remove('hidden');
      toggle && toggle.setAttribute('aria-expanded', 'true');
    }

    function close() {
      panel.classList.add('hidden');
      toggle && toggle.setAttribute('aria-expanded', 'false');
    }

    function isOpen() {
      return !panel.classList.contains('hidden');
    }

    function render() {
      monthLabel.textContent = MONTH_NAMES[viewDate.getMonth()] + ' ' + viewDate.getFullYear();

      var firstOfMonth = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
      var startOffset = firstOfMonth.getDay();
      var gridStart = new Date(firstOfMonth);
      gridStart.setDate(gridStart.getDate() - startOffset);

      daysGrid.innerHTML = '';
      for (var i = 0; i < 42; i++) {
        var cellDate = new Date(gridStart);
        cellDate.setDate(gridStart.getDate() + i);

        var cell = document.createElement('button');
        cell.type = 'button';
        cell.textContent = String(cellDate.getDate());

        var inCurrentMonth = cellDate.getMonth() === viewDate.getMonth();
        var isToday = isSameDay(cellDate, today);
        var isSelected = isSameDay(cellDate, selected);

        cell.className = 'flex size-9 items-center justify-center rounded-full text-sm transition-colors mx-auto';
        if (isSelected) {
          cell.className += ' bg-primary font-medium text-primary-foreground';
        } else if (isToday) {
          cell.className += ' border border-primary font-medium text-primary';
        } else if (inCurrentMonth) {
          cell.className += ' text-heading hover:bg-divider';
        } else {
          cell.className += ' text-muted/50 hover:bg-divider';
        }

        (function (d) {
          cell.addEventListener('click', function () {
            selected = d;
            input.value = formatDate(d);
            input.dispatchEvent(new Event('change', { bubbles: true }));
            close();
          });
        })(cellDate);

        daysGrid.appendChild(cell);
      }
    }

    function goToMonth(offset) {
      viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + offset, 1);
      render();
    }

    if (toggle) toggle.addEventListener('click', function () { isOpen() ? close() : open(); });
    input.addEventListener('focus', open);
    input.addEventListener('input', function () {
      var parsed = parseInputValue(input.value);
      if (parsed) {
        selected = parsed;
        viewDate = new Date(parsed.getFullYear(), parsed.getMonth(), 1);
        render();
      }
    });

    if (prevBtn) prevBtn.addEventListener('click', function () { goToMonth(-1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { goToMonth(1); });

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        selected = null;
        input.value = '';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        render();
        input.focus();
      });
    }

    if (todayBtn) {
      todayBtn.addEventListener('click', function () {
        selected = new Date(today);
        viewDate = new Date(today.getFullYear(), today.getMonth(), 1);
        input.value = formatDate(selected);
        input.dispatchEvent(new Event('change', { bubbles: true }));
        render();
        close();
      });
    }

    document.addEventListener('click', function (event) {
      if (isOpen() && !root.contains(event.target)) close();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen()) {
        close();
        input.focus();
      }
    });

    render();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-datepicker]').forEach(initDatePicker);
  });
})();
