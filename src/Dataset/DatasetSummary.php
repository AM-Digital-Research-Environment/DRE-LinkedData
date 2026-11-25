<?php
declare(strict_types=1);

namespace DRELinkedData\Dataset;

use DRELinkedData\Dump\ChangeTracker;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Dump\GraphMap;
use DRELinkedData\Projection\IdentityLinks;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Settings\DatasetSettings;

/**
 * The plain-array view model behind the public page block and the admin
 * status panel: what the dataset is, what the last dump contains, whether it
 * is out of date, and where every machine-readable entry point lives.
 * Templates only format it. {@see dataset()} is the same information in the
 * shape the dreLinkedData()->dataset() helper contract promises.
 */
final class DatasetSummary
{
    /** The worked example on the page block: what the dataset describes, by class. */
    public const EXAMPLE_QUERY = <<<'SPARQL'
        SELECT ?class (COUNT(?resource) AS ?resources)
        WHERE { ?resource a ?class }
        GROUP BY ?class
        ORDER BY DESC(?resources)
        SPARQL;

    /**
     * The kinds of record, for the page block's "How the data is modelled"
     * section: what each is called, the classes it carries, and the key of
     * its named graph in the N-Quads dump (config dump.graphs).
     *
     * @var list<array{label:string,classes:string,graph:string}>
     */
    public const MODEL_KINDS = [
        [
            'label' => 'Research items', // @translate
            'classes' => 'schema:CreativeWork, dre:ResearchItem, dctype:Text, dctype:Sound, …',
            'graph' => 'research-items',
        ],
        [
            'label' => 'Publications', // @translate
            'classes' => 'fabio:JournalArticle, fabio:Book, fabio:BookChapter, fabio:WorkingPaper, …',
            'graph' => 'publications',
        ],
        [
            'label' => 'Persons', // @translate
            'classes' => 'foaf:Person',
            'graph' => 'persons',
        ],
        [
            'label' => 'Organisations', // @translate
            'classes' => 'foaf:Organization',
            'graph' => 'organisations',
        ],
        [
            'label' => 'Research sections', // @translate
            'classes' => 'frapo:ResearchGroup',
            'graph' => 'research-sections',
        ],
        [
            'label' => 'Projects', // @translate
            'classes' => 'foaf:Project',
            'graph' => 'projects',
        ],
        [
            'label' => 'Subjects, languages, genres and other concepts', // @translate
            'classes' => 'skos:Concept, skos:inScheme its list',
            'graph' => 'concepts',
        ],
        [
            'label' => 'Places', // @translate
            'classes' => 'dcterms:Location',
            'graph' => 'places',
        ],
        [
            'label' => 'Journals and series', // @translate
            'classes' => 'bibo:Journal, bibo:Series',
            'graph' => 'venues',
        ],
        [
            'label' => 'Podcasts, videos, Cluster Chronicles', // @translate
            'classes' => 'fabio:AudioDocument, bibo:AudioVisualDocument, fabio:Essay, …',
            'graph' => 'podcasts, videos, chronicles',
        ],
        [
            'label' => 'Item sets', // @translate
            'classes' => 'dctype:Collection',
            'graph' => 'item-sets',
        ],
        [
            'label' => 'Media (files)', // @translate
            'classes' => 'schema:MediaObject',
            'graph' => 'media',
        ],
    ];

