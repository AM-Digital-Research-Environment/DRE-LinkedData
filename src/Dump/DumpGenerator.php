<?php
declare(strict_types=1);

namespace DRELinkedData\Dump;

use DRELinkedData\Projection\OmekaSource;
use DRELinkedData\Projection\ResourceDescription;
use DRELinkedData\Projection\ResourceProjector;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\Iri;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\Serializer\NTriplesSerializer;
use DRELinkedData\Rdf\Vocab;
use Laminas\Log\LoggerInterface;

/**
 * Writes the full public graph as gzipped N-Triples, and the same statements
 * as gzipped N-Quads with one named graph per kind of record ({@see GraphMap}).
 *
 * Resources are read in id-ordered batches (keyset paging, so memory stays
 * flat however large the instance grows) and streamed into the gzip file as
 * they are projected. The same {@see ResourceProjector} serves single
 * resources over HTTP, so the dump and `/resource/{id}.nt` cannot disagree.
 *
 * N-Triples is the format Apache Jena's loaders and Fuseki's upload take most
 * directly, and the only one that can be produced one line at a time; N-Quads
 * is its line-based twin for datasets. Both files are written in one pass, so
 * they hold the same statements: the N-Quads file only adds the graph names.
 */
final class DumpGenerator
{
    public function __construct(
        private readonly ResourceProjector $projector,
        private readonly OmekaSource $source,
        private readonly DumpStore $store,
        private readonly PrefixMap $prefixes,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize = 250,
        private readonly ?ChangeTracker $changes = null,
        private readonly ?GraphMap $graphs = null,
        private readonly ?UriScheme $uris = null,
    ) {
    }

