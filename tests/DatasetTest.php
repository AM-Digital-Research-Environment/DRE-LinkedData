<?php
declare(strict_types=1);

use DRELinkedData\Dataset\DatasetDescription;
use DRELinkedData\Dataset\DatasetSummary;
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
        new IdentityLinks(moduleConfig()['projection']['identity_links'])
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
    assertContainsText($wikidata . ' <' . $void . 'objectsTarget> <https://www.wikidata.org/> .', $nt);
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
        assertSameValue(3, count($empty['void']));

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
    } finally {
        removeDirectory($dir);
    }
});
