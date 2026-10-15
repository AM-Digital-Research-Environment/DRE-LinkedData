<?php
declare(strict_types=1);

use DRELinkedData\Dataset\DatasetDescription;
use DRELinkedData\Dataset\DatasetSummary;
use DRELinkedData\Dump\ChangeTracker;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Projection\IdentityLinks;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\Serializer\NTriplesSerializer;
use DRELinkedData\Settings\DatasetSettings;
use Omeka\Settings\Settings;

/** @return array<string,mixed> */
function sampleState(): array
{
    return [
        'version' => 1,
        'generated_at' => '2026-10-05T12:00:00Z',
        'triples' => 412345,
        'resources' => ['items' => 10746, 'item_sets' => 61, 'media' => 1670],
        'bytes' => 5242880,
        'sha256' => str_repeat('ab', 32),
        'vocabularies' => ['http://purl.org/dc/terms/', 'http://am-digital.org/ontology/dre/'],
        'linksets' => [
            ['authority' => 'loc', 'predicate' => 'http://www.w3.org/2004/02/skos/core#exactMatch', 'triples' => 646],
            ['authority' => 'wikidata', 'predicate' => 'http://www.w3.org/2002/07/owl#sameAs', 'triples' => 260],
        ],
        'example_resource' => 7392,
    ];
}

function description(): DatasetDescription
{
    return new DatasetDescription(
        new UriScheme(FIXTURE_BASE),
        new IdentityLinks(moduleConfig()['projection']['identity_links']),
        moduleConfig()['dataset']['keywords']
    );
}

test('editorial settings fall back to the site title and reject non-http URIs', function (): void {
    $settings = DatasetSettings::fromSettings(new Settings([
        DatasetSettings::PUBLISHER_NAME => '  Africa Multiple Cluster of Excellence ',
        DatasetSettings::PUBLISHER_URI => 'not a uri',
        DatasetSettings::LICENCE => 'https://creativecommons.org/publicdomain/zero/1.0/',
    ]), 'AMIRA');
    assertSameValue('AMIRA', $settings->title);
    assertSameValue('Africa Multiple Cluster of Excellence', $settings->publisherName);
    assertSameValue(null, $settings->publisherUri);
    assertSameValue('https://creativecommons.org/publicdomain/zero/1.0/', $settings->licence);
});

