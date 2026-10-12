/**
 * VENDORED from DRE-theme — do not edit here.
 *
 * Source of truth: DRE-theme/scripts/lib/token-rules.mjs
 * Refresh with:    (in DRE-theme) npm run vendor:lint
 *
 * The design-token rules are shared across DRE-theme, DRE-Visualizations and
 * DRESearch so the three cannot drift apart again. Repo-specific paths and
 * allowlists belong in this repo's scripts/check-design-tokens.mjs, not here.
 */
/**
 * The shared design-token rules — ONE rule set for DRE-theme, DRE-Visualizations
 * and DRESearch.
 *
 * WHY THIS IS SHARED. All three repositories had a `lint:tokens`, and both module
 * scripts opened by saying they mirrored the theme's. They didn't, in either
 * direction: each had hardened the rules it happened to need. The dashboards
 * enforced off-scale spacing and radius, which the theme did not. The theme
 * measured contrast, which no module did. The search client had four rules and
 * no rem check, which is why `font-size: 1.25rem` sat in its search box. Three
 * scripts, three rule sets, and the differences were undocumented.
 *
 * So the rules live here, versioned in the theme, and each repo supplies a config
 * for its paths and allowlists. The modules vendor this file (see
 * `npm run vendor:lint` in the theme) rather than reimplementing it.
 *
 * THE FOUR RULES NONE OF THE THREE HAD:
 *   • `fallback` — the literal in `var(--token, literal)` must equal the theme's
 *     own resolved value. Every previous lint STRIPPED `var(--x, …)` before
 *     applying any rule, deliberately, "so the rules only ever see the active
 *     value" — which made the fallback the one part of the design system no
 *     check had ever looked at, and the part that had drifted furthest.
 *   • `leading` — line-height must come off the `--leading-*` scale.
 *   • `zindex` — a page layer must come off the named z-index scale.
 *   • `media` — an `@media` width literal must be on the shared breakpoint ladder.
 *
 * Each rule can be disabled, or given a file allowlist, per repo. An allowlist is
 * a RATCHET, not an exemption: it records what has not been converted yet, and
 * shrinking it is the unit of work.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

/** Hairlines, rules and outlines are legitimately sub-grid. */
const PX_GEOMETRY_OK = new Set(['0px', '1px', '2px', '3px']);

/** Small integers that order siblings inside one component are not page layers. */
const LOCAL_Z_MAX = 9;

