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
  the record page and RDF clients to Turtle, N-Triples, JSON-LD or RDF/XML.
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
https://data.africamultiple.uni-bayreuth.de/resource/7392.rdf      RDF/XML
```

`/resource/{id}` follows the W3C [Cool URIs](https://www.w3.org/TR/cooluris/) 303 pattern.
It content-negotiates on `Accept`:

- a browser is redirected to the record page, `https://…/s/amira/item/7392` (item sets go to
  `/s/amira/item-set/{id}`, media to `/s/amira/media/{id}`);
- an RDF client is redirected to the document in the format it named. When a client accepts
  several formats equally, Turtle wins and RDF/XML comes last;
- a client that accepts none of these (only `application/xml`, say) gets
  `406 Not Acceptable` with the list of available types, not a page it cannot read.

`/resource/{id}/` (with a trailing slash) is a `301` to `/resource/{id}`.

The same works from the record pages themselves: item and media pages, and item-set pages
(which Omeka routes as a filtered item browse, `site/item-set`). An RDF `Accept` header gets
a `303` to the resource's document, and every one of these pages advertises the four
documents with `<link rel="alternate">`:

```bash
curl -L -H "Accept: text/turtle" https://data.africamultiple.uni-bayreuth.de/resource/7392
curl -L -H "Accept: application/ld+json" https://data.africamultiple.uni-bayreuth.de/s/amira/item/7392
curl -L -H "Accept: text/turtle" https://data.africamultiple.uni-bayreuth.de/s/amira/item-set/18
```

### HTTP behaviour

- **Absolute redirects.** Every `Location` is absolute and built on the configured base URI.
- **CORS.** Every response of the module (documents, `303`, `301`, `404`, `406`, the dump),
  and the record pages' RDF `303`, carry `Access-Control-Allow-Origin: *` and
  `Access-Control-Expose-Headers: ETag, Link, Content-Location`.
- **Link headers.** A document links the same description in the other three formats
  (`rel="alternate"` with `type`) and the resource it describes (`rel="describes"`, RFC 6892).
  `/linked-data/void` does the same for the dataset.
- **Content types.** `text/turtle; charset=utf-8`, `application/n-triples`,
  `application/ld+json` and `application/rdf+xml`. N-Triples and JSON-LD are UTF-8 by
  definition and take no `charset` parameter.
- **Caching.** `ETag`, `Last-Modified` and `Cache-Control: public, max-age=300`, with `304`
  for a matching `If-None-Match` or, when that header is absent, for `If-Modified-Since`.
- **No session.** Omeka starts the PHP session during bootstrap, before any route is known,
  so it cannot be skipped. For the module's responses (and the record pages' RDF `303`) it is
  closed without being written, and the `Set-Cookie`, `Expires: Thu, 19 Nov 1981…`,
  `Pragma: no-cache` and session `Cache-Control` headers are removed before the response is
  sent. RDF clients get no cookie, and caches see only the module's own `Cache-Control`.

The id is Omeka's own. The sync never deletes or renumbers items, so ids are stable. The site
slug is deliberately kept out of the identifier: renaming the site must not rename every
resource. A resource that is missing or not public is a 404 on every route.

## What the RDF contains

Each document describes one resource, plus a few statements about the document itself
(`foaf:primaryTopic`, `dcterms:modified`, `void:inDataset` and the licence, if one is set).
The resource is `foaf:isPrimaryTopicOf` its record page on the default site
(`https://…/s/amira/item/7392`), and that page is `dcterms:modified` when the record last
changed. Both statements are also in the dump, so each resource there carries its own
modification date.