test('the VoID description carries the dump, the counts and the linksets', function (): void {
    $settings = new DatasetSettings('AMIRA', 'Research data of the Africa Multiple Cluster.', 'University of Bayreuth', 'http://www.wikidata.org/entity/Q317070', 'https://creativecommons.org/publicdomain/zero/1.0/');
    $nt = (new NTriplesSerializer())->serialize(description()->triples($settings, sampleState(), FIXTURE_BASE . '/s/amira', Format::Turtle));

    $dataset = '<' . FIXTURE_BASE . '/linked-data/void#dataset>';
    $void = 'http://rdfs.org/ns/void#';
    assertContainsText('<' . FIXTURE_BASE . '/linked-data/void.ttl> <http://xmlns.com/foaf/0.1/primaryTopic> ' . $dataset . ' .', $nt);
    assertContainsText($dataset . ' <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <' . $void . 'Dataset> .', $nt);
    assertContainsText($dataset . ' <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/ns/dcat#Dataset> .', $nt);
    assertContainsText($dataset . ' <' . $void . 'triples> "412345"^^<http://www.w3.org/2001/XMLSchema#integer> .', $nt);
    assertContainsText($dataset . ' <' . $void . 'entities> "12477"^^<http://www.w3.org/2001/XMLSchema#integer> .', $nt);
    assertContainsText($dataset . ' <' . $void . 'uriSpace> "' . FIXTURE_BASE . '/resource/" .', $nt);
    assertContainsText($dataset . ' <' . $void . 'dataDump> <' . FIXTURE_BASE . '/linked-data/dump.nt.gz> .', $nt);
    assertContainsText($dataset . ' <' . $void . 'exampleResource> <' . FIXTURE_BASE . '/resource/7392> .', $nt);
    assertContainsText($dataset . ' <' . $void . 'vocabulary> <http://am-digital.org/ontology/dre/> .', $nt);
    assertContainsText($dataset . ' <http://purl.org/dc/terms/publisher> <http://www.wikidata.org/entity/Q317070> .', $nt);
    assertContainsText('<http://www.wikidata.org/entity/Q317070> <http://xmlns.com/foaf/0.1/name> "University of Bayreuth" .', $nt);
    assertContainsText($dataset . ' <http://purl.org/dc/terms/license> <https://creativecommons.org/publicdomain/zero/1.0/> .', $nt);
    assertContainsText($dataset . ' <http://www.w3.org/ns/dcat#landingPage> <' . FIXTURE_BASE . '/s/amira> .', $nt);

    $distribution = '<' . FIXTURE_BASE . '/linked-data/void#dump>';
    assertContainsText($distribution . ' <http://www.w3.org/ns/dcat#byteSize> "5242880"^^<http://www.w3.org/2001/XMLSchema#nonNegativeInteger> .', $nt);
    assertContainsText($distribution . ' <http://www.w3.org/ns/dcat#mediaType> <http://www.iana.org/assignments/media-types/application/n-triples> .', $nt);

    $wikidata = '<' . FIXTURE_BASE . '/linked-data/void#links-wikidata-sameAs>';
    assertContainsText($dataset . ' <' . $void . 'subset> ' . $wikidata . ' .', $nt);
    $wikidataSet = '<' . FIXTURE_BASE . '/linked-data/void#dataset-wikidata>';
    assertContainsText($wikidata . ' <' . $void . 'objectsTarget> ' . $wikidataSet . ' .', $nt);
    assertContainsText($wikidataSet . ' <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <' . $void . 'Dataset> .', $nt);
    assertContainsText($wikidataSet . ' <http://purl.org/dc/terms/title> "Wikidata" .', $nt);
    assertContainsText($wikidataSet . ' <http://xmlns.com/foaf/0.1/homepage> <https://www.wikidata.org/> .', $nt);
    assertContainsText($wikidataSet . ' <' . $void . 'uriSpace> "http://www.wikidata.org/entity/" .', $nt);
    assertSameValue(3, substr_count($nt, '<http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <' . $void . 'Dataset> .'), 'ours, plus one node per target dataset');
    assertContainsText($wikidata . ' <' . $void . 'linkPredicate> <http://www.w3.org/2002/07/owl#sameAs> .', $nt);
    assertContainsText($wikidata . ' <' . $void . 'triples> "260"^^<http://www.w3.org/2001/XMLSchema#integer> .', $nt);
    assertContainsText('<' . FIXTURE_BASE . '/linked-data/void#links-loc-exactMatch> <' . $void . 'linkPredicate> <http://www.w3.org/2004/02/skos/core#exactMatch> .', $nt);
});

test('before the first dump the description claims no counts and no distribution', function (): void {
    $settings = new DatasetSettings('AMIRA', '', 'Africa Multiple', null, null);
    $nt = (new NTriplesSerializer())->serialize(description()->triples($settings, null, null, null));
    assertContainsText('<' . FIXTURE_BASE . '/linked-data/void> <http://xmlns.com/foaf/0.1/primaryTopic>', $nt);
    assertContainsText('void#uriSpace>', $nt);
    assertNotContainsText('dataDump', $nt);
    assertNotContainsText('void#triples', $nt);
    assertNotContainsText('license', $nt);
    $publisher = '<' . FIXTURE_BASE . '/linked-data/void#publisher>';
    assertContainsText($publisher . ' <http://xmlns.com/foaf/0.1/name> "Africa Multiple" .', $nt, 'a name-only publisher gets a minted agent IRI');
});

