<?php
declare(strict_types=1);

use DRELinkedData\Dump\ChangeTracker;
use DRELinkedData\Dump\DumpGenerator;
use DRELinkedData\Dump\DumpState;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Dump\GraphMap;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\Serializer\NTriplesSerializer;
use DRELinkedData\Settings\DatasetSettings;
use Omeka\Settings\Settings;

function dumpGenerator(DumpStore $store, MemoryLogger $logger, int $batchSize = 2, ?ChangeTracker $changes = null): DumpGenerator
{
    $source = amiraSource();
    return new DumpGenerator(
        amiraProjector($source),
        $source,
        $store,
        PrefixMap::withVocabularies($source->vocabularyRows),
        $logger,
        $batchSize,
        $changes,
        new GraphMap(moduleConfig()['dump']['graphs']),
        fixtureUris()
    );
}

/** Every fixture resource id, in order. */
function fixtureIds(): array
{
    $ids = array_map(static fn (array $row): int => (int) $row['id'], amiraSource()->resourceRows);
    sort($ids);
    return $ids;
}

test('the dump is the concatenation of every resource document, gzipped', function (): void {
    $dir = tempDirectory('dump');
    try {
        $store = new DumpStore($dir);
        $logger = new MemoryLogger();
        $state = dumpGenerator($store, $logger)->generate();
        assertTrue($state !== null, 'generation should succeed');

        $expected = '';
        foreach (amiraProjector()->describe(fixtureIds()) as $description) {
            $expected .= (new NTriplesSerializer())->serialize($description->triples);
        }
        $written = gzdecode((string) file_get_contents($store->dumpPath()));
        assertSameValue($expected, $written, 'batching must not change the output');

        assertSameValue(substr_count($expected, "\n"), $state->triples);
        assertSameValue(['items' => 5, 'item_sets' => 2, 'media' => 2], $state->resources());
        assertSameValue(filesize($store->dumpPath()), $state->bytes);
        assertSameValue(hash_file('sha256', $store->dumpPath()), $state->sha256);
        assertSameValue(7392, $state->exampleResource, 'the richest item is the example');
        assertSameValue([
            ['authority' => 'doi', 'predicate' => 'https://schema.org/sameAs', 'triples' => 2],
            ['authority' => 'gnd', 'predicate' => 'http://www.w3.org/2002/07/owl#sameAs', 'triples' => 1],
            ['authority' => 'loc', 'predicate' => 'http://www.w3.org/2004/02/skos/core#exactMatch', 'triples' => 1],
        ], $state->linksets);
        assertTrue(in_array('http://am-digital.org/ontology/dre/', $state->vocabularies, true));
        assertTrue(in_array('https://schema.org/', $state->vocabularies, true));
        assertTrue(in_array('http://www.w3.org/2004/02/skos/core#', $state->vocabularies, true));
        assertTrue(in_array('http://purl.org/ontology/bibo/', $state->vocabularies, true));
        assertTrue(!in_array('http://xmlns.com/foaf/0.1/', $state->vocabularies, true) || str_contains($expected, 'xmlns.com/foaf'), 'only vocabularies in use are listed');
        assertSameValue($state->toArray(), $store->state()?->toArray(), 'the state on disk is what generate() returned');
        assertSameValue([], glob($dir . '/.*.part') ?: [], 'no part files are left behind');
        assertTrue(str_contains($logger->records['info'][0] ?? '', 'wrote ' . $state->triples . ' triples for 9 resources'));
        assertNotContainsText('Secret working title', $written, 'a private title never reaches the dump');
        assertContainsText('<http://xmlns.com/foaf/0.1/isPrimaryTopicOf> <' . FIXTURE_BASE . '/s/amira/item/7392> .', $written);
        assertContainsText('<' . FIXTURE_BASE . '/s/amira/item/7392> <http://purl.org/dc/terms/modified> ', $written, 'per-resource modification dates');
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
        assertSameValue($first?->toArray(), $store->state()?->toArray());
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

test('the state records when the run started and when the dataset was first issued', function (): void {
    $dir = tempDirectory('dump-issued');
    try {
        $store = new DumpStore($dir);
        $first = dumpGenerator($store, new MemoryLogger())->generate();
        assertTrue(preg_match('~^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$~', (string) $first?->startedAt) === 1);
        assertSameValue($first?->generatedAt, $first?->firstGeneratedAt, 'the first run issues the dataset');

        // Pretend the first run was long ago; a new run keeps that date.
        file_put_contents($dir . '/state.json', json_encode(['first_generated_at' => '2020-01-01T00:00:00Z'] + (array) $first?->toArray()));
        $second = dumpGenerator($store, new MemoryLogger())->generate();
        assertSameValue('2020-01-01T00:00:00Z', $second?->firstGeneratedAt);
        assertSameValue([], glob($dir . '/.*.part') ?: [], 'state and dump parts are both renamed into place');
    } finally {
        removeDirectory($dir);
    }
});

test('the state reads leniently and round-trips', function (): void {
    $state = DumpState::fromArray([
        'generated_at' => '2026-10-05T12:00:00Z',
        'triples' => '12',
        'resources' => ['items' => 3, 'item_sets' => 'x'],
        'bytes' => 'not a number',
        'vocabularies' => ['https://schema.org/', 42],
        'linksets' => ['junk', ['authority' => 'gnd', 'predicate' => 'http://www.w3.org/2002/07/owl#sameAs', 'triples' => 5]],
        'example_resource' => 0,
    ]);
    assertSameValue(12, $state->triples);
    assertSameValue(['items' => 3, 'item_sets' => 0, 'media' => 0], $state->resources());
    assertSameValue(3, $state->entities());
    assertSameValue(null, $state->bytes, 'a malformed size is unknown, not zero');
    assertSameValue(['https://schema.org/'], $state->vocabularies);
    assertSameValue([['authority' => 'gnd', 'predicate' => 'http://www.w3.org/2002/07/owl#sameAs', 'triples' => 5]], $state->linksets);
    assertSameValue(null, $state->exampleResource);
    assertSameValue('2026-10-05T12:00:00Z', $state->since(), 'without a start time, the end time');
    assertSameValue($state->toArray(), DumpState::fromArray($state->toArray())->toArray());
    assertSameValue(DumpState::VERSION, $state->toArray()['version']);
});

test('changes are counted once per process and settled by the next dump', function (): void {
    $dir = tempDirectory('dump-changes');
    try {
        $store = new DumpStore($dir);
        $settings = new Settings();
        $now = '2026-10-01T10:00:00Z';
        $tracker = new ChangeTracker($settings, $store, static function () use (&$now): string {
            return $now;
        });

        $tracker->record();
        $tracker->record(2);
        assertSameValue(null, $settings->get(DatasetSettings::CHANGES), 'nothing is written before the flush');
        $tracker->flush();
        assertSameValue(['since' => $now, 'count' => 3, 'last' => $now], $settings->get(DatasetSettings::CHANGES));
        assertSameValue(['since' => $now, 'count' => 3], $tracker->pending(null), 'no dump at all: everything is pending');

        // A dump that starts after the changes contains them.
        $state = new DumpState('2026-10-01T11:05:00Z', '2026-10-01T11:00:00Z');
        assertSameValue(null, $tracker->pending($state));
        $tracker->settle('2026-10-01T11:00:00Z');
        assertSameValue(null, $settings->get(DatasetSettings::CHANGES), 'settled');

        // A change while a dump runs is still pending afterwards.
        $now = '2026-10-01T11:02:00Z';
        $tracker->record();
        $tracker->flush();
        $tracker->settle('2026-10-01T11:00:00Z');
        assertSameValue(['since' => $now, 'count' => 1], $tracker->pending($state));

        // A stale record left by a run that did not settle starts afresh.
        $settings->set(DatasetSettings::CHANGES, ['since' => '2026-09-01T00:00:00Z', 'count' => 40, 'last' => '2026-09-02T00:00:00Z']);
        file_put_contents($dir . '/dump.nt.gz', gzencode(''));
        file_put_contents($dir . '/state.json', json_encode($state->toArray()));
        $now = '2026-10-02T09:00:00Z';
        $tracker->record();
        $tracker->flush();
        assertSameValue(['since' => $now, 'count' => 1, 'last' => $now], $settings->get(DatasetSettings::CHANGES));
    } finally {
        removeDirectory($dir);
    }
});

test('a dump run settles the changes it contains', function (): void {
    $dir = tempDirectory('dump-settle');
    try {
        $store = new DumpStore($dir);
        $settings = new Settings([DatasetSettings::CHANGES => ['since' => '2020-01-01T00:00:00Z', 'count' => 12, 'last' => '2020-01-02T00:00:00Z']]);
        $tracker = new ChangeTracker($settings, $store);
        $state = dumpGenerator($store, new MemoryLogger(), 2, $tracker)->generate();
        assertTrue($state !== null);
        assertSameValue(null, $settings->get(DatasetSettings::CHANGES));
        assertSameValue(null, $tracker->pending($state));
    } finally {
        removeDirectory($dir);
    }
});

test('the N-Quads dump holds the same statements, one named graph per kind of record', function (): void {
    $dir = tempDirectory('dump-nquads');
    try {
        $store = new DumpStore($dir);
        $state = dumpGenerator($store, new MemoryLogger())->generate();
        assertTrue($state !== null, 'generation should succeed');
        assertTrue(is_file($store->nquadsPath()), 'the N-Quads dump is published beside the N-Triples one');
        assertSameValue(filesize($store->nquadsPath()), $state->nquadsBytes);
        assertSameValue(hash_file('sha256', $store->nquadsPath()), $state->nquadsSha256);

        $triples = explode("\n", trim((string) gzdecode((string) file_get_contents($store->dumpPath()))));
        $quads = explode("\n", trim((string) gzdecode((string) file_get_contents($store->nquadsPath()))));
        assertSameValue(count($triples), count($quads), 'one quad per triple');
        $graphOf = [];
        foreach ($quads as $i => $quad) {
            assertTrue((bool) preg_match('~^(.*) <(' . preg_quote(FIXTURE_BASE, '~') . '/linked-data/void#graph-[a-z-]+)> \.$~', $quad, $m), "quad {$i} names a graph: {$quad}");
            assertSameValue($triples[$i], $m[1] . ' .', 'the quad is the triple plus its graph');
            if (preg_match('~^<' . preg_quote(FIXTURE_BASE, '~') . '/resource/(\d+)> ~', $quad, $s)) {
                $graphOf[(int) $s[1]][$m[2]] = true;
            }
        }
        $graph = static fn (string $key): string => FIXTURE_BASE . '/linked-data/void#graph-' . $key;
        foreach ($graphOf as $id => $graphs) {
            assertSameValue(1, count($graphs), "resource {$id} sits in exactly one graph");
        }
        assertSameValue([$graph('persons')], array_keys($graphOf[149]));
        assertSameValue([$graph('concepts')], array_keys($graphOf[2390]));
        assertSameValue([$graph('research-items')], array_keys($graphOf[7392]));
        assertSameValue([$graph('item-sets')], array_keys($graphOf[6259]));
        assertSameValue([$graph('media')], array_keys($graphOf[8001]));
        assertSameValue([$graph('other')], array_keys($graphOf[7401]), 'a template no graph names goes to the default graph');

        $counts = array_column($state->graphs, null, 'graph');
        assertSameValue(['research-items', 'persons', 'concepts', 'item-sets', 'media', 'other'], array_keys($counts), 'in configured order, empty graphs left out');
        assertSameValue(['graph' => 'persons', 'triples' => count(array_filter($quads, static fn (string $q): bool => str_ends_with($q, '#graph-persons> .'))), 'entities' => 1], $counts['persons']);
        assertSameValue($state->triples, array_sum(array_column($state->graphs, 'triples')));
        assertSameValue($state->entities(), array_sum(array_column($state->graphs, 'entities')));
        assertSameValue([], glob($dir . '/.*.part') ?: [], 'no part files are left behind');
    } finally {
        removeDirectory($dir);
    }
});

test('publications go to their graph by item set, whatever their template', function (): void {
    $map = new GraphMap(moduleConfig()['dump']['graphs']);
    $item = static fn (?int $template, array $sets): DRELinkedData\Projection\ResourceDescription => new DRELinkedData\Projection\ResourceDescription(1, 'item', null, [], [], $template, $sets);
    assertSameValue('publications', $map->graphFor($item(31, [29918])));
    assertSameValue('publications', $map->graphFor($item(null, [5, 29918])));
    assertSameValue('research-items', $map->graphFor($item(10, [29918])), 'the template wins over the item set');
    assertSameValue('other', $map->graphFor($item(99, [5])));
    assertSameValue('Subjects and other concepts', $map->titles()['concepts']);
});
