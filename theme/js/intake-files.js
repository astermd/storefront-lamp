/**
 * File-upload and signature questions on the server-rendered intake form.
 *
 * A picked file goes to the EMR at once, through the storefront's
 * `/intake/upload/` endpoints (the EMR call needs this deployment's
 * credential, which never reaches a page). What comes back -- where the EMR
 * stored the file -- goes into a hidden input, and that is all the form posts.
 * The route follows the EMR's two flows:
 *
 * - a document or an image that fits in one request goes up whole;
 * - a video, or anything larger, is started, sent in numbered parts and
 *   finished. A part that fails, or a file removed mid-way, aborts the upload
 *   so the EMR drops what it holds.
 *
 * Parts go three at a time rather than all at once. Each one is a request
 * this server holds open while it relays ten megabytes, and a whole video's
 * worth at once would take every PHP worker a small server has.
 *
 * A file is checked against the question's rules before a byte is sent --
 * its kind by extension, its size, how many the question takes -- and a
 * refusal is said under the question. The server checks the same rules.
 *
 * While a file is still going up, or one has failed, the stepper will not
 * leave the page (theme/js/intake.js reads `data-intake-busy` and
 * `data-intake-failed` off the field), so a submitted answer always names a
 * stored file.
 *
 * A signature is drawn on a canvas and kept as a PNG data URL in its hidden
 * input. It is small, so it travels in the answer and is never uploaded.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-intake-form]');
  if (!form) return;

  var csrfInput = form.querySelector('input[name="_csrf"]');
  var stepInput = form.querySelector('input[name="step"]');
  var urls = {
    upload: form.getAttribute('data-upload-url'),
    start: form.getAttribute('data-upload-start-url'),
    part: form.getAttribute('data-upload-part-url'),
    finish: form.getAttribute('data-upload-finish-url'),
    abort: form.getAttribute('data-upload-abort-url')
  };

  /** The EMR's own per-request cap, less room for the request around the file. */
  var EMR_REQUEST_BYTES = 10 * 1024 * 1024 - 64 * 1024;
  /** Every part but the last must be at least this, or the EMR cannot assemble them. */
  var MIN_PART_BYTES = 5 * 1024 * 1024;
  var PARALLEL_PARTS = 3;
  var VIDEO = ['mp4', 'mov', 'webm'];

  var requestBytes = Math.min(EMR_REQUEST_BYTES, Number(form.getAttribute('data-upload-request-bytes')) || EMR_REQUEST_BYTES);

  function megabytes(bytes) {
    var value = bytes / (1024 * 1024);
    return (Math.round(value * 10) / 10) + ' MB';
  }

  function extensionOf(name) {
    var dot = name.lastIndexOf('.');
    return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
  }

  function fields(extra) {
    var data = new FormData();
    data.set('_csrf', csrfInput ? csrfInput.value : '');
    data.set('step', stepInput ? stepInput.value : '');
    Object.keys(extra).forEach(function (key) {
      var value = extra[key];
      if (Array.isArray(value)) {
        data.set(key, value[0], value[1]);
      } else {
        data.set(key, value);
      }
    });
    return data;
  }

  /**
   * One request, as a promise of its JSON. XHR rather than fetch because
   * only XHR reports how much of a body has been sent.
   */
  function send(url, data, onProgress, attempt) {
    var xhr = new XMLHttpRequest();
    var promise = new Promise(function (resolve, reject) {
      xhr.open('POST', url);
      xhr.setRequestHeader('X-Requested-With', 'fetch');
      xhr.responseType = 'json';
      if (onProgress) {
        xhr.upload.addEventListener('progress', function (event) { onProgress(event.loaded); });
      }
      xhr.addEventListener('load', function () {
        var body = xhr.response || {};
        if (xhr.status >= 200 && xhr.status < 300) {
          resolve(body);
        } else {
          // A body over this server's limit is turned away before the app
          // sees it, so there is no message of ours to show.
          reject(new Error(body.error || (xhr.status === 413
            ? 'This file is too large for this site.'
            : 'The upload did not go through. Please try again.')));
        }
      });
      xhr.addEventListener('error', function () { reject(new Error('The upload did not go through. Please check your connection and try again.')); });
      xhr.addEventListener('abort', function () { reject(new Error('cancelled')); });
      xhr.send(data);
    });
    if (attempt) attempt.requests.push(xhr);
    return promise;
  }

  // ---- file-upload questions ----------------------------------------------

  Array.prototype.forEach.call(form.querySelectorAll('[data-intake-upload]'), function (box) {
    var wrapper = box.closest('[data-intake-field]');
    var name = wrapper ? wrapper.getAttribute('data-intake-field') : '';
    var input = box.querySelector('[data-intake-file-input]');
    var drop = box.querySelector('[data-intake-file-drop]');
    var list = box.querySelector('[data-intake-file-list]');
    var message = box.querySelector('[data-intake-file-message]');
    var maxFiles = Number(box.getAttribute('data-max-files')) || Infinity;
    var maxBytes = Number(box.getAttribute('data-max-bytes')) || 0;
    var maxLabel = box.getAttribute('data-max-label') || megabytes(maxBytes);
    var accepted = (box.getAttribute('data-accept') || '').split(',').map(function (one) {
      return one.trim().replace(/^\./, '').toLowerCase();
    }).filter(Boolean);

    function say(text) {
      if (!message) return;
      message.textContent = text || '';
      message.classList.toggle('hidden', !text);
    }

    function items() {
      return Array.prototype.slice.call(list.querySelectorAll('[data-intake-file]'));
    }

    function refresh() {
      var all = items();
      var busy = all.some(function (item) { return item.getAttribute('data-state') === 'uploading'; });
      var failed = all.some(function (item) { return item.getAttribute('data-state') === 'failed'; });
      if (wrapper) {
        wrapper.setAttribute('data-intake-busy', busy ? '1' : '0');
        wrapper.setAttribute('data-intake-failed', failed ? '1' : '0');
      }
      // Conditions and the stepper read answers off `change`.
      box.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function refusal(file) {
      var extension = extensionOf(file.name);
      if (accepted.indexOf(extension) === -1) return file.name + ' is not a file type this question accepts.';
      if (!file.size) return file.name + ' is empty.';
      if (file.size > maxBytes) return file.name + ' is larger than ' + maxLabel + '.';

      var parts = VIDEO.indexOf(extension) !== -1 || file.size > requestBytes;
      if (parts && file.size > requestBytes && requestBytes < MIN_PART_BYTES) {
        return file.name + ' is too large for this site.';
      }
      return null;
    }

    function row(file) {
      var item = document.createElement('li');
      item.setAttribute('data-intake-file', '');
      item.className = 'flex flex-col gap-2 rounded-sm border border-border bg-background px-3.5 py-2.5';

      var line = document.createElement('div');
      line.className = 'flex items-center gap-3';

      var label = document.createElement('span');
      label.className = 'min-w-0 flex-1 truncate text-sm text-heading';
      label.setAttribute('data-intake-file-name', '');
      label.textContent = file.name;

      var status = document.createElement('span');
      status.className = 'shrink-0 text-xs text-muted';
      status.setAttribute('data-intake-file-status', '');

      var retry = document.createElement('button');
      retry.type = 'button';
      retry.hidden = true;
      retry.className = 'shrink-0 rounded-sm px-2 py-1 text-xs font-medium text-primary underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary';
      retry.setAttribute('data-intake-file-retry', '');
      retry.textContent = 'Retry';

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'shrink-0 rounded-sm px-2 py-1 text-xs font-medium text-body underline transition-colors hover:text-danger focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary';
      remove.setAttribute('data-intake-file-remove', '');
      remove.setAttribute('aria-label', 'Remove ' + file.name);
      remove.textContent = 'Remove';

      var bar = document.createElement('div');
      bar.className = 'h-1 overflow-hidden rounded-full bg-divider';
      bar.setAttribute('role', 'progressbar');
      bar.setAttribute('aria-label', 'Uploading ' + file.name);
      bar.setAttribute('aria-valuemin', '0');
      bar.setAttribute('aria-valuemax', '100');
      var fill = document.createElement('div');
      fill.className = 'h-full w-0 bg-primary transition-[width]';
      fill.setAttribute('data-intake-file-progress', '');
      bar.appendChild(fill);

      line.appendChild(label);
      line.appendChild(status);
      line.appendChild(retry);
      line.appendChild(remove);
      item.appendChild(line);
      item.appendChild(bar);
      return item;
    }

    function progress(item, percent) {
      var fill = item.querySelector('[data-intake-file-progress]');
      var bar = fill ? fill.parentNode : null;
      var value = Math.max(0, Math.min(100, Math.round(percent)));
      if (fill) fill.style.width = value + '%';
      if (bar) bar.setAttribute('aria-valuenow', String(value));
      var status = item.querySelector('[data-intake-file-status]');
      if (status) status.textContent = value + '%';
    }

    function settle(item, state, text) {
      item.setAttribute('data-state', state);
      var bar = item.querySelector('[role="progressbar"]');
      if (bar) bar.hidden = state !== 'uploading';
      var status = item.querySelector('[data-intake-file-status]');
      if (status) {
        status.textContent = text || '';
        status.className = 'shrink-0 text-xs ' + (state === 'failed' ? 'font-medium text-danger' : 'text-muted');
      }
      var retry = item.querySelector('[data-intake-file-retry]');
      if (retry) retry.hidden = state !== 'failed';
    }

    function attach(item, file) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = name + '[]';
      hidden.value = JSON.stringify(file);
      hidden.setAttribute('data-intake-file-ref', '');
      item.insertBefore(hidden, item.firstChild);
    }

    function whole(file, attempt) {
      return send(urls.upload, fields({ field: name, file: [file, file.name] }), function (loaded) {
        attempt.report(loaded / file.size * 100);
      }, attempt).then(function (body) { return body.file; });
    }

    function inParts(file, attempt) {
      return send(urls.start, fields({ field: name, file_name: file.name, file_size: String(file.size) }), null, attempt)
        .then(function (body) {
          attempt.uploadId = body.upload_id;
          var size = Math.min(requestBytes, Number(body.part_bytes) || requestBytes);
          var slices = [];
          for (var offset = 0, number = 1; offset < file.size; offset += size, number += 1) {
            slices.push({ number: number, blob: file.slice(offset, offset + size) });
          }

          var sent = slices.map(function () { return 0; });
          var done = [];
          var next = 0;

          function worker() {
            if (attempt.cancelled) return Promise.reject(new Error('cancelled'));
            if (next >= slices.length) return Promise.resolve();
            var index = next;
            next += 1;
            var slice = slices[index];
            var data = fields({ upload_id: attempt.uploadId, part_number: String(slice.number), file: [slice.blob, file.name] });
            return send(urls.part, data, function (loaded) {
              sent[index] = Math.min(loaded, slice.blob.size);
              attempt.report(sent.reduce(function (sum, one) { return sum + one; }, 0) / file.size * 100);
            }, attempt).then(function (part) {
              sent[index] = slice.blob.size;
              done.push({ part_number: Number(part.part_number) || slice.number, etag: part.etag });
              return worker();
            });
          }

          var workers = [];
          for (var i = 0; i < Math.min(PARALLEL_PARTS, slices.length); i += 1) workers.push(worker());

          return Promise.all(workers).then(function () {
            done.sort(function (a, b) { return a.part_number - b.part_number; });
            return send(urls.finish, fields({ upload_id: attempt.uploadId, parts: JSON.stringify(done) }), null, attempt);
          }).then(function (finished) {
            attempt.uploadId = null;
            return finished.file;
          });
        });
    }

    function abandon(attempt) {
      attempt.cancelled = true;
      attempt.requests.forEach(function (xhr) { try { xhr.abort(); } catch (e) { /* already done */ } });
      if (attempt.uploadId) {
        // Best effort: the EMR keeps a started upload's parts until told.
        send(urls.abort, fields({ upload_id: attempt.uploadId })).catch(function () {});
        attempt.uploadId = null;
      }
    }

    function begin(item, file) {
      var attempt = {
        requests: [],
        uploadId: null,
        cancelled: false,
        report: function (percent) { if (!attempt.cancelled) progress(item, Math.min(99, percent)); }
      };
      item.__attempt = attempt;
      item.__file = file;
      settle(item, 'uploading', '0%');
      progress(item, 0);
      refresh();

      var parts = VIDEO.indexOf(extensionOf(file.name)) !== -1 || file.size > requestBytes;
      (parts ? inParts(file, attempt) : whole(file, attempt)).then(function (stored) {
        if (attempt.cancelled) return;
        if (!stored || !stored.path) throw new Error('The upload did not go through. Please try again.');
        attach(item, stored);
        settle(item, 'uploaded', 'Uploaded');
        if (!item.querySelector('[data-lucide], svg')) {
          var icon = document.createElement('i');
          icon.setAttribute('data-lucide', 'file-check');
          icon.setAttribute('aria-hidden', 'true');
          icon.className = 'size-4 shrink-0 text-success';
          var line = item.querySelector('[data-intake-file-name]');
          line.parentNode.insertBefore(icon, line);
          if (window.lucide) window.lucide.createIcons();
        }
        refresh();
      }).catch(function (error) {
        if (attempt.cancelled) return;
        abandon(attempt);
        attempt.cancelled = false;
        settle(item, 'failed', error && error.message && error.message !== 'cancelled'
          ? error.message
          : 'The upload did not go through. Please try again.');
        refresh();
      });
    }

    function add(files) {
      var problems = [];
      Array.prototype.forEach.call(files, function (file) {
        var problem = refusal(file);
        if (problem) { problems.push(problem); return; }

        if (items().length >= maxFiles) {
          if (maxFiles === 1) {
            // A one-file question takes the new file in place of the old.
            items().forEach(discard);
          } else {
            problems.push('This question takes at most ' + maxFiles + ' files.');
            return;
          }
        }

        var item = row(file);
        list.appendChild(item);
        begin(item, file);
      });
      say(problems.join(' '));
      if (window.lucide) window.lucide.createIcons();
    }

    function discard(item) {
      if (item.__attempt) abandon(item.__attempt);
      item.parentNode.removeChild(item);
    }

    input.addEventListener('change', function () {
      add(input.files || []);
      // Cleared, so choosing the same file again after removing it is a change.
      input.value = '';
    });

    list.addEventListener('click', function (event) {
      var remove = event.target.closest ? event.target.closest('[data-intake-file-remove]') : null;
      var retry = event.target.closest ? event.target.closest('[data-intake-file-retry]') : null;
      var item = (remove || retry) ? (remove || retry).closest('[data-intake-file]') : null;
      if (!item) return;
      event.preventDefault();

      if (remove) {
        discard(item);
        say('');
        refresh();
        input.focus();
        return;
      }

      if (retry && item.__file) begin(item, item.__file);
    });

    if (drop && box.getAttribute('data-drag-drop') === '1') {
      ['dragenter', 'dragover'].forEach(function (type) {
        drop.addEventListener(type, function (event) {
          if (input.disabled) return;
          event.preventDefault();
          drop.setAttribute('data-dragging', '');
        });
      });
      ['dragleave', 'drop'].forEach(function (type) {
        drop.addEventListener(type, function () { drop.removeAttribute('data-dragging'); });
      });
      drop.addEventListener('drop', function (event) {
        if (input.disabled) return;
        event.preventDefault();
        var dropped = event.dataTransfer ? event.dataTransfer.files : [];
        add(maxFiles === 1 && dropped.length > 1 ? [dropped[0]] : dropped);
      });
    }

    refresh();
  });

  // ---- signatures -----------------------------------------------------------

  Array.prototype.forEach.call(form.querySelectorAll('[data-intake-signature]'), function (box) {
    var canvas = box.querySelector('[data-intake-signature-pad]');
    var value = box.querySelector('[data-intake-signature-value]');
    var clear = box.querySelector('[data-intake-signature-clear]');
    if (!canvas || !value || !canvas.getContext) return;

    var width = canvas.width;
    var height = canvas.height;
    // Drawn at the screen's density so a stroke is sharp, and exported at
    // that size: the clinician reads it, the file stays small either way.
    var scale = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = width * scale;
    canvas.height = height * scale;
    var context = canvas.getContext('2d');
    var drawing = false;
    var drawn = false;
    var last = null;

    function blank() {
      context.setTransform(1, 0, 0, 1, 0, 0);
      // White rather than transparent: a transparent PNG shown on a dark
      // background is a signature nobody can see.
      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.setTransform(scale, 0, 0, scale, 0, 0);
      context.lineWidth = 2.2;
      context.lineCap = 'round';
      context.lineJoin = 'round';
      context.strokeStyle = '#111827';
    }

    function point(event) {
      var rect = canvas.getBoundingClientRect();
      return {
        x: (event.clientX - rect.left) * (width / rect.width),
        y: (event.clientY - rect.top) * (height / rect.height)
      };
    }

    function store() {
      value.value = drawn ? canvas.toDataURL('image/png') : '';
      value.dispatchEvent(new Event('change', { bubbles: true }));
    }

    blank();
    if (value.value.indexOf('data:image/png;base64,') === 0) {
      var image = new Image();
      image.onload = function () {
        context.drawImage(image, 0, 0, width, height);
        drawn = true;
      };
      image.src = value.value;
    }

    canvas.addEventListener('pointerdown', function (event) {
      if (event.button !== undefined && event.button !== 0) return;
      drawing = true;
      last = point(event);
      canvas.setPointerCapture(event.pointerId);
      // A tap is a dot, not nothing.
      context.beginPath();
      context.arc(last.x, last.y, context.lineWidth / 2, 0, Math.PI * 2);
      context.fillStyle = context.strokeStyle;
      context.fill();
      drawn = true;
      event.preventDefault();
    });

    canvas.addEventListener('pointermove', function (event) {
      if (!drawing) return;
      var here = point(event);
      context.beginPath();
      context.moveTo(last.x, last.y);
      context.lineTo(here.x, here.y);
      context.stroke();
      last = here;
      event.preventDefault();
    });

    function end() {
      if (!drawing) return;
      drawing = false;
      store();
    }

    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);

    if (clear) {
      clear.addEventListener('click', function () {
        drawn = false;
        blank();
        store();
      });
    }
  });
})();
