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
import { runRules, report } from './lib/token-rules.mjs';

const ROOT = join(import.meta.dirname, '..');
const table = JSON.parse(readFileSync(join(ROOT, 'scripts', 'lib', 'dre-tokens-fallback.json'), 'utf8'));

const findings = runRules({
  root: ROOT,
  dirs: ['asset/css', 'view'],
  extensions: ['.css', '.phtml'],
  table,
  localPrefixes: ['--dre-ld-'],
});

report('Design-token contract', findings);
