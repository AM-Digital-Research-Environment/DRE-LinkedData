# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/AM-Digital-Research-Environment/DRE-LinkedData/releases/tag/v0.1.0
