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

`composer check` syntax-checks every PHP and PHTML file, runs the design-token lint (Node),
and runs the unit harness. It needs no `composer install` — the module has **no runtime
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

CI runs the unit suite on PHP 8.2 and 8.5, plus the smoke test against a checksum-pinned
Omeka S 4.2.1. Please run `composer check` before pushing.

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

1. Bump `version` in **`config/module.ini`** and **`CITATION.cff`** (and add `date-released`).
2. Update `CHANGELOG.md`.
3. Commit, then tag: `git tag -a vX.Y.Z -m "..." && git push --follow-tags`.

The `Release` workflow refuses to publish if those two declared versions disagree with the
tag. When they agree it runs the full test suite, builds `DRELinkedData.zip` with
`git archive --prefix=DRELinkedData/`, checks the archive for leaked development files, and
attaches the zip plus a SHA-256 checksum to the release. Do not hand-build the zip.

## Licence

Contributions are accepted under [GPL-3.0-or-later](LICENSE), the licence of this project.