test('Creative Commons licences get their short names', function (): void {
    assertSameValue('CC0 1.0', DatasetSummary::licenceLabel('https://creativecommons.org/publicdomain/zero/1.0/'));
    assertSameValue('CC BY 4.0', DatasetSummary::licenceLabel('https://creativecommons.org/licenses/by/4.0/'));
    assertSameValue('CC BY-SA 4.0', DatasetSummary::licenceLabel('http://creativecommons.org/licenses/by-sa/4.0/legalcode'));
    assertSameValue('Public Domain Mark 1.0', DatasetSummary::licenceLabel('https://creativecommons.org/publicdomain/mark/1.0/'));
    assertSameValue('https://opendatacommons.org/licenses/odbl/1-0/', DatasetSummary::licenceLabel('https://opendatacommons.org/licenses/odbl/1-0/'));
});

test('the summary gives templates everything they show', function (): void {
    $dir = tempDirectory('summary');
    try {
        $store = new DumpStore($dir);
        $summary = new DatasetSummary($store, new UriScheme(FIXTURE_BASE . '/'), new IdentityLinks(moduleConfig()['projection']['identity_links']), static fn (): PrefixMap => new PrefixMap());
        $settings = new DatasetSettings('AMIRA', 'About.', '', null, null);

        $empty = $summary->build($settings);
        assertSameValue(false, $empty['available']);
        assertSameValue(null, $empty['example']);
        assertSameValue(FIXTURE_BASE . '/resource/{id}', $empty['uriPattern']);
        assertSameValue(4, count($empty['void']));
        assertSameValue('RDF/XML', $empty['void'][3]['label']);
        assertSameValue(null, $empty['changes']);

        file_put_contents($dir . '/dump.nt.gz', gzencode("<a:b> <a:c> <a:d> .\n"));
        file_put_contents($dir . '/state.json', json_encode(sampleState()));
        $full = $summary->build($settings);
        assertSameValue(true, $full['available']);
        assertSameValue(['items' => 10746, 'itemSets' => 61, 'media' => 1670], $full['resources']);
        assertSameValue(7392, $full['example']['id']);
        assertSameValue(FIXTURE_BASE . '/resource/7392.jsonld', $full['example']['documents'][2]['url']);
        assertSameValue(['label' => 'Library of Congress', 'predicate' => 'skos:exactMatch', 'triples' => 646], $full['linksets'][0]);
        assertSameValue(['prefix' => 'dcterms', 'namespace' => 'http://purl.org/dc/terms/'], $full['vocabularies'][0]);
        assertSameValue(['prefix' => '', 'namespace' => 'http://am-digital.org/ontology/dre/'], $full['vocabularies'][1]);
        assertSameValue(8001, $summary->build($settings, 8001)['example']['id'], 'an editor-chosen example wins');

        $checked = new DatasetSummary($store, new UriScheme(FIXTURE_BASE), new IdentityLinks(moduleConfig()['projection']['identity_links']), static fn (): PrefixMap => new PrefixMap(), static fn (int $id): bool => amiraSource()->isVisible($id));
        assertSameValue(8001, $checked->build($settings, 8001)['example']['id'], 'a public example is kept');
        assertSameValue(7392, $checked->build($settings, 9999)['example']['id'], 'a private or missing example falls back to the dump\'s');
    } finally {
        removeDirectory($dir);
    }
});

