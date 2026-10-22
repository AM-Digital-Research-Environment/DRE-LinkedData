<?php
declare(strict_types=1);

namespace DRELinkedData\Dataset;

use DRELinkedData\Dump\ChangeTracker;
use DRELinkedData\Dump\DumpStore;
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
     * The examples the page block offers, the first shown by default. Each
     * declares its own prefixes so it runs as copied, and each reads only what
     * the projection always writes (classes, labels, subject and place links,
     * owl:sameAs identity links).
     *
     * @var list<array{label:string,query:string}>
     */
    public const EXAMPLE_QUERIES = [
        [
            'label' => 'Resources by class', // @translate
            'query' => self::EXAMPLE_QUERY,
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
            'label' => 'Most frequent places', // @translate
            'query' => <<<'SPARQL'
                PREFIX dcterms: <http://purl.org/dc/terms/>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                SELECT ?place ?label (COUNT(?record) AS ?records)
                WHERE {
                  ?record dcterms:spatial ?place .
                  ?place rdfs:label ?label .
                }
                GROUP BY ?place ?label
                ORDER BY DESC(?records)
                LIMIT 25
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
            'label' => 'Resources linked to Wikidata', // @translate
            'query' => <<<'SPARQL'
                PREFIX owl: <http://www.w3.org/2002/07/owl#>
                PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>

                SELECT ?resource ?label ?wikidata
                WHERE {
                  ?resource owl:sameAs ?wikidata ;
                    rdfs:label ?label .
                  FILTER(STRSTARTS(STR(?wikidata), "http://www.wikidata.org/entity/"))
                }
                LIMIT 100
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
                'bytes' => isset($state['bytes']) ? (int) $state['bytes'] : null,
            ];
        }
        return [
            'uri' => $this->uris->dataset()->value,
            'void' => $this->uris->voidDocument(),
            'landingPage' => $landingPage,
            'title' => $settings->title,
            'description' => $settings->description,
            'licence' => $settings->licence,
            'publisher' => ['name' => $settings->publisherName, 'uri' => $settings->publisherUri],
            'modified' => is_string($state['generated_at'] ?? null) ? $state['generated_at'] : null,
            'triples' => isset($state['triples']) ? (int) $state['triples'] : null,
            'entities' => isset($state['resources']) ? (int) array_sum((array) $state['resources']) : null,
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

        $documents = static fn (callable $url): array => array_map(
            static fn (Format $format): array => [
                'label' => $format->label(),
                'type' => $format->mediaType(),
                'url' => $url($format),
            ],
            Format::cases()
        );

        $exampleId ??= isset($state['example_resource']) ? (int) $state['example_resource'] : null;
        $example = null;
        if ($exampleId) {
            $example = [
                'id' => $exampleId,
                'uri' => $this->uris->resource($exampleId)->value,
                'documents' => $documents(fn (Format $format): string => $this->uris->resourceDocument($exampleId, $format)),
            ];
        }

        $authorities = $this->identityLinks->authorities();
        $prefixes = ($this->prefixes)();
        $linksets = [];
        foreach ((array) ($state['linksets'] ?? []) as $linkset) {
            $authority = (string) ($linkset['authority'] ?? '');
            $predicate = (string) ($linkset['predicate'] ?? '');
            $compact = $prefixes->compact($predicate);
            $linksets[] = [
                'label' => (string) ($authorities[$authority]['label'] ?? $authority),
                'predicate' => $compact ? $compact[0] . ':' . $compact[1] : $predicate,
                'triples' => (int) ($linkset['triples'] ?? 0),
            ];
        }

        $vocabularies = [];
        $byNamespace = array_flip($prefixes->all());
        foreach ((array) ($state['vocabularies'] ?? []) as $namespace) {
            $vocabularies[] = ['prefix' => (string) ($byNamespace[$namespace] ?? ''), 'namespace' => (string) $namespace];
        }

        $resources = (array) ($state['resources'] ?? []);
        return [
            'title' => $settings->title,
            'description' => $settings->description,
            'publisherName' => $settings->publisherName,
            'publisherUri' => $settings->publisherUri,
            'licence' => $settings->licence,
            'licenceLabel' => $settings->licence !== null ? self::licenceLabel($settings->licence) : null,
            'available' => $state !== null,
            'generatedAt' => $state['generated_at'] ?? null,
            'triples' => isset($state['triples']) ? (int) $state['triples'] : null,
            'resources' => [
                'items' => (int) ($resources['items'] ?? 0),
                'itemSets' => (int) ($resources['item_sets'] ?? 0),
                'media' => (int) ($resources['media'] ?? 0),
            ],
            'bytes' => isset($state['bytes']) ? (int) $state['bytes'] : null,
            'sha256' => isset($state['sha256']) ? (string) $state['sha256'] : null,
            'dumpUrl' => $this->uris->dump(),
            'void' => $documents(fn (Format $format): string => $this->uris->voidDocument($format)),
            'voidUrl' => $this->uris->voidDocument(),
            'uriPattern' => $this->uris->resourceSpace() . '{id}',
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

    /**
     * "CC0 1.0", "CC BY 4.0", … for Creative Commons licence URIs; anything
     * else is shown as its URI.
     */
    /** The URL that runs $query at $endpoint with GET (SPARQL 1.1 Protocol). */
    public static function queryUrl(string $endpoint, string $query): string
    {
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . 'query=' . rawurlencode($query);
    }

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
