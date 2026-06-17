// Build a content-addressed asset manifest the PHP FrontendLoader reads:
//   { "telemetry.js": "/build/telemetry.abcd1234.js", "dashboard.js": ... }
// Each app emits a single self-contained bundle "<entry>.<hash>.js" into build/.
// With clean:false, stale hashes can linger, so we pick the NEWEST file per
// entry (by mtime). Keeps deps minimal (no webpack-manifest-plugin).

import { readdirSync, statSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const buildDir = join(here, '..', 'build');

const newest = {}; // entry -> { file, mtime }
for (const f of readdirSync(buildDir)) {
  if (!f.endsWith('.js')) continue;
  const entry = f.replace(/\.[0-9a-f]{8}\.js$/, '.js'); // dashboard.abcd1234.js -> dashboard.js
  const mtime = statSync(join(buildDir, f)).mtimeMs;
  if (!newest[entry] || mtime > newest[entry].mtime) {
    newest[entry] = { file: f, mtime };
  }
}

const manifest = {};
for (const [entry, { file }] of Object.entries(newest)) {
  manifest[entry] = '/build/' + file;
}

writeFileSync(join(buildDir, 'asset-manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log('wrote build/asset-manifest.json', manifest);
