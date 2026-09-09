// Build script: compiles Tailwind CSS and copies theme JS into
// public/assets/build with 8-char content hashes + manifest.json.
// Dev-only — built output is committed so servers never need Node.
import { createHash } from 'node:crypto';
import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, readdirSync, copyFileSync, rmSync, statSync } from 'node:fs';
import { join, basename } from 'node:path';

const root = new URL('..', import.meta.url).pathname;
const outDir = join(root, 'public/assets/build');
mkdirSync(outDir, { recursive: true });

// Clean previous hashed output (keep manifest until rewritten).
for (const f of readdirSync(outDir)) {
  if (f !== '.gitkeep') rmSync(join(outDir, f));
}

const manifest = {};
const hashOf = (buf) => createHash('sha256').update(buf).digest('hex').slice(0, 8);

// 1. CSS via Tailwind CLI
const cssTmp = join(outDir, 'app.tmp.css');
execSync(`npx @tailwindcss/cli -i theme/css/app.css -o ${cssTmp} --minify`, { cwd: root, stdio: 'inherit' });
const css = readFileSync(cssTmp);
rmSync(cssTmp);
const cssName = `app.${hashOf(css)}.css`;
writeFileSync(join(outDir, cssName), css);
manifest['app.css'] = cssName;

// 2. JS modules (each file hashed individually; vendor/ included)
const jsDir = join(root, 'theme/js');
const walk = (dir) => readdirSync(dir).flatMap((f) => {
  const p = join(dir, f);
  return statSync(p).isDirectory() ? walk(p) : [p];
});
for (const file of walk(jsDir).filter((p) => p.endsWith('.js'))) {
  const buf = readFileSync(file);
  const name = basename(file);
  const hashed = name.replace(/\.js$/, `.${hashOf(buf)}.js`);
  copyFileSync(file, join(outDir, hashed));
  manifest[name] = hashed;
}

writeFileSync(join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log('Built', Object.keys(manifest).length, 'assets');
