# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.1] — 2026-10-15

### Changed

- The dataset's `dcat:landingPage` is now the public page that carries the *Linked open data* block (on AMIRA, `/s/amira/page/linked-open-data`), not the site's home. That page explains the figures, the dump and the URIs, so catalogues and dataset search engines should send people there. `foaf:homepage` stays the site. Without such a page, the home is still the landing page.
- `dreLinkedData()->dataset()` returns that page as `landingPage` (null without one), so DRE SEO can give the schema.org `Dataset` the same page.

## [0.3.0] — 2026-10-15

### Fixed

- A record whose only title is private no longer shows that title as its `rdfs:label` or
  `skos:prefLabel`. Labels now come from public values only (the template's title property,
  else `dcterms:title`); a record with no public title has no label. Omeka copies the first
  title value into its own title column whether or not it is public, and that column was
  used before. The dump is fixed the same way.
- Item-set pages (`/s/amira/item-set/{id}`) now answer an RDF `Accept` header with a `303` to
  the RDF document, and advertise their documents with `<link rel="alternate">`, like item
  and media pages. Omeka serves them as a filtered item browse, which the module missed.
- RDF documents, redirects and errors no longer carry a session cookie, nor the
  `Expires: Thu, 19 Nov 1981` and `Pragma: no-cache` headers that contradicted their
  `Cache-Control`. The session is closed unwritten for these responses.
- `dcterms:identifier` holds strings, as its range requires: a stored URI is published as a
  literal, with its label beside it (`"GND 124120881"`). The link stays in `owl:sameAs` /
  `skos:exactMatch`, and a URI that is not an authority identity (a YouTube playlist) is
  kept as `rdfs:seeAlso`.
- Impossible calendar dates (`2021-02-31`) are no longer typed `xsd:date`.
- A DOI containing an encoded `#` or `?` no longer turns into a fragment or query of its
  `https://doi.org/` IRI.
- JSON-LD no longer declares a prefix that would make a JSON-LD processor misread a full
  IRI with the same scheme (a `geo:` IRI beside the `geo` vocabulary, say).
- Invalid UTF-8 in a stored URI is percent-encoded instead of breaking the document.
- The dump's `ETag` can no longer describe a different file while a new dump is swapped in.
- The page block ignores an example resource that is not public.

### Added

- RDF/XML (`application/rdf+xml`, `/resource/{id}.rdf`, `/linked-data/void.rdf`), so
  RDF/XML-only clients are served instead of refused. Turtle still wins any tie.
- Every resource is `foaf:isPrimaryTopicOf` its record page, and the page is
  `dcterms:modified` when the record last changed, in the documents and in the dump.
- Item sets that hold concepts are typed `skos:ConceptScheme`.
- HTTP: `Link` headers on documents (the other formats as `alternate`, the resource as
  `describes`), `Access-Control-Expose-Headers`, CORS on `404`s and on the record pages'
  `303`, `304` for `If-Modified-Since`, and `Content-Location` on the negotiated
  `/linked-data/void`. Every redirect target is absolute. `/resource/{id}/` redirects to
  `/resource/{id}`.
- `/linked-data/void` sends browsers to the site page carrying the Linked open data block.
- DCAT: a contact point (new "Contact email" setting), `dcterms:issued`, keywords, English
  language tags, and the dump's SHA-256 as an `spdx:Checksum`.
- The view helper gains `page` and `sameAs` for each resource, an RDF/XML download, and
  `dreLinkedData()->dataset()` for the dataset as a whole. The contract shared with
  DRE-theme and DRE SEO is documented in the README.
- The admin status panel and the page block say when the dump is out of date ("N changes
  since …"): item, item-set and media saves are counted, once per request.
- `scripts/generate-dump.php` regenerates the dump from the command line, for cron or the
  end of a sync (`--if-stale` skips the run when nothing changed).
- The admin status panel warns when no base URI is set, or when it is not `https`.

### Changed

- `application/n-triples` and `application/ld+json` responses no longer carry a `charset`
  parameter; `text/turtle` keeps it.
- Numeric Data Types intervals are documented as plain literals.

## [0.2.0] — 2026-10-14

### Changed

- Full texts and transcripts (`bibo:content`) are no longer published. The dataset licence
  covers the metadata, not the works, and they made single documents close to a megabyte.
- Authority Resources are published as `skos:Concept` instead of `fabio:AuthorityFile`
  (a FaBiO document class), with `skos:prefLabel` and `skos:inScheme` for each item set.
  Class replacements are configurable (`class_replacements`).
- `bibo:authorList` and `bibo:editorList` are real `rdf:List`s in Omeka's value order, on
  hash IRIs of the resource, instead of pointing straight at people. Each linked member is
  also stated as `dcterms:creator` or `dcterms:contributor`.
- `bibo:doi` holds the bare DOI, as BIBO defines it, even when Omeka stores a resolver URL;
  the `https://doi.org/` IRI is linked with `schema:sameAs` and counted as a VoID linkset.
- VoID linksets point at a `void:Dataset` node per authority (title, homepage,
  `void:uriSpace`) instead of the authority's website.
- A resource URI answers `406 Not Acceptable` when the client accepts neither HTML nor a
  served RDF format (only `application/rdf+xml`, say), instead of redirecting to HTML.

### Added

- A fallback class, `schema:CreativeWork`, for items with no resource class: about 3,800
  research items were untyped.
- `geo:lat` and `geo:long` are typed `xsd:decimal` when numeric.


## [0.1.0] — 2026-10-12

### Added

- Dereferenceable resource URIs at `/resource/{id}` for every public item, item set and
  media, following the W3C 303 pattern: browsers go to the record page, RDF clients to
  `/resource/{id}.ttl`, `.nt` or `.jsonld`. The record pages themselves answer an RDF
  `Accept` header with the same redirect and advertise their documents with
  `<link rel="alternate">`.
- A clean RDF projection of each record. Omeka bookkeeping is dropped, private values and
  links to private resources never appear, and `dre:mongoId`, `dre:security` and
  `dre:bitstream` are excluded even when marked public. Numeric Data Types values become
  `xsd:gYear`, `gYearMonth`, `date`, `dateTime`, `integer` or `duration`. Item-set membership
  is `schema:isPartOf`, and media state their file URL and type. Uploaded filenames are
  never published.
- Identity links from authority identifiers: Wikidata, GND, VIAF, ORCID, ROR and GeoNames
  become `owl:sameAs`. Library of Congress headings, and anything on the Authority Resource
  template, become `skos:exactMatch`. Wikidata page URLs are normalised to entity IRIs.
- A background job, started from the module configuration, that writes the whole public
  graph to `files/dre-linked-data/dump.nt.gz` (served at `/linked-data/dump.nt.gz`). It
  streams in batches, swaps the file in atomically and takes a lock so runs never overlap.
- A VoID and DCAT description at `/linked-data/void` (and `/.well-known/void`) with the
  URI space, dump, distribution, vocabularies, example resource and one linkset per
  authority.
- A "Linked open data" page block in the DRE theme's design language, and a
  `dreLinkedData($resource)` view helper for the theme's record apparatus.
- Unit tests, an Omeka S 4.2.1 integration smoke test that parses every serialisation with
  EasyRdf and ml/json-ld, the DRE design-token lint, and CI and release workflows.

[Unreleased]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/releases/tag/v0.1.0