    /**
     * The predicates that link records, for the same section: the property,
     * what it links, and what it means.
     *
     * @var list<array{predicate:string,links:string,meaning:string}>
     */
    public const MODEL_LINKS = [
        [
            'predicate' => 'dcterms:creator, dcterms:contributor',
            'links' => 'record → person or organisation', // @translate
            'meaning' => 'Anyone involved, whatever the role: creator for authors, artists, interviewers and other creators, contributor for every other role.', // @translate
        ],
        [
            'predicate' => 'marcrel:aut, marcrel:rtm, marcrel:ivr, marcrel:spn, …',
            'links' => 'record → person or organisation', // @translate
            'meaning' => 'The precise role (author, research team member, interviewer, sponsor, …), from the MARC relators.', // @translate
        ],
        [
            'predicate' => 'bibo:authorList, bibo:editorList',
            'links' => 'publication → list of persons', // @translate
            'meaning' => 'Authors and editors in their published order, as an rdf:List.', // @translate
        ],
        [
            'predicate' => 'dcterms:isPartOf',
            'links' => 'research item → project; project → research section; publication → journal, book or series; place → country; person → institution', // @translate
            'meaning' => 'The record\'s immediate whole.', // @translate
        ],
        [
            'predicate' => 'dcterms:subject',
            'links' => 'record → concept, person, organisation or place', // @translate
            'meaning' => 'What the record is about.', // @translate
        ],
        [
            'predicate' => 'dcterms:spatial',
            'links' => 'record → place', // @translate
            'meaning' => 'Place of origin.', // @translate
        ],
        [
            'predicate' => 'dcterms:provenance',
            'links' => 'research item → organisation or place', // @translate
            'meaning' => 'Where the original is held now.', // @translate
        ],
        [
            'predicate' => 'frapo:isFundedBy',
            'links' => 'record or project → organisation', // @translate
            'meaning' => 'Funder.', // @translate
        ],
        [
            'predicate' => 'dcterms:type, dcterms:language, dcterms:format, dcterms:audience',
            'links' => 'record → concept', // @translate
            'meaning' => 'Type of resource, language, genre, target audience.', // @translate
        ],
        [
            'predicate' => 'foaf:member',
            'links' => 'project or research section → person', // @translate
            'meaning' => 'Member; dcterms:creator on a project names its principal investigators.', // @translate
        ],
        [
            'predicate' => 'schema:isPartOf, schema:associatedMedia',
            'links' => 'item → item set; item → media', // @translate
            'meaning' => 'The item sets it belongs to and its files.', // @translate
        ],
        [
            'predicate' => 'owl:sameAs, skos:exactMatch',
            'links' => 'entity or concept → Wikidata, GND, Library of Congress, ORCID, …', // @translate
            'meaning' => 'The same entity, or a matching concept, in another dataset.', // @translate
        ],
    ];

