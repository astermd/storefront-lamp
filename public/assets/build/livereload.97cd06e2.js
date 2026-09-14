/**
 * Reloads the page when the theme is rebuilt. Development only.
 *
 * `npm run dev` re-runs the asset build on every save; this is what turns
 * that into a browser the designer does not have to refresh. It watches the
 * build manifest rather than any one file because the build content-hashes
 * its output -- a CSS edit, a JS edit and a class added to a Twig template
 * all land as a changed filename in there, and nothing else has to be
 * enumerated.
 *
 * It is a full page reload, not hot module replacement: state in the page is
 * lost, which for a storefront theme is the right trade. Preserving it would
 * mean a bundler owning the pipeline, and the built output is committed here
 * precisely so servers never run Node.
 *
 * layouts/base.twig loads this only outside production ({@see
 * AsterMD\Storefront\Tests\Theme\LiveReloadTest}). Shipped to real visitors it
 * would be a request a second, per open tab, forever.
 */
(function () {
  var MANIFEST = '/assets/build/manifest.json';
  var INTERVAL_MS = 1000;
  var seen = null;

  function check() {
    // `cache: no-store` because the manifest is the thing being watched: a
    // cached copy reports the build the page was loaded with, forever.
    fetch(MANIFEST, { cache: 'no-store' })
      .then(function (response) { return response.ok ? response.text() : null; })
      .catch(function () { return null; })
      .then(function (body) {
        // A null body is a build in progress or a dev server that has gone
        // away. Neither is a change, and reloading on it would fight a
        // rebuild that is halfway through writing the directory.
        if (body === null) return;

        if (seen === null) {
          seen = body;

          return;
        }

        if (body !== seen) window.location.reload();
      });
  }

  window.setInterval(check, INTERVAL_MS);
  check();
})();
