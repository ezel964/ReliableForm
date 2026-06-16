// Build a content-addressed asset manifest the PHP FrontendLoader can read:
//   { "telemetry.js": "/build/telemetry.abcd1234.js", ... }
// Keeps deps minimal (no webpack-manifest-plugin) — we just scan build/.

import { readdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const buildDir = join(here, '..', 'build');

const files = readdirSync(buildDir).filter((f) => f.endsWith('.js'));
const manifest = {};
for (const f of files) {
  const entry = f.replace(/\.[0-9a-f]{8}\.js$/, '.js'); // telemetry.abcd1234.js -> telemetry.js
  manifest[entry] = '/build/' + f;
}

writeFileSync(join(buildDir, 'asset-manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log('wrote build/asset-manifest.json', manifest);