test('the DCAT description has a contact point, issue date, keywords, checksum and English labels', function (): void {
    $settings = new DatasetSettings('AMIRA', 'Research data.', 'Africa Multiple Cluster of Excellence', null, 'https://creativecommons.org/publicdomain/zero/1.0/', 'dre@example.org');
    $state = sampleState() + ['first_generated_at' => '2026-10-01T08:00:00Z'];
    $nt = (new NTriplesSerializer())->serialize(description()->triples($settings, $state, null, null));
    $dataset = '<' . FIXTURE_BASE . '/linked-data/void#dataset>';
    $contact = '<' . FIXTURE_BASE . '/linked-data/void#contact>';
    $checksum = '<' . FIXTURE_BASE . '/linked-data/void#dump-sha256>';
    $xsd = 'http://www.w3.org/2001/XMLSchema#';

    assertContainsText($dataset . ' <http://purl.org/dc/terms/title> "AMIRA"@en .', $nt);
    assertContainsText($dataset . ' <http://purl.org/dc/terms/description> "Research data."@en .', $nt);
    assertContainsText($dataset . ' <http://www.w3.org/ns/dcat#keyword> "research data"@en .', $nt);
    assertContainsText($dataset . ' <http://www.w3.org/ns/dcat#contactPoint> ' . $contact . ' .', $nt);
    assertContainsText($contact . ' <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/2006/vcard/ns#Kind> .', $nt);
    assertContainsText($contact . ' <http://www.w3.org/2006/vcard/ns#hasEmail> <mailto:dre@example.org> .', $nt);
    assertContainsText($contact . ' <http://www.w3.org/2006/vcard/ns#fn> "Africa Multiple Cluster of Excellence" .', $nt);
    assertContainsText($dataset . ' <http://purl.org/dc/terms/issued> "2026-10-01T08:00:00Z"^^<' . $xsd . 'dateTime> .', $nt);
    assertContainsText('<' . FIXTURE_BASE . '/linked-data/void#dump> <http://spdx.org/rdf/terms#checksum> ' . $checksum . ' .', $nt);
    assertContainsText($checksum . ' <http://spdx.org/rdf/terms#algorithm> <http://spdx.org/rdf/terms#checksumAlgorithm_sha256> .', $nt);
    assertContainsText($checksum . ' <http://spdx.org/rdf/terms#checksumValue> "' . str_repeat('ab', 32) . '"^^<' . $xsd . 'hexBinary> .', $nt);
    assertContainsText('<http://purl.org/dc/terms/title> "N-Triples dump (gzip)"@en .', $nt);
    assertContainsText($dataset . ' <http://rdfs.org/ns/void#feature> <http://www.w3.org/ns/formats/RDF_XML> .', $nt);

    $none = (new NTriplesSerializer())->serialize(description()->triples(new DatasetSettings('AMIRA', '', '', null, null), sampleState(), null, null));
    assertNotContainsText('contactPoint', $none, 'no address, no contact point');
    assertNotContainsText('vcard', $none);
});

test('a contact email must be an address', function (): void {
    assertSameValue('dre@example.org', DatasetSettings::fromSettings(new Settings([DatasetSettings::CONTACT_EMAIL => ' dre@example.org ']), 'x')->contactEmail);
    assertSameValue(null, DatasetSettings::fromSettings(new Settings([DatasetSettings::CONTACT_EMAIL => 'not an address']), 'x')->contactEmail);
    assertSameValue(null, DatasetSettings::fromSettings(new Settings(), 'x')->contactEmail);
    foreach (array_merge(DatasetSettings::KEYS, DatasetSettings::INTERNAL_KEYS) as $key) {
        assertTrue(str_starts_with($key, 'dre_ld_'), $key);
    }
    assertTrue(!in_array(DatasetSettings::CHANGES, DatasetSettings::KEYS, true), 'the form must never overwrite the change record');
});

test('the landing page is the Linked open data page, the homepage only without one', function (): void {
    $settings = new DatasetSettings('AMIRA', 'About.', 'Africa Multiple', null, null);
    $page = FIXTURE_BASE . '/s/amira/page/linked-open-data';
    $dataset = '<' . FIXTURE_BASE . '/linked-data/void#dataset>';
    $with = (new NTriplesSerializer())->serialize(description()->triples($settings, sampleState(), FIXTURE_BASE . '/s/amira', Format::Turtle, $page));
    assertContainsText($dataset . ' <http://www.w3.org/ns/dcat#landingPage> <' . $page . '> .', $with);
    assertContainsText($dataset . ' <http://xmlns.com/foaf/0.1/homepage> <' . FIXTURE_BASE . '/s/amira> .', $with);
    if (str_contains($with, '<http://www.w3.org/ns/dcat#landingPage> <' . FIXTURE_BASE . '/s/amira>')) {
        throw new RuntimeException('The homepage must not also be a landing page when the block page exists.');
    }
});

