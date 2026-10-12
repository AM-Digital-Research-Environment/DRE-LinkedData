<?php
declare(strict_types=1);

namespace DRELinkedData\Dump;

use DRELinkedData\Projection\OmekaSource;
use DRELinkedData\Projection\ResourceDescription;
use DRELinkedData\Projection\ResourceProjector;
use DRELinkedData\Rdf\Iri;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\Serializer\NTriplesSerializer;
use DRELinkedData\Rdf\Vocab;
use Laminas\Log\LoggerInterface;

/**
 * Writes the full public graph as gzipped N-Triples.
 *
 * Resources are read in id-ordered batches (keyset paging, so memory stays
 * flat however large the instance grows) and streamed into the gzip file as
 * they are projected. The same {@see ResourceProjector} serves single
 * resources over HTTP, so the dump and `/resource/{id}.nt` cannot disagree.
 *
 * N-Triples is the format Apache Jena's loaders and Fuseki's upload take most
 * directly, and the only one that can be produced one line at a time.
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
    ) {
    }

    /**
     * @param (callable():bool)|null $shouldStop polled between batches; true abandons the run
     * @return array<string,mixed>|null the published state, or null when the run
     *                                  was stopped or another run holds the lock
     */
    public function generate(?callable $shouldStop = null): ?array
    {
        $lock = $this->store->lock();
        if ($lock === null) {
            $this->logger->warn('DRELinkedData: a dump is already being generated; this run did nothing.');
            return null;
        }

        $started = microtime(true);
        $partPath = $this->store->partPath();
        $gzip = gzopen($partPath, 'wb6');
        if ($gzip === false) {
            $this->store->unlock($lock);
            throw new \RuntimeException('Unable to open ' . $partPath . ' for writing.');
        }
        $open = true;

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
                    foreach ($description->triples as $triple) {
                        $chunk .= $serializer->line($triple);
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
            $open = false;

            ksort($vocabularies);
            ksort($linksets);
            $state = [
                'version' => 1,
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'duration_seconds' => round(microtime(true) - $started, 1),
                'triples' => $triples,
                'resources' => [
                    'items' => $resources[ResourceDescription::ITEM],
                    'item_sets' => $resources[ResourceDescription::ITEM_SET],
                    'media' => $resources[ResourceDescription::MEDIA],
                ],
                'bytes' => (int) filesize($partPath),
                'sha256' => (string) hash_file('sha256', $partPath),
                'vocabularies' => array_keys($vocabularies),
                'linksets' => array_map(
                    static function (string $key, int $count): array {
                        [$authority, $predicate] = explode("\0", $key, 2);
                        return ['authority' => $authority, 'predicate' => $predicate, 'triples' => $count];
                    },
                    array_keys($linksets),
                    array_values($linksets)
                ),
                'example_resource' => $example,
            ];
            $this->store->publish($partPath, $state);

            $this->logger->info(sprintf(
                'DRELinkedData: wrote %d triples for %d resources in %.1f s.',
                $triples,
                array_sum($resources),
                $state['duration_seconds']
            ));
            return $state;
        } finally {
            if ($open) {
                gzclose($gzip);
            }
            $this->store->discard($partPath);
            $this->store->unlock($lock);
        }
    }
}
