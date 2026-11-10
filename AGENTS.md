# AGENTS.md

Instructions for coding agents (Codex reads this file; Claude Code reads it
through `CLAUDE.md`). Human contributors: [CONTRIBUTING.md](CONTRIBUTING.md)
says the same things at more length, and [README.md](README.md) documents what
the module publishes.

## What this is

DRE Linked Data, the Omeka S 4.2.1 module that publishes AMIRA, the Africa
Multiple research-data platform, as Linked Open Data: `/resource/{id}` URIs with
content negotiation, a clean RDF projection with identity links to the
authorities, a gzipped N-Triples dump, a VoID/DCAT description and a "Linked
open data" page block. PHP (`Module.php`, `src/`, `config/`, `view/`), one
stylesheet and one vanilla script in `asset/`, no build step. PHP >= 8.2;
production runs 8.5. Not yet deployed.

It sits beside DRE-theme, DRE Search, DRE Visualizations and DRE SEO. The page
block consumes the theme's design tokens by name: read DRE-theme's `DESIGN.md`
and `docs/DESIGN-INTEGRATION.md` before a visual change.

## Commands

```bash
composer check        # PHP lint, JS syntax check, token lint, i18n check, PHP and JS unit tests
OMEKA_PATH=/abs/path/omeka-s composer integration    # smoke test against a real Omeka S 4.2.1
OMEKA_PATH=/abs/path/omeka-s php phpstan.phar analyse --no-progress --memory-limit=1G
php phpcs.phar                                       # PSR-12 (phpcs.xml.dist); phpcbf.phar fixes layout
composer i18n                                        # after adding, changing or removing a translatable string
composer bump-version -- minor                       # the only way to bump; see "Releasing"
```

`composer check` does **not** run the smoke test, PHPStan or phpcs; CI does, in
its `omeka-smoke` and `coding-standard` jobs, and the release is gated on them. Run both before committing a
change to `Module.php`, `src/`, `config/` or `scripts/*.php`. They need an
unpacked Omeka S 4.2.1 outside the repository (CI pins its sha256 in
`.github/workflows/ci.yml`) and the PHPStan phar CI names there; phpcs is the
PHP_CodeSniffer 4.0.4 phar, also named there. Nothing is
installed with Composer: `composer.json` has scripts and no dependencies.

## Gotchas

- **No Composer dependencies, ever.** Omeka's core `vendor/` provides Laminas,
  PSR, Doctrine, EasyRdf and ml/json-ld at runtime. Never add `laminas/*` or
  `psr/*` (or anything else) to `composer.json`: duplicate framework packages
  are a site-wide autoload fatal, not a module error.
- **Visibility lives in SQL** (`Projection\OmekaSource`). Any new query that
  reaches the public applies the same rule (public resource, public value,
  media only of a public item, links to anything private dropped), and the
  smoke test proves it against a private row. The unit tests use a fake source
  and cannot.
- **Identity links are an allowlist** in `config/module.config.php`
  (`dre_linked_data.projection.identity_links.authorities`). A new authority
  needs a pattern, canonical form, label, dataset and uri_space, plus a case in
  `tests/ConfigurationTest.php` on a URI shape the instance actually stores.
  DRE SEO reads the result through the `dreLinkedData` view helper's `sameAs`
  key, so a change here changes SEO's JSON-LD too.
- **The view helper is a cross-repository contract.** `dreLinkedData($resource)`
  (`src/View/Helper/LinkedData.php`) is read by DRE-theme's
  `helper/RecordLinkedData.php` and DRE SEO's `LinkedDataIdentity`; both guard
  on the helper existing. Add keys; do not rename or reshape existing ones.
- **The vendored token files are DRE-theme's.** `scripts/lib/token-rules.mjs`
  and `scripts/lib/dre-tokens-fallback.json` are checked against
  `scripts/lib/VENDORED.sha256` by `composer lint:tokens`. Never edit them
  here: change DRE-theme and run `npm run vendor:lint ../DRE-LinkedData` there.
  Every `var(--token, fallback)` fallback must be the theme's light value; the
  lint reports a wrong one.
- **A modifier class needs a rule.** `tests/StylesheetTest.php` fails on any
  `dre-ld__*--*` class a template emits without a rule in
  `asset/css/dre-linked-data.css`.
- **The script is tested without a browser.** `tests/js/*.test.mjs` run
  `asset/js/dre-linked-data.js` in a `vm` context over the fake DOM in
  `tests/js/fake-dom.mjs`, which implements only what the script uses. A
  script change that touches more of the DOM extends the fake; there is no
  npm, jsdom or Playwright here.
- **The results status node is visually hidden.** Downloads report through it
  and through the visible `.dre-ld__download-note` beside the buttons; they
  must never touch the results region.
- **`.gitattributes` is the package manifest.** The release zip is
  `git archive --prefix=DRELinkedData/`; `scripts/` ships only
  `generate-dump.php` (the cron script). A new root file or script is either
  `export-ignore`d or meant to ship, and `release.yml`'s leak check and
  required-file list must agree with that.
- **Omeka starts the PHP session before routing.** `Module::releaseSession()`
  closes it unwritten and strips its headers on the module's responses; keep
  new routes inside that rule (see the README's "HTTP behaviour").

## Releasing

Fill `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md) as you work. Then, on a
clean, green `main`:

```bash
composer bump-version -- minor     # module.ini, CITATION.cff, CHANGELOG heading and links
composer check
git commit -am "Release x.y.z"
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin main vX.Y.Z
```

The tag runs `.github/workflows/release.yml`: the whole of `ci.yml` on the
tagged tree, the version check (`bump-version.php --check`), the archive and its
checks, then the GitHub release with that version's CHANGELOG section as its
notes. Never build the archive by hand. Changelog headings are
`## [x.y.z] - YYYY-MM-DD`, with a hyphen.

## Conventions

- British English in user-visible copy and documentation.
- Escape everything in `.phtml` (`$this->escapeHtml()` and friends); wrap
  visible strings in `$translate()`, and mark PHP literals translated later
  with `// @translate` on the same line. `composer i18n` regenerates
  `language/template.pot`; `composer check` fails while it is stale.
- Commit messages: an imperative or descriptive subject, a body explaining why.
- Comments explain why, at the density of the surrounding code.
- Actions in workflows are pinned to full commit SHAs with the release in a
  comment.