    /**
     * The examples the page block offers, the first shown by default. Each
     * declares its own prefixes so it runs as copied. Most follow links
     * between records: from a person, a project, a subject or a place to the
     * records that point at them.
     *
     * @var list<array{label:string,query:string}>
     */
    public const EXAMPLE_QUERIES = [
        [
            'label' => 'Resources by class', // @translate
            'query' => self::EXAMPLE_QUERY,
        ],
        [
            'label' => 'Everything linked to a person, whatever the role', // @translate
            'query' => <<<'SPARQL'
                PREFIX foaf: <http://xmlns.com/foaf/0.1/>
                PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                # Every predicate that points at the person: each role (author,
                # translator, …), subject, membership. Change the name to try another.
                SELECT ?record ?title ?role
                WHERE {
                  ?person a foaf:Person ;
                    rdfs:label "Beier, Ulli" .
                  ?record ?role ?person .
                  FILTER(?role != rdf:first)
                  OPTIONAL { ?record rdfs:label ?title }
                }
                ORDER BY ?role ?title
                LIMIT 200
                SPARQL,
        ],
        [
            'label' => 'The records of a project', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX foaf: <http://xmlns.com/foaf/0.1/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                # Research items point at their project with dcterms:isPartOf.
                SELECT ?record ?title ?type
                WHERE {
                  ?project a foaf:Project ;
                    rdfs:label "Taiwo Shango" .
                  ?record dcterms:isPartOf ?project ;
                    rdfs:label ?title .
                  OPTIONAL { ?record dcterms:type/rdfs:label ?type }
                }
                ORDER BY ?title
                LIMIT 200
                SPARQL,
        ],
        [
            'label' => 'Projects and their number of records', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX foaf: <http://xmlns.com/foaf/0.1/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                SELECT ?project ?title (COUNT(?record) AS ?records)
                WHERE {
                  ?project a foaf:Project ;
                    rdfs:label ?title .
                  ?record dcterms:isPartOf ?project .
                }
                GROUP BY ?project ?title
                ORDER BY DESC(?records)
                LIMIT 50
                SPARQL,
        ],
        [
            'label' => 'Everyone who worked on a project\'s records', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX foaf: <http://xmlns.com/foaf/0.1/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                # dcterms:creator and dcterms:contributor stand for every role.
                SELECT ?agent ?name (COUNT(DISTINCT ?record) AS ?records)
                WHERE {
                  ?project a foaf:Project ;
                    rdfs:label "Taiwo Shango" .
                  ?record dcterms:isPartOf ?project ;
                    dcterms:creator|dcterms:contributor ?agent .
                  ?agent rdfs:label ?name .
                }
                GROUP BY ?agent ?name
                ORDER BY ?name
                SPARQL,
        ],
        [
            'label' => 'Projects by research section, with their investigators', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX foaf: <http://xmlns.com/foaf/0.1/>
                PREFIX frapo: <http://purl.org/cerif/frapo/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                SELECT ?section ?project ?title
                  (GROUP_CONCAT(DISTINCT ?name; separator=" · ") AS ?investigators)
                WHERE {
                  ?project a foaf:Project ;
                    rdfs:label ?title ;
                    dcterms:isPartOf ?group .
                  ?group a frapo:ResearchGroup ;
                    rdfs:label ?section .
                  OPTIONAL { ?project dcterms:creator/rdfs:label ?name }
                }
                GROUP BY ?section ?project ?title
                ORDER BY ?section ?title
                SPARQL,
        ],
        [
            'label' => 'Most frequent subjects', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX skos: <http://www.w3.org/2004/02/skos/core#>

                SELECT ?subject ?label (COUNT(?record) AS ?records)
                WHERE {
                  ?record dcterms:subject ?subject .
                  ?subject skos:prefLabel ?label .
                }
                GROUP BY ?subject ?label
                ORDER BY DESC(?records)
                LIMIT 25
                SPARQL,
        ],
        [
            'label' => 'Records about a subject', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                PREFIX skos: <http://www.w3.org/2004/02/skos/core#>

                # Every subject heading containing the word, and its records.
                SELECT ?record ?title ?subject ?heading
                WHERE {
                  ?subject a skos:Concept ;
                    skos:prefLabel ?heading .
                  FILTER(CONTAINS(LCASE(?heading), "music"))
                  ?record dcterms:subject ?subject ;
                    rdfs:label ?title .
                }
                ORDER BY ?heading ?title
                LIMIT 200
                SPARQL,
        ],
        [
            'label' => 'Subjects that occur together', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX skos: <http://www.w3.org/2004/02/skos/core#>

                SELECT ?first ?second (COUNT(DISTINCT ?record) AS ?records)
                WHERE {
                  ?record dcterms:subject ?a, ?b .
                  ?a skos:prefLabel ?first .
                  ?b skos:prefLabel ?second .
                  FILTER(STR(?a) < STR(?b))
                }
                GROUP BY ?first ?second
                ORDER BY DESC(?records)
                LIMIT 25
                SPARQL,
        ],
        [
            'label' => 'Places of origin, with coordinates', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                SELECT ?place ?name ?lat ?long (COUNT(?record) AS ?records)
                WHERE {
                  ?record dcterms:spatial ?place .
                  ?place a dcterms:Location ;
                    rdfs:label ?name ;
                    geo:lat ?lat ;
                    geo:long ?long .
                }
                GROUP BY ?place ?name ?lat ?long
                ORDER BY DESC(?records)
                LIMIT 100
                SPARQL,
        ],
        [
            'label' => 'Where research items are held', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                # dcterms:provenance is the current location: an institution or a place.
                SELECT ?holder ?name ?kind (COUNT(?record) AS ?records)
                WHERE {
                  ?record dcterms:provenance ?holder .
                  ?holder rdfs:label ?name ;
                    a ?kind .
                }
                GROUP BY ?holder ?name ?kind
                ORDER BY DESC(?records)
                LIMIT 50
                SPARQL,
        ],
        [
            'label' => 'Research items by type', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX dre: <https://data.africamultiple.uni-bayreuth.de/ontology/dre#>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                # The Type of Resource, and the DCMI Type class it maps to.
                SELECT ?type ?dcmiType (COUNT(?item) AS ?items)
                WHERE {
                  ?item a dre:ResearchItem ;
                    dcterms:type/rdfs:label ?type .
                  OPTIONAL {
                    ?item a ?dcmiType .
                    FILTER(STRSTARTS(STR(?dcmiType), "http://purl.org/dc/dcmitype/"))
                  }
                }
                GROUP BY ?type ?dcmiType
                ORDER BY DESC(?items)
                SPARQL,
        ],
        [
            'label' => 'Publications and their authors', // @translate
            'query' => <<<'SPARQL'
                PREFIX bibo: <http://purl.org/ontology/bibo/>
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                PREFIX schema: <https://schema.org/>

                # The publications item set; authors from the ordered author list.
                SELECT ?publication ?title ?date
                  (GROUP_CONCAT(?name; separator=" · ") AS ?authors)
                WHERE {
                  ?publication schema:isPartOf <https://data.africamultiple.uni-bayreuth.de/resource/29918> ;
                    rdfs:label ?title ;
                    bibo:authorList/rdf:rest*/rdf:first ?author .
                  ?author rdfs:label ?name .
                  OPTIONAL { ?publication dcterms:date ?date }
                }
                GROUP BY ?publication ?title ?date
                ORDER BY DESC(?date) ?title
                LIMIT 100
                SPARQL,
        ],
        [
            'label' => 'Records matched to Wikidata, by class', // @translate
            'query' => <<<'SPARQL'
                PREFIX owl: <http://www.w3.org/2002/07/owl#>
                PREFIX skos: <http://www.w3.org/2004/02/skos/core#>

                SELECT ?class (COUNT(DISTINCT ?entity) AS ?matched)
                WHERE {
                  ?entity owl:sameAs|skos:exactMatch ?wikidata ;
                    a ?class .
                  FILTER(STRSTARTS(STR(?wikidata), "http://www.wikidata.org/entity/"))
                }
                GROUP BY ?class
                ORDER BY DESC(?matched)
                SPARQL,
        ],
        [
            'label' => 'People with an ORCID iD', // @translate
            'query' => <<<'SPARQL'
                PREFIX foaf: <http://xmlns.com/foaf/0.1/>
                PREFIX owl: <http://www.w3.org/2002/07/owl#>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                SELECT ?person ?name ?orcid
                WHERE {
                  ?person a foaf:Person ;
                    rdfs:label ?name ;
                    owl:sameAs ?orcid .
                  FILTER(STRSTARTS(STR(?orcid), "https://orcid.org/"))
                }
                ORDER BY ?name
                LIMIT 100
                SPARQL,
        ],
        [
            'label' => 'Named graphs and their size', // @translate
            'query' => <<<'SPARQL'
                # One named graph per kind of record. Without GRAPH, a query
                # reads them all together.
                SELECT ?graph (COUNT(*) AS ?triples)
                WHERE {
                  GRAPH ?graph { ?s ?p ?o }
                }
                GROUP BY ?graph
                ORDER BY DESC(?triples)
                SPARQL,
        ],
    ];