| | Omeka's `?format=turtle` | This module |
|---|---|---|
| Subject and link IRIs | `/api/items/{id}` | `/resource/{id}` |
| Omeka bookkeeping (`o:owner`, `o:resource_template`, `o:is_public`, …) | included | dropped |
| Title | `o:title` | `rdfs:label` (plus the original property), from public values only |
| Wikidata / GND / LCSH identifiers | `dcterms:identifier` holding the URI | `dcterms:identifier` as a string (its range is `rdfs:Literal`), plus `owl:sameAs` / `skos:exactMatch` |
| Other URIs in `dcterms:identifier` (YouTube playlists, GEPRIS) | `dcterms:identifier` holding the URI | `dcterms:identifier` as a string, plus `rdfs:seeAlso` the URI |
| Item-set membership | `o:item_set` | `schema:isPartOf` |
| Media | `o:media` | `schema:associatedMedia`; the media states `schema:contentUrl`, `schema:encodingFormat` and the work it encodes |
| Authority Resources | `fabio:AuthorityFile` | `skos:Concept`, with `skos:prefLabel` and `skos:inScheme` (its item sets, which are typed `skos:ConceptScheme`) |
| Items with no class | no `rdf:type` | `schema:CreativeWork` |
| `bibo:authorList`, `bibo:editorList` | one value per person | an `rdf:List` in Omeka's order, on hash IRIs (`/resource/{id}#authorList-1`, …), plus `dcterms:creator` / `dcterms:contributor` for each linked person |
| DOIs | `bibo:doi` holding a `https://doi.org/` URL | the bare DOI in `bibo:doi`, plus `schema:sameAs` its `https://doi.org/` IRI |
| Full text and transcripts (`bibo:content`) | included | dropped |

