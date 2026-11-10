# Contributing

This module publishes a production dataset — the Africa Multiple DRE Omeka S instance — as
Linked Open Data, so changes are held to that bar. Bug reports and patches are welcome all
the same.

## Reporting a bug

Open an issue with the Omeka S version, the PHP version, the module version (Admin →
Modules, or `config/module.ini`), and what you expected against what you got. For anything
touching the RDF, the most useful thing you can attach is the resource URI and the document
you received (`curl -L -H "Accept: text/turtle" https://…/resource/{id}`).

## Making a change

```bash
git clone https://github.com/AM-Digital-Research-Environment/DRE-LinkedData.git
cd DRE-LinkedData
composer check
```

`composer check` syntax-checks every PHP and PHTML file and the block's script, runs the
design-token lint, the PHP unit harness and the script's unit tests (`node --test`, over the
small fake DOM in `tests/js/fake-dom.mjs`). Node 24 is the only other tool it needs; nothing
is installed with npm. It needs no `composer install` — the module has **no runtime
dependencies**, by design. Omeka's core `vendor/` supplies Laminas, PSR and Doctrine at
runtime, and bundling duplicate copies of framework packages causes class-collision fatals
that take down the whole site, not just the module. Never add `laminas/*` or `psr/*` to
`composer.json`.

The integration smoke test runs the real factories, routes and controllers against an
unpacked Omeka S, checks the visibility SQL against SQLite, and parses every serialisation
with the RDF libraries in Omeka's own vendor tree:

```bash
OMEKA_PATH=/path/to/omeka-s composer integration
```

Static analysis runs PHPStan (level 6, no baseline) against the same unpacked Omeka S.
Use the PHPStan phar or any global install; the module still installs nothing:

```bash
OMEKA_PATH=/path/to/omeka-s composer analyse
```

CI runs the unit suite on PHP 8.2 and 8.5, plus the smoke test and PHPStan against a
checksum-pinned Omeka S 4.2.1. Please run `composer check` before pushing, and
`composer analyse` when you touch `src/`, `Module.php` or `scripts/generate-dump.php`.

## Conventions

- Add a test to `tests/` for behaviour changes. The harness is deliberately dependency-free;
  follow the existing pattern rather than reaching for PHPUnit.
- **Visibility lives in SQL** (`Projection\OmekaSource`). Any new query that reaches the
  public must apply the same rule, and the smoke test must prove it against a private row.
- A new authority belongs in `config/module.config.php` with a pattern, a canonical form, a
  label and a target dataset, and a case in `tests/ConfigurationTest.php` using a URI shape
  the instance actually stores.
- The page block uses the DRE theme's tokens only. `var(--token, fallback)` fallbacks must be
  the theme's own values: `composer lint:tokens` checks them against the table vendored from
  DRE-theme. Refresh that table from DRE-theme with
  `npm run vendor:lint ../DRE-LinkedData`; never edit `scripts/lib/` by hand.
- Escape everything in `.phtml` templates (`$this->escapeHtml()` and friends).
- An identity link is a strong claim. `owl:sameAs` to the wrong entity is worse than no link,
  because every consumer merges the two.

## Releasing

Maintainers only:

1. Write the release notes under `## [Unreleased]` in `CHANGELOG.md` as you work.
2. On a clean, green `main`, bump every version declaration at once:

   ```bash
   composer bump-version -- minor          # or patch, major, or an explicit x.y.z
   composer bump-version -- minor --date 2026-11-12   # a release date other than today
   ```

   `scripts/bump-version.php` sets `version` in `config/module.ini` and `version` and
   `date-released` in `CITATION.cff`, turns the Unreleased notes into
   `## [x.y.z] - YYYY-MM-DD` under a fresh, empty `## [Unreleased]`, and moves the compare
   links at the foot of the changelog. It checks everything before it writes anything,
   refuses an empty Unreleased section, and never commits or tags. Do not edit these
   versions by hand.
3. `composer check`, commit (`Release x.y.z`), then tag and push:
   `git tag -a vX.Y.Z -m "Release vX.Y.Z" && git push origin main vX.Y.Z`.

The tag runs `.github/workflows/release.yml`. It first runs the whole CI suite
(`ci.yml`, called as a reusable workflow) on the tagged tree: both PHP versions, the
script's tests, the Omeka S smoke test and PHPStan. It refuses a tag that is not on `main`
or that `composer bump-version -- --check x.y.z` disagrees with (module.ini, CITATION.cff
and a dated changelog section). It then builds `DRELinkedData.zip` with
`git archive --prefix=DRELinkedData/`, checks the archive for leaked development files, and
attaches the zip and its SHA-256 checksum to the release, with that version's changelog
section as the release notes. Do not hand-build the zip. Run manually from a branch, the
workflow proves the build without publishing anything.

## Licence

Contributions are accepted under [GPL-3.0-or-later](LICENSE), the licence of this project.