    /**
     * @param \Closure():PrefixMap $prefixes
     * @param (\Closure(int):bool)|null $isVisible whether a resource id is public
     */
    public function __construct(
        private readonly DumpStore $store,
        private readonly UriScheme $uris,
        private readonly IdentityLinks $identityLinks,
        private readonly \Closure $prefixes,
        private readonly ?\Closure $isVisible = null,
        private readonly ?ChangeTracker $changes = null,
        private readonly ?GraphMap $graphs = null,
    ) {
    }

    /**
     * The dataset as the view helper contract describes it (README, "For the
     * theme and DRE SEO").
     *
     * @return array<string,mixed>
     */
    public function dataset(DatasetSettings $settings, ?string $landingPage = null): array
    {
        $state = $this->store->state();
        $distributions = [];
        if ($state !== null) {
            $distributions[] = [
                'label' => 'N-Triples dump (gzip)',
                'url' => $this->uris->dump(),
                'type' => Format::NTriples->mediaType(),
                'encoding' => 'application/gzip',
                'bytes' => $state->bytes,
            ];
            // Appended, so distributions[0] stays the N-Triples dump (0.8.0+).
            if ($state->nquadsSha256 !== null) {
                $distributions[] = [
                    'label' => 'N-Quads dump (gzip)',
                    'url' => $this->uris->dumpNquads(),
                    'type' => 'application/n-quads',
                    'encoding' => 'application/gzip',
                    'bytes' => $state->nquadsBytes,
                ];
            }
        }
        return [
            'uri' => $this->uris->dataset()->value,
            'void' => $this->uris->voidDocument(),
            'landingPage' => $landingPage,
            'title' => $settings->title,
            'description' => $settings->description,
            'licence' => $settings->licence,
            'publisher' => ['name' => $settings->publisherName, 'uri' => $settings->publisherUri],
            'modified' => $state?->generatedAt,
            'triples' => $state?->triples,
            'entities' => $state?->entities(),
            'distributions' => $distributions,
            'sparqlEndpoint' => $settings->sparqlEndpoint,
        ];
    }

