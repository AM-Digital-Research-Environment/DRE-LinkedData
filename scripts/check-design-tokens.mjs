#!/usr/bin/env node
/**
 * Design-token contract lint — this module's CONFIG for the shared rule set.
 *
 *   node scripts/check-design-tokens.mjs        (composer lint:tokens)
 *
 * The rules are in scripts/lib/token-rules.mjs and the token table in
 * scripts/lib/dre-tokens-fallback.json, both vendored verbatim from DRE-theme
 * (refresh there with `npm run vendor:lint ../DRE-LinkedData`). They check that
 * every `var(--token, literal)` fallback is the theme's actual value, and that
 * no colour, font size, spacing, radius or z-index is authored off the theme's
 * scales — the page block must read as part of the theme, not beside it.
 *
 * No allowlist: the module started clean and should stay that way.
 * Exit code 1 on any finding; prints file:line for each.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { runRules, report } from './lib/token-rules.mjs';

const ROOT = join(import.meta.dirname, '..');

// The vendored copies must match the manifest DRE-theme wrote beside them, as
// VENDORED.sha256 says this script checks: a rule or a token value is changed
// in DRE-theme and re-vendored, never edited here. Line endings are normalised
// so a CRLF checkout hashes as the LF files the theme wrote. (The same check
// as DRESearch's and DRE Visualizations' token lints.)
const manifest = readFileSync(join(ROOT, 'scripts', 'lib', 'VENDORED.sha256'), 'utf8');
let vendored = 0;
for (const line of manifest.split('\n')) {
  const [, hash, path] = /^([0-9a-f]{64}) {2}(.+)$/.exec(line.trim()) ?? [];
  if (!hash) continue;
  vendored++;
  const text = readFileSync(join(ROOT, path), 'utf8').replace(/\r\n/g, '\n');
  if (createHash('sha256').update(text, 'utf8').digest('hex') !== hash) {
    console.error(
      `${path} differs from the copy DRE-theme vendored. ` +
        'Change it in DRE-theme and run `npm run vendor:lint ../DRE-LinkedData` there.',
    );
    process.exit(1);
  }
}
if (vendored === 0) {
  console.error('scripts/lib/VENDORED.sha256 lists no files: re-vendor from DRE-theme.');
  process.exit(1);
}

const table = JSON.parse(readFileSync(join(ROOT, 'scripts', 'lib', 'dre-tokens-fallback.json'), 'utf8'));

const findings = runRules({
  root: ROOT,
  dirs: ['asset/css', 'view'],
  extensions: ['.css', '.phtml'],
  table,
  localPrefixes: ['--dre-ld-'],
});

report('Design-token contract', findings);