const norm = (s) =>
  String(s)
    .toLowerCase()
    .replace(/["']/g, '"') // prettier rewrites font stacks to single quotes
    .replace(/\s+/g, ' ')
    .trim();

/** Scale values (as strings) for a token-name prefix, from the generated table. */
function scaleFor(table, prefix) {
  const all = { ...table.shared, ...table.light };
  const out = new Set();
  for (const [name, value] of Object.entries(all)) {
    if (name.startsWith(prefix)) out.add(norm(value));
  }
  return out;
}

/** Every rem/px length inside a clamp()/calc() counts as on-scale. */
function expandClamps(values) {
  const out = new Set(values);
  for (const v of values) {
    for (const m of String(v).matchAll(/(-?[\d.]+)(rem|px)/g)) out.add(m[1] + m[2]);
  }
  return out;
}

function* walk(dir, opts) {
  let entries;
  try {
    entries = readdirSync(dir);
  } catch {
    return;
  }
  for (const name of entries) {
    if (opts.skipDirs.includes(name)) continue;
    const p = join(dir, name);
    if (statSync(p).isDirectory()) yield* walk(p, opts);
    else if (opts.extensions.some((e) => name.endsWith(e)) && !opts.skipFile(name)) yield p;
  }
}

/** Strip `//` and `/* *\/` comments from a line, tracking block state across lines. */
function makeCommentStripper() {
  let inBlock = false;
  return (line) => {
    let code = line;
    if (inBlock) {
      const end = code.indexOf('*/');
      if (end === -1) return '';
      code = code.slice(end + 2);
      inBlock = false;
    }
    code = code.replace(/\/\*.*?\*\//g, '');
    const open = code.indexOf('/*');
    if (open !== -1) {
      code = code.slice(0, open);
      inBlock = true;
    }
    return code.replace(/(^|[^:])\/\/.*$/, '$1');
  };
}

/** Split `--name, fallback` at the first top-level comma. */
function splitVarArgs(inner) {
  let depth = 0;
  for (let i = 0; i < inner.length; i++) {
    const ch = inner[i];
    if (ch === '(') depth++;
    else if (ch === ')') depth--;
    else if (ch === ',' && depth === 0) return [inner.slice(0, i).trim(), inner.slice(i + 1).trim()];
  }
  return [inner.trim(), null];
}

/**
 * Every `var(--name, fallback)` in a source, with the 1-based line it starts on.
 *
 * Scanned over the WHOLE file rather than line by line: Prettier wraps a long
 * fallback across lines (`var(\n  --shadow-md,\n  0 4px …\n)`), and a line-based
 * matcher silently skips exactly the declarations most likely to be wrong.
 */
function* varCalls(src) {
  const code = blankComments(src);
  for (let i = code.indexOf('var('); i !== -1; i = code.indexOf('var(', i + 4)) {
    let depth = 0;
    let j = i + 3;
    for (; j < code.length; j++) {
      if (code[j] === '(') depth++;
      else if (code[j] === ')' && --depth === 0) break;
    }
    if (j >= code.length) continue;
    const [name, fallback] = splitVarArgs(code.slice(i + 4, j));
    if (fallback == null) continue;
    // Not a custom property: `'var(' + name + ', ' + fallback + ')'` is a JS
    // string BUILDING a var() expression (the token bridge does exactly this),
    // and its "fallback" is a variable, not a literal to check.
    if (!/^--[\w-]+$/.test(name)) continue;
    yield { name, fallback, line: code.slice(0, i).split('\n').length };
  }
}

/**
 * Every `cssColor('--name', 'literal')` bridge call — the JS equivalent of a
 * `var(--name, literal)` fallback, and subject to the same rule.
 *
 * This is where the worst of the drift hid: `--primary-muted` fell back to
 * `#b2dfdb`, a stock Material teal, and `--surface` / `--primary-contrast` both
 * to `#ffffff`, the literal the theme retired `--white` to prevent. A CSS-only
 * fallback check would never have seen any of it.
 */
function* bridgeCalls(src) {
  const code = blankComments(src);
  const re = /css(?:Color|Font|Value)\s*\(\s*['"](--[\w-]+)['"]\s*,\s*['"]([^'"]*)['"]\s*\)/g;
  for (const m of code.matchAll(re)) {
    yield { name: m[1], fallback: m[2], line: code.slice(0, m.index).split('\n').length };
  }
}

/**
 * Blank out comment bodies, preserving length and newlines so offsets and line
 * numbers still line up.
 *
 * Necessary because these files document their own contract *in the syntax the
 * rules match*: dre-visualizations.css explains `var(--token, …)` in its header
 * comment, which a naive scan reports as a reference to an undefined token.
 */
function blankComments(src) {
  const blank = (s) => s.replace(/[^\n]/g, ' ');
  return src
    .replace(/\/\*[\s\S]*?\*\//g, blank)
    .replace(/(^|[^:])\/\/[^\n]*/g, (m, lead) => lead + blank(m.slice(lead.length)));
}

/**
 * Run the rule set.
 *
 * @param {object} config
 * @param {string} config.root         repo root
 * @param {string[]} config.dirs       directories to scan, relative to root
 * @param {string[]} config.extensions file extensions to read
 * @param {object} config.table        the parsed dre-tokens-fallback.json
 * @param {object} [config.rules]      per-rule `false` to disable, or `{allow: []}`
 * @param {string[]} [config.localPrefixes] the repo's own custom-property
 *   namespace (e.g. `--rv-`), which the fallback rule must not treat as a
 *   missing theme token
 * @param {string[]} [config.skipDirs]
 * @returns {string[]} findings, each `path:line  message`
 */
export function runRules(config) {
  const {
    root,
    dirs,
    extensions,
    table,
    rules = {},
    localPrefixes = [],
    skipDirs = ['node_modules', '.git', 'dist', 'vendor', 'build'],
  } = config;

  const enabled = (name) => rules[name] !== false;
  const allowed = (name, rel) => {
    const allow = rules[name]?.allow;
    return Array.isArray(allow) && allow.some((a) => rel === a || rel.startsWith(a));
  };

  const fallbacks = { ...table.shared, ...table.light };
  const darkFallbacks = { ...table.shared, ...table.dark };
  const TEXT = expandClamps(scaleFor(table, '--text-'));
  const SPACE = scaleFor(table, '--space-');
  const RADIUS = scaleFor(table, '--radius-');
  const LEADING = scaleFor(table, '--leading-');
  const ZINDEX = new Set(Object.entries({ ...table.shared }).filter(([n]) => n.startsWith('--z-')).map(([, v]) => v));
  const LADDER = new Set(Object.values(table.breakpoints ?? {}));

  // Findings carry the rule that produced them, so an allowlist can be scoped to
  // one rule in one file rather than exempting a file from everything.
  const findings = [];
  const push = (rule, file, line, message) => findings.push({ rule, file, line, message });

  const opts = { skipDirs, extensions, skipFile: (n) => /\.(bundle|min)\.(js|css)$/.test(n) };

  for (const dir of dirs) {
    for (const file of walk(join(root, dir), opts)) {
      const rel = relative(root, file).split(sep).join('/');
      const src = readFileSync(file, 'utf8');
      const lines = src.split(/\r?\n/);
      const strip = makeCommentStripper();

      // ------------------------------------------------------------------
      // Whole-file rule: fallback literals.
      // ------------------------------------------------------------------
      if (enabled('fallback') && !allowed('fallback', rel)) {
        for (const { name, fallback, line } of [...varCalls(src), ...bridgeCalls(src)]) {
          // A module's OWN namespace (--rv-* in DRE-Visualizations) is declared
          // in its own stylesheet, aliased onto a theme token there. Checking it
          // against the theme's table would report every alias as undefined.
          if (localPrefixes.some((p) => name.startsWith(p))) continue;
          const want = fallbacks[name];
          if (want == null) {
            // A token the theme does not define. Its fallback is therefore the
            // ONLY thing that ever paints — a hard-coded value wearing a token's
            // name, which is worse than a plain literal because it reads as
            // theme-aware. (--danger and --type-entity-term were both this.)
            push(
              'fallback',
              rel,
              line,
              `var(${name}, …) — the theme defines no such token, so this always paints its fallback`
            );
            continue;
          }
          const got = norm(fallback);
          if (got === norm(want) || got === norm(darkFallbacks[name] ?? ' ')) continue;
          push('fallback', rel, line,
            `var(${name}, ${fallback.replace(/\s+/g, ' ').slice(0, 48)}) — fallback should be ${want}`);
        }
      }

      // ------------------------------------------------------------------
      // Line rules.
      // ------------------------------------------------------------------
      lines.forEach((raw, i) => {
        const line = strip(raw);
        if (!line.trim()) return;
        // Fallback position is checked by its own rule above; the numeric rules
        // must only see the ACTIVE value, or every corrected fallback would trip
        // the very scales it was corrected to match.
        const active = line.replace(/var\(\s*--[\w-]+\s*,[^()]*(?:\([^()]*\)[^()]*)*\)/g, 'var(--x)');

        if (enabled('hex') && !allowed('hex', rel)) {
          const noUri = active
            .replace(/url\([^)]*\)/g, '')
            .replace(/%23[0-9a-fA-F]{3,6}/g, '')
            // The JS bridge's fallback argument is fallback position too:
            // `cssColor('--accent', '#ca7210')` is the same contract as
            // `var(--accent, #ca7210)`, and the `fallback` rule checks its value.
            .replace(/css(?:Color|Font|Value)\s*\(\s*['"]--[\w-]+['"]\s*,[^)]*\)/g, '');
          const hex = noUri.match(/#[0-9a-fA-F]{3,8}\b/);
          if (hex) push('hex', rel, i + 1, `raw hex outside fallback position: ${hex[0]}`);
        }

        if (enabled('stripe') && !allowed('stripe', rel)) {
          if (/border-(left|right)\s*:\s*([2-9]|\d{2,})px\s+\w+\s+(var\(|#|oklch|rgb)/.test(active)) {
            push('stripe', rel, i + 1, `coloured side-stripe border: ${active.trim()}`);
          }
        }

        if (enabled('gradientText') && /background-clip\s*:\s*text/.test(active)) {
          push('gradientText', rel, i + 1, 'gradient text (background-clip: text)');
        }

        if (enabled('fontSize') && !allowed('fontSize', rel)) {
          const px = active.match(/font-size\s*:\s*(\d+(?:\.\d+)?px)/);
          if (px) push('fontSize', rel, i + 1, `px font-size (the type scale is rem-only): ${px[1]}`);
          const rem = active.match(/font-size\s*:\s*(\d+(?:\.\d+)?rem)\s*[;}]/);
          if (rem && !TEXT.has(norm(rem[1]))) {
            push('fontSize', rel, i + 1, `off-scale font-size ${rem[1]} — author from --text-*`);
          }
        }

        if (enabled('spacing') && !allowed('spacing', rel)) {
          for (const m of active.matchAll(
            /(?:^|[\s:])(?:margin|padding|gap|inset|top|right|bottom|left)(?:-[\w]+)?\s*:\s*([^;{}]+)/g
          )) {
            for (const len of m[1].matchAll(/(-?\d+(?:\.\d+)?rem)/g)) {
              if (!SPACE.has(norm(len[1]))) {
                push('spacing', rel, i + 1, `off-scale rem spacing ${len[1]} — author from --space-*`);
              }
            }
          }
        }

        if (enabled('radius') && !allowed('radius', rel)) {
          const m = active.match(/border-radius\s*:\s*([^;{}]+)/);
          if (m && !/9999px|50%|100%/.test(m[1])) {
            for (const len of m[1].matchAll(/(\d+(?:\.\d+)?(?:rem|px))/g)) {
              if (!RADIUS.has(norm(len[1]))) {
                push('radius', rel, i + 1, `off-scale border-radius ${len[1]} — author from --radius-*`);
              }
            }
          }
        }

        // NEW — line-height must be on the --leading-* scale.
        if (enabled('leading') && !allowed('leading', rel)) {
          const m = active.match(/line-height\s*:\s*(\d+(?:\.\d+)?)\s*[;}]/);
          // 0 and 1 collapse the line box — an icon/glyph reset, not prose
          // rhythm, and there is deliberately no token for them.
          if (m && !['0', '1'].includes(m[1]) && !LEADING.has(norm(m[1]))) {
            push('leading', rel, i + 1, `off-scale line-height ${m[1]} — author from --leading-*`);
          }
        }

        // NEW — a page layer must be on the named z-index scale.
        if (enabled('zindex') && !allowed('zindex', rel)) {
          const m = active.match(/z-index\s*:\s*(-?\d+)\s*[;}]/);
          if (m) {
            const value = Number(m[1]);
            if (Math.abs(value) > LOCAL_Z_MAX && !ZINDEX.has(m[1])) {
              push('zindex', rel, i + 1,
                `raw z-index ${m[1]} — a page layer belongs on the --z-* scale ` +
                  `(local sibling ordering should be ≤ ${LOCAL_Z_MAX})`);
            }
          }
        }

        // NEW — @media width literals must be on the shared ladder.
        if (enabled('media') && !allowed('media', rel)) {
          if (/@media|@container/.test(active)) {
            const isContainer = /@container/.test(active);
            for (const m of active.matchAll(/\((?:min|max)-width\s*:\s*(\d+(?:\.\d+)?)(px|rem)\)/g)) {
              // A container query is measured against its own element, so it is
              // deliberately NOT on the viewport ladder — and it is the better
              // answer for module-internal layout.
              if (isContainer) continue;
              const px = m[2] === 'rem' ? Number(m[1]) * 16 : Number(m[1]);
              // max-width queries sit one pixel below a ladder step by convention.
              if (!LADDER.has(px) && !LADDER.has(px + 1)) {
                push('media', rel, i + 1,
                  `@media ${m[1]}${m[2]} is off the shared breakpoint ladder ` +
                    `(${[...LADDER].sort((a, b) => a - b).join(' · ')} px)`);
              }
            }
          }
        }

        if (enabled('pxGeometry') && !allowed('pxGeometry', rel)) {
          if (!/@media|@include|@use|@container/.test(active)) {
            for (const px of active.match(/-?\d+(\.\d+)?px/g) ?? []) {
              if (!PX_GEOMETRY_OK.has(px)) {
                push('pxGeometry', rel, i + 1, `px page geometry (use --space-* / --container-*): ${px}`);
              }
            }
          }
        }
      });
    }
  }

  return findings;
}

/**
 * Parse a `rule  path` allowlist file into a `rules` config.
 *
 * The file IS the backlog. Each line exempts one rule in one file, so a partial
 * conversion narrows the exemption instead of losing the whole file's coverage.
 * Blank lines and `#` comments are ignored.
 */
export function parseAllowlist(text, base = {}) {
  const rules = structuredClone(base);
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.replace(/#.*$/, '').trim();
    if (!line) continue;
    const [rule, ...rest] = line.split(/\s+/);
    const path = rest.join(' ');
    if (!rule || !path) continue;
    if (rules[rule] === false) continue;
    if (typeof rules[rule] !== 'object' || rules[rule] === null) rules[rule] = { allow: [] };
    rules[rule].allow ??= [];
    rules[rule].allow.push(path);
  }
  return rules;
}

/** Regenerate an allowlist from the findings a clean run produced. */
export function formatAllowlist(findings, header) {
  const byRule = new Map();
  for (const f of findings) {
    if (!byRule.has(f.rule)) byRule.set(f.rule, new Set());
    byRule.get(f.rule).add(f.file);
  }
  const out = [header.trimEnd(), ''];
  for (const rule of [...byRule.keys()].sort()) {
    const files = [...byRule.get(rule)].sort();
    out.push(`# ${rule} — ${files.length} file(s)`);
    for (const file of files) out.push(`${rule}  ${file}`);
    out.push('');
  }
  return out.join('\n');
}

/** Print findings and exit non-zero if there are any. */
export function report(label, findings) {
  const format = (f) => (typeof f === 'string' ? f : `${f.file}:${f.line}  [${f.rule}] ${f.message}`);
  if (findings.length) {
    console.error(`${label}: ${findings.length} finding(s)\n`);
    for (const f of findings) console.error('  ' + format(f));
    process.exit(1);
  }
  console.log(`${label}: clean.`);
}