    /**
     * @param int|null $exampleId a resource to show as the worked example;
     *                            defaults to the dump's richest item. One that
     *                            is not public is ignored: its documents 404.
     * @return array<string,mixed>
     */
    public function build(DatasetSettings $settings, ?int $exampleId = null): array
    {
        $state = $this->store->state();
        if ($exampleId !== null && $this->isVisible !== null && !($this->isVisible)($exampleId)) {
            $exampleId = null;
        }

        $exampleId ??= $state?->exampleResource;
        $example = null;
        if ($exampleId) {
            $example = [
                'id' => $exampleId,
                'uri' => $this->uris->resource($exampleId)->value,
                'documents' => Format::documents(fn (Format $format): string => $this->uris->resourceDocument($exampleId, $format)),
            ];
        }

        $authorities = $this->identityLinks->authorities();
        $prefixes = ($this->prefixes)();
        $linksets = [];
        foreach ($state->linksets ?? [] as $linkset) {
            $authority = $linkset['authority'];
            $predicate = $linkset['predicate'];
            $compact = $prefixes->compact($predicate);
            $linksets[] = [
                'label' => (string) ($authorities[$authority]['label'] ?? $authority),
                'predicate' => $compact ? $compact[0] . ':' . $compact[1] : $predicate,
                'triples' => $linkset['triples'],
            ];
        }

        $vocabularies = [];
        $byNamespace = array_flip($prefixes->all());
        foreach ($state->vocabularies ?? [] as $namespace) {
            $vocabularies[] = ['prefix' => (string) ($byNamespace[$namespace] ?? ''), 'namespace' => $namespace];
        }

        return [
            'title' => $settings->title,
            'description' => $settings->description,
            'publisherName' => $settings->publisherName,
            'publisherUri' => $settings->publisherUri,
            'licence' => $settings->licence,
            'licenceLabel' => $settings->licence !== null ? self::licenceLabel($settings->licence) : null,
            'available' => $state !== null,
            'generatedAt' => $state?->generatedAt,
            'triples' => $state?->triples,
            'resources' => [
                'items' => $state->items ?? 0,
                'itemSets' => $state->itemSets ?? 0,
                'media' => $state->media ?? 0,
            ],
            'bytes' => $state?->bytes,
            'sha256' => $state?->sha256,
            'dumpUrl' => $this->uris->dump(),
            // The N-Quads twin, null for a dump written before it existed.
            'nquads' => $state?->nquadsSha256 !== null ? [
                'url' => $this->uris->dumpNquads(),
                'bytes' => $state->nquadsBytes,
                'sha256' => $state->nquadsSha256,
                'graphs' => array_map(
                    fn (array $graph): array => $graph + [
                        'uri' => $this->uris->graph($graph['graph'])->value,
                        'title' => $this->graphs?->titles()[$graph['graph']] ?? $graph['graph'],
                    ],
                    $state->graphs
                ),
            ] : null,
            'void' => Format::documents(fn (Format $format): string => $this->uris->voidDocument($format)),
            'voidUrl' => $this->uris->voidDocument(),
            'uriPattern' => $this->uris->resourceSpace() . '{id}',
            // "How the data is modelled": the kinds of record and the links
            // between them, plus the pattern of the named graphs' names.
            'model' => [
                'kinds' => self::MODEL_KINDS,
                'links' => self::MODEL_LINKS,
                'graphPattern' => $this->uris->voidDocument() . '#graph-{name}',
            ],
            'example' => $example,
            'linksets' => $linksets,
            'vocabularies' => $vocabularies,
            // ['endpoint', 'query', 'url', 'examples'] when an endpoint is
            // configured: the default example, the URL that runs it with GET,
            // and every example as ['label', 'query', 'url'].
            'sparql' => $settings->sparqlEndpoint !== null ? [
                'endpoint' => $settings->sparqlEndpoint,
                'query' => self::EXAMPLE_QUERY,
                'url' => self::queryUrl($settings->sparqlEndpoint, self::EXAMPLE_QUERY),
                'examples' => array_map(
                    static fn (array $example): array => $example + [
                        'url' => self::queryUrl((string) $settings->sparqlEndpoint, $example['query']),
                    ],
                    self::EXAMPLE_QUERIES
                ),
            ] : null,
            // ['since' => ISO date, 'count' => n] when the data changed after
            // the dump was taken; null when it is up to date (or never made).
            'changes' => $state !== null ? $this->changes?->pending($state) : null,
        ];
    }

    /** The URL that runs $query at $endpoint with GET (SPARQL 1.1 Protocol). */
    public static function queryUrl(string $endpoint, string $query): string
    {
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . 'query=' . rawurlencode($query);
    }

    /**
     * "CC0 1.0", "CC BY 4.0", … for Creative Commons licence URIs; anything
     * else is shown as its URI.
     */
    public static function licenceLabel(string $uri): string
    {
        if (preg_match('~^https?://creativecommons\.org/publicdomain/zero/([\d.]+)/?$~i', $uri, $match)) {
            return 'CC0 ' . $match[1];
        }
        if (preg_match('~^https?://creativecommons\.org/publicdomain/mark/([\d.]+)/?$~i', $uri, $match)) {
            return 'Public Domain Mark ' . $match[1];
        }
        if (preg_match('~^https?://creativecommons\.org/licenses/([a-z-]+)/([\d.]+)/?~i', $uri, $match)) {
            return 'CC ' . strtoupper($match[1]) . ' ' . $match[2];
        }
        return $uri;
    }
}