    /**
     * @param (callable():bool)|null $shouldStop polled between batches; true abandons the run
     * @return DumpState|null the published state, or null when the run was
     *                        stopped or another run holds the lock
     */
    public function generate(?callable $shouldStop = null): ?DumpState
    {
        $lock = $this->store->lock();
        if ($lock === null) {
            $this->logger->warn('DRELinkedData: a dump is already being generated; this run did nothing.');
            return null;
        }

        $started = microtime(true);
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        $previous = $this->store->state();
        $partPath = $this->store->partPath();
        $gzip = gzopen($partPath, 'wb6');
        if ($gzip === false) {
            $this->store->unlock($lock);
            throw new \RuntimeException('Unable to open ' . $partPath . ' for writing.');
        }
        $open = true;
        // The N-Quads twin needs graph names, hence both the map and the URIs.
        $quads = $this->graphs !== null && $this->uris !== null;
        $nquadsPath = $quads ? $this->store->partPath() : null;
        $nquads = $nquadsPath !== null ? gzopen($nquadsPath, 'wb6') : null;
        if ($nquads === false) {
            gzclose($gzip);
            $this->store->discard($partPath);
            $this->store->unlock($lock);
            throw new \RuntimeException('Unable to open ' . $nquadsPath . ' for writing.');
        }
        /** @var array<string,Iri> $graphIris */
        $graphIris = [];
        /** @var array<string,array{triples:int,entities:int}> $graphCounts */
        $graphCounts = [];

        $serializer = new NTriplesSerializer();
        $triples = 0;
        $resources = [ResourceDescription::ITEM => 0, ResourceDescription::ITEM_SET => 0, ResourceDescription::MEDIA => 0];
        /** @var array<string,int> $linksets "authority\0predicate" => count */
        $linksets = [];
        /** @var array<string,string|null> $namespaceOf IRI => namespace, memoised */
        $namespaceOf = [];
        $vocabularies = [];
        $example = null;
        $exampleSize = 0;

        try {
            $afterId = 0;
            while ($ids = $this->source->idsAfter($afterId, $this->batchSize)) {
                if ($shouldStop !== null && $shouldStop()) {
                    $this->logger->notice('DRELinkedData: dump generation stopped on request; the previous dump is unchanged.');
                    return null;
                }
                $afterId = (int) end($ids);

                foreach ($this->projector->describe($ids) as $description) {
                    $chunk = '';
                    $quadChunk = '';
                    $graph = null;
                    if ($quads) {
                        $key = $this->graphs->graphFor($description);
                        $graph = $graphIris[$key] ??= $this->uris->graph($key);
                        $graphCounts[$key] ??= ['triples' => 0, 'entities' => 0];
                        $graphCounts[$key]['triples'] += count($description->triples);
                        $graphCounts[$key]['entities']++;
                    }
                    foreach ($description->triples as $triple) {
                        $chunk .= $serializer->line($triple);
                        if ($graph !== null) {
                            $quadChunk .= $serializer->quad($triple, $graph);
                        }
                        $terms = [$triple->predicate->value];
                        if ($triple->predicate->value === Vocab::RDF_TYPE && $triple->object instanceof Iri) {
                            $terms[] = $triple->object->value;
                        }
                        foreach ($terms as $term) {
                            $namespace = $namespaceOf[$term] ??= $this->prefixes->namespaceOf($term) ?? '';
                            if ($namespace !== '') {
                                $vocabularies[$namespace] = true;
                            }
                        }
                    }
                    if ($chunk !== '' && gzwrite($gzip, $chunk) === false) {
                        throw new \RuntimeException('Writing to ' . $partPath . ' failed.');
                    }
                    if ($nquads !== null && $quadChunk !== '' && gzwrite($nquads, $quadChunk) === false) {
                        throw new \RuntimeException('Writing to ' . $nquadsPath . ' failed.');
                    }

                    $triples += count($description->triples);
                    $resources[$description->kind]++;
                    foreach ($description->identityLinks as $link) {
                        $key = $link['authority'] . "\0" . $link['predicate'];
                        $linksets[$key] = ($linksets[$key] ?? 0) + 1;
                    }
                    // The richest item makes the most instructive example.
                    if ($description->kind === ResourceDescription::ITEM && count($description->triples) > $exampleSize) {
                        $example = $description->id;
                        $exampleSize = count($description->triples);
                    }
                }
            }

            gzclose($gzip);
            if ($nquads !== null) {
                gzclose($nquads);
            }
            $open = false;
            // In the configured order, so the VoID lists them as the config does.
            $graphList = [];
            foreach ($quads ? array_keys($this->graphs->titles()) : [] as $key) {
                if (isset($graphCounts[$key])) {
                    $graphList[] = ['graph' => $key] + $graphCounts[$key];
                }
            }

            ksort($vocabularies);
            ksort($linksets);
            $generatedAt = gmdate('Y-m-d\TH:i:s\Z');
            $state = new DumpState(
                generatedAt: $generatedAt,
                startedAt: $startedAt,
                // When the dataset was first published (dcterms:issued), carried
                // over from run to run.
                firstGeneratedAt: $previous->firstGeneratedAt ?? $previous->generatedAt ?? $generatedAt,
                durationSeconds: round(microtime(true) - $started, 1),
                triples: $triples,
                items: $resources[ResourceDescription::ITEM],
                itemSets: $resources[ResourceDescription::ITEM_SET],
                media: $resources[ResourceDescription::MEDIA],
                bytes: (int) filesize($partPath),
                sha256: (string) hash_file('sha256', $partPath),
                vocabularies: array_keys($vocabularies),
                linksets: array_map(
                    static function (string $key, int $count): array {
                        [$authority, $predicate] = explode("\0", $key, 2);
                        return ['authority' => $authority, 'predicate' => $predicate, 'triples' => $count];
                    },
                    array_keys($linksets),
                    array_values($linksets)
                ),
                exampleResource: $example,
                nquadsBytes: $nquadsPath !== null ? (int) filesize($nquadsPath) : null,
                nquadsSha256: $nquadsPath !== null ? (string) hash_file('sha256', $nquadsPath) : null,
                graphs: $graphList,
            );
            $this->store->publish($partPath, $state, $nquadsPath);
            $this->changes?->settle($startedAt);

            $this->logger->info(sprintf(
                'DRELinkedData: wrote %d triples for %d resources in %.1f s.',
                $triples,
                $state->entities(),
                $state->durationSeconds
            ));
            return $state;
        } finally {
            if ($open) {
                gzclose($gzip);
                if ($nquads !== null) {
                    gzclose($nquads);
                }
            }
            $this->store->discard($partPath);
            if ($nquadsPath !== null) {
                $this->store->discard($nquadsPath);
            }
            $this->store->unlock($lock);
        }
    }
}