**Titles.** `rdfs:label` (and a concept's `skos:prefLabel`) is the first public value of
the resource template's title property, or of `dcterms:title` when the template sets none,
which is the rule Omeka applies to fill its `resource.title` column. That column itself is
never used: Omeka fills it from the first title value even when the value is private. A
resource with no public title gets no label.

**Identifiers.** `dcterms:identifier` has a literal range, so a URI stored there is published
as a string, and its label beside it when it has one (`"GND 124120881"`, `"sh85149367"`).
The link itself is not lost: an allowlisted authority URI becomes an identity link (below),
and any other URI `rdfs:seeAlso`.

**Values.** Language tags are kept when they are well-formed. Numeric Data Types values
are typed at their stored precision: `xsd:gYear`, `gYearMonth`, `date`, `dateTime`,
`integer` or `duration`, and only when they are real calendar values (`2021-02-31` stays a
plain literal). Intervals (`numeric:interval`, `1975-03/1980`) have no XSD type and are
published as plain literals, whole. `geo:lat` and `geo:long` are `xsd:decimal` when they are
numbers.

**Visibility.** A resource is published when it is public, and a media only when its item
is public too. Values must be public themselves. A link to a resource that is not visible is
dropped entirely rather than turned into a literal, so not even a private item's id leaks.

**Full texts.** `bibo:content` (publication full texts, podcast and video transcripts) is
not projected. The licence on the documents and the dump covers the metadata, which is not
true of the works themselves, and one thesis would otherwise make an 880 KB document.

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

DOIs are handled apart from these identity links: `bibo:doi` takes a bare DOI, so a stored
`https://doi.org/…`, `http://dx.doi.org/…` or `doi:…` value is published as the DOI itself,
with `schema:sameAs` to its resolver IRI. Those links form a VoID linkset of their own. The
DOI literal is percent-decoded; in the IRI, `#`, `?` and `%` stay encoded, so a DOI containing
them never turns into a fragment or a query.

Value annotations (such as a contributor's affiliation for one item) are not projected yet.

## The dump

**Modules → DRE Linked Data → Configure → "Generate the dump now"** starts a background job
that writes every public resource to `files/dre-linked-data/dump.nt.gz`. It is served at
`/linked-data/dump.nt.gz`. The same run is available from the command line (below).

- **Batching.** Resources are read in id-ordered batches of 250, so memory stays flat.
- **Atomic swap.** The file is written beside the old one and swapped in only when complete,
  so downloads never see half a dump.
- **No overlap.** A lock stops two runs overlapping.
- **Stopping.** "Attempt stop" in Admin → Jobs abandons a run and leaves the previous dump
  in place.

Resource documents are always live. The dump and the figures in the VoID description are
not: like DRE Search's index and DRE Visualizations' precompute, **regenerate after a sync.**

**Knowing when it is stale.** Every create, update or delete of an item, item set or media
(through the admin, the API or the sync) is counted. The count goes into one setting
(`dre_ld_changes`), written once per request or job however many resources it touched. The
admin status panel and the page block then say *"Dump is out of date (N changes since …)"*
until the next dump. A new private resource is not counted. Changes are compared with the
time the dump *started*, so one made while a dump runs still counts. The count is
approximate when several writers save at once; the date and the verdict are not.

**From the command line or cron.** `scripts/generate-dump.php` bootstraps Omeka and runs the
generator synchronously:

```bash
# in the Docker image, as the web server's user
su -s /bin/sh www-data -c 'php /var/www/html/modules/DRELinkedData/scripts/generate-dump.php'

# only when something changed since the last dump (for a nightly cron)
php modules/DRELinkedData/scripts/generate-dump.php --if-stale --quiet

# elsewhere
php generate-dump.php --omeka-path=/path/to/omeka-s
```

Run it after the MongoDB2OmekaS sync, or from cron with `--if-stale`. It needs the base URI
to be configured. Exit codes: `0` written (or up to date), `1` failed, `2` bad invocation
(not an Omeka root, module inactive, no base URI), `3` another run holds the lock. Run it as
the user the web server runs as, so the files it writes stay replaceable by the admin job.

**Swapping in.** The new state (`state.json`) is written first, then the dump and the state
are renamed into place one right after the other. The download's `ETag` is the dump's
SHA-256 only when the state's byte count matches the file actually opened, so even in the
instant between the two renames a client never gets a checksum for the wrong file.

To load the dump into Apache Jena Fuseki (Jena reads `.gz` directly):

```bash
tdb2.tdbloader --loc /fuseki/databases/amira amira-linked-data.nt.gz
```

### Serving it over SPARQL

The module does not run a triplestore. To offer a SPARQL endpoint, run one beside Omeka,
load it from the dump, and reload it when `state.json` changes. Its `sha256` identifies the
dump, so compare that rather than the file's timestamp. Then put the endpoint's public
address in the **SPARQL endpoint** setting. The
[omeka-s-docker](https://github.com/AM-Digital-Research-Environment/omeka-s-docker) template
ships one as an opt-in service: Oxigraph, read-only, with no network of its own, reloaded
automatically (`deploy/amira/README.md`, "SPARQL endpoint"). Having no network, it cannot
run federated queries: a `SERVICE` clause fails. Wikidata's query service can still reach
it the other way, from its side, once the endpoint is on its federation allowlist.

## Dataset description (VoID / DCAT)

`/linked-data/void` serves Turtle by default, or the format asked for. `.ttl`, `.nt`,
`.jsonld` and `.rdf` force one, and `/.well-known/void` redirects there. A browser (an
`Accept` that prefers `text/html`) is sent with a `303` to the public site page carrying the
"Linked open data" block, if there is one; without such a page it gets Turtle like everyone
else. The negotiated response names its format-specific URL in `Content-Location`. The
description covers:

- **Dataset node.** One node typed both `void:Dataset` and `dcat:Dataset`, with title and
  description (tagged `@en`), a few `dcat:keyword`s (`@en`, from the `dataset.keywords`
  config), publisher, licence, landing page, `dcterms:issued` (the first dump ever
  published, carried from run to run in the dump state) and `dcterms:modified` (the latest).
- **Contact point.** With a contact email configured, `dcat:contactPoint` a `vcard:Kind`
  with `vcard:hasEmail <mailto:…>`. Nothing is stated without one.
- **SPARQL endpoint.** With an endpoint configured, `void:sparqlEndpoint` on the dataset,
  and a `dcat:DataService` (`#sparql`) with its `dcat:endpointURL`, `dcat:servesDataset` the
  dataset and `dcterms:conformsTo` the SPARQL 1.1 Protocol. Nothing is stated without one.
- **Resolution.** The URI space (`void:uriSpace`), the supported formats, and an example
  resource (the most richly described item).
- **Figures from the last dump.** `void:triples`, `void:entities` and the vocabularies used.
- **Distribution.** A `dcat:Distribution` for the dump, with media type, compression,
  byte size and its SHA-256 as an `spdx:Checksum` (`spdx:checksumValue` typed
  `xsd:hexBinary`), as DCAT 3 recommends.
- **Linksets.** One `void:Linkset` per authority and predicate, with its link count. Its
  `void:objectsTarget` is a `void:Dataset` node for the authority (`#dataset-wikidata`, …)
  with a title, a homepage and the authority's `void:uriSpace`.

## The page block

Add **Linked open data** to a site page (a "Data access" page, say). It shows:

- the dataset's figures;
- the download, with its checksum;
- the VoID links;
- how resource URIs work, with a worked example and a `curl` line;
- with a SPARQL endpoint configured, a query form: the endpoint, five example queries to
  start from (resources by class, the most frequent subjects and places, people with an
  ORCID iD, resources linked to Wikidata), a query editor, and the results as a table, with
  CSV and JSON downloads;
- the linksets and the vocabulary list.

Before the first dump it says so, and still explains the per-resource route, which already
works. Editors can set a heading and pick the example resource.

The query form works without JavaScript: it sends the query to the endpoint with GET and the
browser shows the raw results. `asset/js/dre-linked-data.js` runs it in place instead. It
POSTs the query, draws the first 500 rows (the downloads carry them all), turns `http(s)`
IRIs into links, and follows the DRE asynchronous-state contract (DRE-theme
`docs/DESIGN-INTEGRATION.md`): one persistent `role="status"` node, `aria-busy` only while a
query runs, *Loading…*, an error with *Try again*, and a quiet message with no retry when the
endpoint is unavailable. Technical detail goes to the console, except the endpoint's own
message about a query it rejected, which is how a visitor finds the mistake.

The block is built entirely on the DRE theme's design tokens. It uses colour, type,
spacing, radius and motion tokens and reuses the theme's own `.button`, `.btn--secondary`,
field, `pre` and table styles. Every `var()` fallback is the theme's own value, so it reads correctly in light and
dark mode. It lays out by container query from 320px up. `composer lint:tokens` holds it to
the shared rule set vendored from DRE-theme.

When the data has changed since the dump was taken, the block says so ("Dump is out of
date (N changes since …)"), and that every resource URI already serves the current data.
An editor-chosen example resource that is not public is ignored in favour of the dump's.

## View helper contract (for the theme and DRE SEO)

`dreLinkedData` is a shared contract with DRE-theme and DRE SEO. Both guard on the helper
(`$this->getHelperPluginManager()->has('dreLinkedData')`, in a `try`) and behave as before
when the module is absent or older.

```php
$this->dreLinkedData($resource);
// [] when the resource is not public (a media of a private item is not public either)
// or is not an item, item set or media. Otherwise:
[
  'uri'       => 'https://data.africamultiple.uni-bayreuth.de/resource/41980',
  'page'      => 'https://data.africamultiple.uni-bayreuth.de/s/amira/item/41980', // null without a site
  'sameAs'    => ['http://www.wikidata.org/entity/Q1', 'https://d-nb.info/gnd/…', 'https://doi.org/10.…'],
  'downloads' => [
     ['label' => 'Turtle',    'type' => 'text/turtle',           'url' => '…/resource/41980.ttl'],
     ['label' => 'N-Triples', 'type' => 'application/n-triples', 'url' => '…/resource/41980.nt'],
     ['label' => 'JSON-LD',   'type' => 'application/ld+json',   'url' => '…/resource/41980.jsonld'],
     ['label' => 'RDF/XML',   'type' => 'application/rdf+xml',   'url' => '…/resource/41980.rdf'],
  ],
]
```

- **`uri`** is the persistent identifier: what citations, `cite-as` and the displayed
  "Permanent link" use.
- **`page`** is the HTML record page on the default site (`/s/{slug}/item|item-set|media/{id}`),
  absolute, on the configured base URI. The RDF states it as `foaf:isPrimaryTopicOf`.
- **`sameAs`** lists exactly the identity links the RDF states for the resource: the canonical
  `owl:sameAs` and `skos:exactMatch` targets of its allowlisted authority identifiers, and the
  `https://doi.org/…` IRI of each DOI, in value order, without duplicates. `[]` if none. It is
  one query per resource, remembered for the request.
- **`downloads`** keeps its first three entries as they always were; RDF/XML is appended as
  the fourth. Consumers that show three formats can slice.

```php
$this->dreLinkedData();            // no argument: the helper itself
$this->dreLinkedData()->dataset(); // the dataset as a whole, built once per request
// [] only if the module cannot describe it (no base URI can be determined). Otherwise:
[
  'uri'         => 'https://…/linked-data/void#dataset',
  'void'        => 'https://…/linked-data/void',
  'landingPage' => 'https://…/s/amira/page/linked-open-data', // the page with the block, or null (0.3.1+)
  'title'       => 'Africa Multiple Interactive Research Atlas (AMIRA)',
  'description' => '…',                    // the dataset description setting
  'licence'     => 'https://creativecommons.org/publicdomain/zero/1.0/', // or null
  'publisher'   => ['name' => '…', 'uri' => 'http://www.wikidata.org/entity/Q141717295' /* or null */],
  'modified'    => '2026-10-14T14:17:01Z', // the dump's generation time, or null
  'triples'     => 186131,                 // or null
  'entities'    => 12617,                  // or null
  'distributions' => [                     // [] until a dump exists
     ['label' => 'N-Triples dump (gzip)', 'url' => 'https://…/linked-data/dump.nt.gz',
      'type' => 'application/n-triples', 'encoding' => 'application/gzip', 'bytes' => 1911482],
  ],
  'sparqlEndpoint' => 'https://…/sparql',  // the configured endpoint, or null (0.4.0+)
]
```

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
| Base URI | The `{base}` of every identifier and record page link. **Set this once to the public `https` address and never change it.** Empty means "the address Omeka is reached at", which is fine for testing but not for publication. The status panel above the form warns while it is empty or `http`. |
| Dataset title / description | For the VoID description and the page block. The title defaults to the site title. |
| Publisher name / URI | `dcterms:publisher`. With only a name, an agent IRI is minted under `/linked-data/void#publisher`. |
| Contact email | Optional. Published as the dataset's `dcat:contactPoint` (a vCard). Nothing is published while this is empty. |
| SPARQL endpoint | Optional. The public address of a read-only SPARQL endpoint loaded from the dump (see [Serving it over SPARQL](#serving-it-over-sparql)). Advertised as `void:sparqlEndpoint` and a `dcat:DataService`, and offered on the page block with an example query. Nothing is advertised while this is empty. |
| Metadata licence | A licence URI (e.g. `https://creativecommons.org/publicdomain/zero/1.0/`), stated on every document and the dump. **Nothing is claimed while this is empty.** |
| Generate the dump now | Starts the dump job on save. |

Structural choices — excluded terms, authority patterns and DOI properties, which template
is "concepts", class replacements and the fallback class, list, decimal and literal-range
properties, the classes added to item sets and media, the batch size, the dataset keywords —
are in the `dre_linked_data` block of
`config/module.config.php`, overridable key by key from Omeka's `config/local.config.php`.

## Project layout

```
Module.php                      ACL, record-page conneg listener, alternate links, session
                                release, change counting, config form
config/module.config.php        routes, wiring, and the dre_linked_data instance config
src/Rdf/                        IRI/literal/triple model, prefix map, content negotiation,
                                N-Triples / Turtle / JSON-LD / RDF/XML serialisers
src/Projection/                 OmekaSource (visibility in SQL), ResourceProjector,
                                IdentityLinks, SameAs, UriScheme
src/Dump/                       DumpGenerator (streaming job core), DumpStore (files, lock, state),
                                ChangeTracker (is the dump out of date?)
src/Dataset/                    DatasetDescription (VoID/DCAT), DatasetSummary (view model)
src/Controller/                 /resource/*, /linked-data/*, /.well-known/void
src/Job/GenerateDump.php        the background job
src/Site/BlockLayout/           the "Linked open data" page block
src/View/Helper/LinkedData.php  dreLinkedData($resource) / dreLinkedData()->dataset()
view/                           block template and admin status panel
asset/css/dre-linked-data.css   the block's styles, on the theme's tokens
asset/js/dre-linked-data.js     runs the block's SPARQL form in place
scripts/generate-dump.php       the dump from the command line or cron
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
dispatches the controllers through the real routes, checks their status codes and headers,
and parses Turtle, N-Triples, JSON-LD and RDF/XML with EasyRdf and ml/json-ld from Omeka's
vendor tree. The four must yield the identical graph. Record-page negotiation runs on URLs
matched by Omeka's own route table, item-set pages included.

## Roadmap

- **Value annotations**, as RDF 1.2 triple terms once Fuseki and the serialisers agree.
- **Finer classes for research items**, perhaps from their Type of Resource authority,
  instead of the `schema:CreativeWork` fallback.
- **A dereferenceable `dre:` namespace.** `http://am-digital.org/ontology/dre/` does not
  resolve today.
- **Wider authority coverage** in the source data (Type of Resource → DCMI Type, languages →
  ISO 639-3), which the identity links will pick up without code changes.

## Uninstalling

Uninstall removes the `dre_ld_*` settings (the seven on the form and the change record) and
the `files/dre-linked-data/` directory.
Remove any "Linked open data" page blocks first: Omeka keeps blocks whose layout no longer
exists.

## Citing this module

See [`CITATION.cff`](CITATION.cff); GitHub renders it under "Cite this repository".

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) and, for anything that could expose private data,
[`SECURITY.md`](SECURITY.md).

## Licence

[GPL-3.0-or-later](LICENSE).
