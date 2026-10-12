# DRE Linked Data

[![CI](https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/actions/workflows/ci.yml/badge.svg)](https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/AM-Digital-Research-Environment/DRE-LinkedData?sort=semver&label=release)](https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/releases/latest)
[![Omeka S ^4.2.0](https://img.shields.io/badge/Omeka%20S-%5E4.2.0-3b5f7d)](https://omeka.org/s/)
[![PHP >= 8.2](https://img.shields.io/badge/PHP-%E2%89%A5%208.2-777bb4)](https://www.php.net/)
[![Licence: GPL-3.0-or-later](https://img.shields.io/badge/licence-GPL--3.0--or--later-009260)](LICENSE)

Linked Open Data for the **Africa Multiple DRE** Omeka S instance
([data.africamultiple.uni-bayreuth.de](https://data.africamultiple.uni-bayreuth.de)).

Omeka already returns RDF from its API (`/api/items/7392?format=turtle`), but that is the
platform's internal model rather than publishable Linked Data:

- subjects are API URLs;
- record pages do not answer RDF clients;
- `o:owner` and `o:resource_template` sit beside the metadata;
- the Wikidata, GND and Library of Congress identifiers the sync stores are plain
  `dcterms:identifier` values, which no generic client follows.

This module publishes the same records properly:

- **A permanent URI for every public resource** — `/resource/{id}` — that sends browsers to
  the record page and RDF clients to Turtle, N-Triples or JSON-LD.
- **A clean RDF projection** of each record, with identity links to the authorities.
- **A full dump** as gzipped N-Triples, ready to load into Apache Jena Fuseki.
- **A VoID/DCAT description** of the dataset for Linked Data tools and data catalogues.
- **A "Linked open data" page block** that explains all of it to visitors, in the DRE theme.

It sits in the DRE module family alongside `DRESearch`, `DRESeo` and `DreVisualizations`. It
has no third-party Composer dependencies, creates no database tables and needs no theme
edits.

---

## Resource URIs

```
https://data.africamultiple.uni-bayreuth.de/resource/7392          the item itself
https://data.africamultiple.uni-bayreuth.de/resource/7392.ttl      Turtle
https://data.africamultiple.uni-bayreuth.de/resource/7392.nt       N-Triples
https://data.africamultiple.uni-bayreuth.de/resource/7392.jsonld   JSON-LD
```

`/resource/{id}` follows the W3C [Cool URIs](https://www.w3.org/TR/cooluris/) 303 pattern.
It content-negotiates on `Accept`:

- a browser is redirected to `/s/amira/item/7392`;
- an RDF client is redirected to the document in the format it named.

The same works from the record page itself:

```bash
curl -L -H "Accept: text/turtle" https://data.africamultiple.uni-bayreuth.de/resource/7392
curl -L -H "Accept: application/ld+json" https://data.africamultiple.uni-bayreuth.de/s/amira/item/7392
```

The id is Omeka's own. The sync never deletes or renumbers items, so ids are stable. The site
slug is deliberately kept out of the identifier: renaming the site must not rename every
resource. A resource that is missing or not public is a 404 on every route.

## What the RDF contains

Each document describes one resource, plus a few statements about the document itself
(`foaf:primaryTopic`, `dcterms:modified`, `void:inDataset` and the licence, if one is set).

| | Omeka's `?format=turtle` | This module |
|---|---|---|
| Subject and link IRIs | `/api/items/{id}` | `/resource/{id}` |
| Omeka bookkeeping (`o:owner`, `o:resource_template`, `o:is_public`, …) | included | dropped |
| Title | `o:title` | `rdfs:label` (plus the original property) |
| Wikidata / GND / LCSH identifiers | `dcterms:identifier` only | also `owl:sameAs` / `skos:exactMatch` |
| Item-set membership | `o:item_set` | `schema:isPartOf` |
| Media | `o:media` | `schema:associatedMedia`; the media states `schema:contentUrl`, `schema:encodingFormat` and the work it encodes |

**Values.** Language tags are kept when they are well-formed. Numeric Data Types values
are typed at their stored precision: `xsd:gYear`, `gYearMonth`, `date`, `dateTime`,
`integer` or `duration`.

**Visibility.** A resource is published when it is public, and a media only when its item
is public too. Values must be public themselves. A link to a resource that is not visible is
dropped entirely rather than turned into a literal, so not even a private item's id leaks.

**Belt and braces.** `dre:mongoId`, `dre:security` and `dre:bitstream` are never published,
even when a value is marked public by mistake. An uploaded file's original name and a
sideloaded file's server path are never published either.

**Identity links.** URI values of `dcterms:identifier` are checked against an allowlist of
authorities (`config/module.config.php`). The canonical entity IRI is added beside the
original value:

| Authority | Stored as, for example | Linked as |
|---|---|---|
| Wikidata | `http://www.wikidata.org/entity/Q408`, `https://www.wikidata.org/wiki/Q707283` | `http://www.wikidata.org/entity/Q…` |
| Library of Congress | `http://id.loc.gov/authorities/subjects/sh85149367` | the same, without `.html` |
| GND | `https://d-nb.info/gnd/124120881` | the same |
| VIAF, ORCID, ROR, GeoNames | their usual URL forms | their entity IRIs |

The predicate is `owl:sameAs` for entities: persons, places, organisations and journals.
Items on the Authority Resource template (template 6: subjects, languages, genres) are
concepts, so they get `skos:exactMatch`. Other identifiers stored on authority items, such
as YouTube playlist and GEPRIS URLs, stay plain identifiers: they are related, not the same
thing.

Value annotations (such as a contributor's affiliation for one item) are not projected yet.
Research items have no resource class in Omeka, so they get no `rdf:type`.

## The dump

**Modules → DRE Linked Data → Configure → "Generate the dump now"** starts a background job
that writes every public resource to `files/dre-linked-data/dump.nt.gz`. It is served at
`/linked-data/dump.nt.gz`.

- **Batching.** Resources are read in id-ordered batches of 250, so memory stays flat.
- **Atomic swap.** The file is written beside the old one and swapped in only when complete,
  so downloads never see half a dump.
- **No overlap.** A lock stops two runs overlapping.
- **Stopping.** "Attempt stop" in Admin → Jobs abandons a run and leaves the previous dump
  in place.

Resource documents are always live. The dump and the figures in the VoID description are
not: like DRE Search's index and DRE Visualizations' precompute, **regenerate after a sync.**

To load the dump into Apache Jena Fuseki (Jena reads `.gz` directly):

```bash
tdb2.tdbloader --loc /fuseki/databases/amira amira-linked-data.nt.gz
```

## Dataset description (VoID / DCAT)

`/linked-data/void` serves Turtle by default, or the format asked for. `.ttl`, `.nt` and
`.jsonld` force one, and `/.well-known/void` redirects there. The description covers:

- **Dataset node.** One node typed both `void:Dataset` and `dcat:Dataset`, with title,
  description, publisher, licence and landing page.
- **Resolution.** The URI space (`void:uriSpace`), the supported formats, and an example
  resource (the most richly described item).
- **Figures from the last dump.** `void:triples`, `void:entities` and the vocabularies used.
- **Distribution.** A `dcat:Distribution` for the dump, with media type, compression and
  byte size.
- **Linksets.** One `void:Linkset` per authority and predicate, with its link count.

## The page block

Add **Linked open data** to a site page (a "Data access" page, say). It shows:

- the dataset's figures;
- the download, with its checksum;
- the VoID links;
- how resource URIs work, with a worked example and a `curl` line;
- the linksets and the vocabulary list.

Before the first dump it says so, and still explains the per-resource route, which already
works. Editors can set a heading and pick the example resource.

The block is built entirely on the DRE theme's design tokens. It uses colour, type,
spacing, radius and motion tokens and reuses the theme's own `.button`, `pre` and table
styles. Every `var()` fallback is the theme's own value, so it reads correctly in light and
dark mode. It lays out by container query from 320px up. `composer lint:tokens` holds it to
the shared rule set vendored from DRE-theme.

**For the theme.** `$this->dreLinkedData($resource)` returns the resource URI and its three
RDF documents, in the same `downloads` shape as DRE SEO's citation exports. That lets the
record apparatus list "Turtle · N-Triples · JSON-LD" beside BibTeX and RIS, guarded on the
helper's existence like `dreCitation`.

## Installation

Download `DRELinkedData.zip` from the
[latest release](https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/releases/latest)
and unpack it into Omeka's `modules/` directory. The folder must be named `DRELinkedData`
to match the namespace. Then **Admin → Modules → DRE Linked Data → Install**, and configure.

For the Docker deployment, add the release URL to `deploy/amira/modules.txt` in
`omeka-s-docker`. The dump lives under `files/`, which is already a persistent, writable
volume, so no new volume is needed. One web-server change is required for `/.well-known/void`
only. The stock `nginx.conf` denies every dot-path (`location ~ /\.`), so it needs an
exception for `/.well-known/` placed before that rule. Every other route reaches Omeka
through the existing `try_files … /index.php`.

**Requirements:** Omeka S `^4.2.0`, PHP `>= 8.2` with `zlib`. CI covers PHP 8.2 and the
production runtime, 8.5.

## Configuration

| Setting | Purpose |
|---|---|
| Base URI | The `{base}` of every identifier. **Set this once to the public address and never change it.** Empty means "the address Omeka is reached at", which is fine for testing but not for publication. |
| Dataset title / description | For the VoID description and the page block. The title defaults to the site title. |
| Publisher name / URI | `dcterms:publisher`. With only a name, an agent IRI is minted under `/linked-data/void#publisher`. |
| Metadata licence | A licence URI (e.g. `https://creativecommons.org/publicdomain/zero/1.0/`), stated on every document and the dump. **Nothing is claimed while this is empty.** |
| Generate the dump now | Starts the dump job on save. |

Structural choices — excluded terms, authority patterns, which template is "concepts", the
classes added to item sets and media, the batch size — are in the `dre_linked_data` block of
`config/module.config.php`, overridable key by key from Omeka's `config/local.config.php`.

## Project layout

```
Module.php                      ACL, record-page conneg listener, alternate links, config form
config/module.config.php        routes, wiring, and the dre_linked_data instance config
src/Rdf/                        IRI/literal/triple model, prefix map, content negotiation,
                                N-Triples / Turtle / JSON-LD serialisers
src/Projection/                 OmekaSource (visibility in SQL), ResourceProjector,
                                IdentityLinks, UriScheme
src/Dump/                       DumpGenerator (streaming job core), DumpStore (files, lock, state)
src/Dataset/                    DatasetDescription (VoID/DCAT), DatasetSummary (view model)
src/Controller/                 /resource/*, /linked-data/*, /.well-known/void
src/Job/GenerateDump.php        the background job
src/Site/BlockLayout/           the "Linked open data" page block
src/View/Helper/LinkedData.php  dreLinkedData($resource), for the theme
view/                           block template and admin status panel
asset/css/dre-linked-data.css   the block's styles, on the theme's tokens
scripts/                        design-token lint (rules + table vendored from DRE-theme)
tests/                          unit harness, fixtures, Omeka 4.2.1 integration smoke test
```

## Development and testing

```bash
composer check                                     # PHP lint, design-token lint, unit tests
OMEKA_PATH=/path/to/omeka-s composer integration   # against a real Omeka S 4.2.1
```

The integration test builds a small Omeka database in SQLite to prove the visibility SQL
(private items, private values, media of private items, links to any of them). It then
dispatches the controllers through the real routes and parses Turtle, N-Triples and JSON-LD
with EasyRdf and ml/json-ld from Omeka's vendor tree. The three must yield the identical
graph.

## Roadmap

- **SPARQL.** Load the dump into Apache Jena Fuseki and expose a read-only endpoint, then
  advertise it with `void:sparqlEndpoint`.
- **Value annotations**, as RDF 1.2 triple terms once Fuseki and the serialisers agree.
- **Classes for research items**, perhaps from their Type of Resource authority.
- **A dereferenceable `dre:` namespace.** `http://am-digital.org/ontology/dre/` does not
  resolve today.
- **Wider authority coverage** in the source data (Type of Resource → DCMI Type, languages →
  ISO 639-3), which the identity links will pick up without code changes.

## Uninstalling

Uninstall removes the six `dre_ld_*` settings and the `files/dre-linked-data/` directory.
Remove any "Linked open data" page blocks first: Omeka keeps blocks whose layout no longer
exists.

## Citing this module

See [`CITATION.cff`](CITATION.cff); GitHub renders it under "Cite this repository".

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) and, for anything that could expose private data,
[`SECURITY.md`](SECURITY.md).

## Licence

[GPL-3.0-or-later](LICENSE).
