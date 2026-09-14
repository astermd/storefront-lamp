// Dev watcher: re-runs the asset build whenever the theme changes, so a
// designer edits a file and refreshes nothing.
//
// It watches theme/templates as well as theme/css and theme/js, which is not
// obvious: Tailwind's `@source` directives in theme/css/app.css scan the
// templates, so a class used for the first time in a .twig file has to
// trigger a CSS rebuild or the markup ships referring to a rule that was
// never compiled. That exact failure -- a class name Tailwind could not see
// -- is what made every intake field collapse into one grid cell.
//
// Not a bundler and not a dev server. The build is the same one `npm run
// build` runs, so what a designer previews is what gets committed; the page
// refreshes itself through theme/js/livereload.js, which polls the manifest
// this rewrites.
import { execFileSync } from 'node:child_process';
import { watch } from 'node:fs';
import { join } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const WATCHED = ['theme/css', 'theme/js', 'theme/templates'];

// A single save can surface as several events (write, truncate, rename), and
// editors that write atomically produce a rename plus a create. Rebuilding on
// each would run the build three times for one keystroke, so events inside
// this window collapse into one run.
const DEBOUNCE_MS = 80;

let pending = null;
let building = false;

function build(reason) {
  if (building) return;
  building = true;
  const started = Date.now();
  try {
    execFileSync('node', [join(root, 'build/build-assets.mjs')], { cwd: root, stdio: 'pipe' });
    console.log(`rebuilt in ${Date.now() - started}ms  (${reason})`);
  } catch (error) {
    // Stay alive. A broken @source or a half-written file is something the
    // designer fixes in the next save, and an exit here would mean restarting
    // the watcher to find that out.
    console.error(`build failed  (${reason})\n${error.stderr?.toString() ?? error.message}`);
  } finally {
    building = false;
  }
}

function schedule(reason) {
  clearTimeout(pending);
  pending = setTimeout(() => build(reason), DEBOUNCE_MS);
}

for (const dir of WATCHED) {
  watch(join(root, dir), { recursive: true }, (_event, file) => {
    // Editor scratch files: vim's 4913, JetBrains' ___jb_tmp___, and the
    // dotfile swap files most editors leave behind mid-save.
    if (!file || /(^|\/)\.|~$|\.sw[px]$|___jb_/.test(file)) return;
    schedule(`${dir}/${file}`);
  });
}

console.log(`watching ${WATCHED.join(', ')} — ctrl-c to stop`);
build('initial');
