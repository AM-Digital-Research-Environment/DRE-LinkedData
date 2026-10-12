<?php
declare(strict_types=1);

use DRELinkedData\Dump\DumpGenerator;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\Serializer\NTriplesSerializer;

function dumpGenerator(DumpStore $store, MemoryLogger $logger, int $batchSize = 2): DumpGenerator
{
    $source = amiraSource();
    return new DumpGenerator(
        amiraProjector($source),
        $source,
        $store,
        PrefixMap::withVocabularies($source->vocabularyRows),
        $logger,
        $batchSize
    );
}

test('the dump is the concatenation of every resource document, gzipped', function (): void {
    $dir = tempDirectory('dump');
    try {
        $store = new DumpStore($dir);
        $logger = new MemoryLogger();
        $state = dumpGenerator($store, $logger)->generate();
        assertTrue($state !== null, 'generation should succeed');

        $expected = '';
        foreach (amiraProjector()->describe([149, 2390, 6259, 7392, 8001, 8002]) as $description) {
            $expected .= (new NTriplesSerializer())->serialize($description->triples);
        }
        $written = gzdecode((string) file_get_contents($store->dumpPath()));
        assertSameValue($expected, $written, 'batching must not change the output');

        assertSameValue(substr_count($expected, "\n"), $state['triples']);
        assertSameValue(['items' => 3, 'item_sets' => 1, 'media' => 2], $state['resources']);
        assertSameValue(filesize($store->dumpPath()), $state['bytes']);
        assertSameValue(hash_file('sha256', $store->dumpPath()), $state['sha256']);
        assertSameValue(7392, $state['example_resource'], 'the richest item is the example');
        assertSameValue([
            ['authority' => 'gnd', 'predicate' => 'http://www.w3.org/2002/07/owl#sameAs', 'triples' => 1],
            ['authority' => 'loc', 'predicate' => 'http://www.w3.org/2004/02/skos/core#exactMatch', 'triples' => 1],
        ], $state['linksets']);
        assertTrue(in_array('http://am-digital.org/ontology/dre/', $state['vocabularies'], true));
        assertTrue(in_array('https://schema.org/', $state['vocabularies'], true));
        assertTrue(in_array('http://www.w3.org/2004/02/skos/core#', $state['vocabularies'], true));
        assertTrue(in_array('http://purl.org/ontology/bibo/', $state['vocabularies'], true));
        assertTrue(!in_array('http://xmlns.com/foaf/0.1/', $state['vocabularies'], true) || str_contains($expected, 'xmlns.com/foaf'), 'only vocabularies in use are listed');
        assertSameValue($state, $store->state(), 'the state on disk is what generate() returned');
        assertSameValue([], glob($dir . '/.*.part') ?: [], 'no part files are left behind');
        assertTrue(str_contains($logger->records['info'][0] ?? '', 'wrote ' . $state['triples'] . ' triples for 6 resources'));
    } finally {
        removeDirectory($dir);
    }
});

test('a stopped run leaves the previous dump in place', function (): void {
    $dir = tempDirectory('dump-stop');
    try {
        $store = new DumpStore($dir);
        $first = dumpGenerator($store, new MemoryLogger())->generate();
        $before = file_get_contents($store->dumpPath());

        $logger = new MemoryLogger();
        $polls = 0;
        $result = dumpGenerator($store, $logger)->generate(static function () use (&$polls): bool {
            return ++$polls > 1;
        });
        assertSameValue(null, $result);
        assertSameValue($before, file_get_contents($store->dumpPath()));
        assertSameValue($first, $store->state());
        assertSameValue([], glob($dir . '/.*.part') ?: []);
        assertTrue(isset($logger->records['notice']), 'the stop is logged');
    } finally {
        removeDirectory($dir);
    }
});

test('a second run does nothing while the first holds the lock', function (): void {
    $dir = tempDirectory('dump-lock');
    try {
        $store = new DumpStore($dir);
        $lock = $store->lock();
        assertTrue($lock !== null, 'the first lock is granted');
        $logger = new MemoryLogger();
        assertSameValue(null, dumpGenerator($store, $logger)->generate());
        assertTrue(!$store->hasDump());
        assertTrue(isset($logger->records['warn']));
        $store->unlock($lock);
        assertTrue(dumpGenerator($store, new MemoryLogger())->generate() !== null, 'free again after unlock');
    } finally {
        removeDirectory($dir);
    }
});

test('the store reports no state without a dump, and clear() removes everything', function (): void {
    $dir = tempDirectory('dump-clear');
    $store = new DumpStore($dir . '/dre-linked-data');
    assertSameValue(null, $store->state());
    file_put_contents($store->partPath(), 'x');
    $store->clear();
    assertTrue(!is_dir($dir . '/dre-linked-data'));
    removeDirectory($dir);
});