test('dataset() follows the helper contract', function (): void {
    $dir = tempDirectory('dataset');
    try {
        $store = new DumpStore($dir);
        $summary = new DatasetSummary($store, new UriScheme(FIXTURE_BASE), new IdentityLinks(moduleConfig()['projection']['identity_links']), static fn (): PrefixMap => new PrefixMap());
        $settings = new DatasetSettings('AMIRA', 'About.', 'Africa Multiple', 'http://www.wikidata.org/entity/Q141717295', 'https://creativecommons.org/publicdomain/zero/1.0/');

        $before = $summary->dataset($settings);
        assertSameValue(['uri', 'void', 'landingPage', 'title', 'description', 'licence', 'publisher', 'modified', 'triples', 'entities', 'distributions'], array_keys($before));
        assertSameValue(null, $before['landingPage']);
        assertSameValue(FIXTURE_BASE . '/s/amira/page/linked-open-data', $summary->dataset($settings, FIXTURE_BASE . '/s/amira/page/linked-open-data')['landingPage']);
        assertSameValue(FIXTURE_BASE . '/linked-data/void#dataset', $before['uri']);
        assertSameValue(FIXTURE_BASE . '/linked-data/void', $before['void']);
        assertSameValue(['name' => 'Africa Multiple', 'uri' => 'http://www.wikidata.org/entity/Q141717295'], $before['publisher']);
        assertSameValue([null, null, null, []], [$before['modified'], $before['triples'], $before['entities'], $before['distributions']]);

        file_put_contents($dir . '/dump.nt.gz', gzencode("<a:b> <a:c> <a:d> .\n"));
        file_put_contents($dir . '/state.json', json_encode(sampleState()));
        $after = $summary->dataset($settings);
        assertSameValue('2026-10-05T12:00:00Z', $after['modified']);
        assertSameValue(412345, $after['triples']);
        assertSameValue(12477, $after['entities']);
        assertSameValue([[
            'label' => 'N-Triples dump (gzip)',
            'url' => FIXTURE_BASE . '/linked-data/dump.nt.gz',
            'type' => 'application/n-triples',
            'encoding' => 'application/gzip',
            'bytes' => 5242880,
        ]], $after['distributions']);
        assertSameValue(null, $summary->dataset(new DatasetSettings('AMIRA', '', '', null, null))['licence']);
    } finally {
        removeDirectory($dir);
    }
});

test('the summary says when the dump is out of date', function (): void {
    $dir = tempDirectory('summary-stale');
    try {
        $store = new DumpStore($dir);
        file_put_contents($dir . '/dump.nt.gz', gzencode("<a:b> <a:c> <a:d> .\n"));
        file_put_contents($dir . '/state.json', json_encode(['started_at' => '2026-10-05T11:59:00Z'] + sampleState()));
        $settings = new Settings([DatasetSettings::CHANGES => ['since' => '2026-10-06T08:00:00Z', 'count' => 7, 'last' => '2026-10-06T09:00:00Z']]);
        $summary = new DatasetSummary($store, new UriScheme(FIXTURE_BASE), new IdentityLinks(moduleConfig()['projection']['identity_links']), static fn (): PrefixMap => new PrefixMap(), null, new ChangeTracker($settings, $store));
        $built = $summary->build(new DatasetSettings('AMIRA', '', '', null, null));
        assertSameValue(['since' => '2026-10-06T08:00:00Z', 'count' => 7], $built['changes']);

        $settings->set(DatasetSettings::CHANGES, ['since' => '2026-10-04T08:00:00Z', 'count' => 3, 'last' => '2026-10-04T09:00:00Z']);
        assertSameValue(null, $summary->build(new DatasetSettings('AMIRA', '', '', null, null))['changes'], 'changes before the dump started are in it');
    } finally {
        removeDirectory($dir);
    }
});
