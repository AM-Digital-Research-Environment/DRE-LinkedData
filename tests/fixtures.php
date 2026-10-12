<?php
declare(strict_types=1);

use DRELinkedData\Projection\IdentityLinks;
use DRELinkedData\Projection\OmekaSource;
use DRELinkedData\Projection\ResourceProjector;
use DRELinkedData\Projection\UriScheme;

const FIXTURE_BASE = 'https://data.example.org';

/**
 * A slice of AMIRA as the source queries return it: a research item with the
 * awkward values the sync really writes, a subject reconciled to LCSH (plus a
 * YouTube playlist that is NOT an identity), a person with a GND id, a project
 * item set, an uploaded scan and a YouTube media.
 *
 * Absent on purpose, because SQL filters them before PHP sees them: private
 * resources, private values. A link to a private resource is present with
 * target_visible = 0, as the values() query reports it.
 */
function amiraSource(): FakeSource
{
    $source = new FakeSource();
    $source->vocabularyRows = [
        ['prefix' => 'dcterms', 'namespace_uri' => 'http://purl.org/dc/terms/'],
        ['prefix' => 'dctype', 'namespace_uri' => 'http://purl.org/dc/dcmitype/'],
        ['prefix' => 'bibo', 'namespace_uri' => 'http://purl.org/ontology/bibo/'],
        ['prefix' => 'foaf', 'namespace_uri' => 'http://xmlns.com/foaf/0.1/'],
        ['prefix' => 'fabio', 'namespace_uri' => 'http://purl.org/spar/fabio/'],
        ['prefix' => 'marcrel', 'namespace_uri' => 'http://id.loc.gov/vocabulary/relators/'],
        ['prefix' => 'dre', 'namespace_uri' => 'http://am-digital.org/ontology/dre/'],
    ];
    $source->propertyMap = [
        1 => ['iri' => 'http://purl.org/dc/terms/title', 'term' => 'dcterms:title'],
        3 => ['iri' => 'http://purl.org/dc/terms/subject', 'term' => 'dcterms:subject'],
        7 => ['iri' => 'http://purl.org/dc/terms/created', 'term' => 'dcterms:created'],
        10 => ['iri' => 'http://purl.org/dc/terms/identifier', 'term' => 'dcterms:identifier'],
        12 => ['iri' => 'http://purl.org/dc/terms/language', 'term' => 'dcterms:language'],
        33 => ['iri' => 'http://purl.org/dc/terms/isPartOf', 'term' => 'dcterms:isPartOf'],
        106 => ['iri' => 'http://purl.org/ontology/bibo/numPages', 'term' => 'bibo:numPages'],
        346 => ['iri' => 'http://purl.org/spar/fabio/hasTranslatedTitle', 'term' => 'fabio:hasTranslatedTitle'],
        500 => ['iri' => 'http://id.loc.gov/vocabulary/relators/aut', 'term' => 'marcrel:aut'],
        660 => ['iri' => 'http://am-digital.org/ontology/dre/id', 'term' => 'dre:id'],
        661 => ['iri' => 'http://am-digital.org/ontology/dre/security', 'term' => 'dre:security'],
        663 => ['iri' => 'http://am-digital.org/ontology/dre/mongoId', 'term' => 'dre:mongoId'],
        666 => ['iri' => 'http://am-digital.org/ontology/dre/collectionUrl', 'term' => 'dre:collectionUrl'],
    ];
    $source->classMap = [
        5 => 'http://purl.org/spar/fabio/AuthorityFile',
        40 => 'http://xmlns.com/foaf/0.1/Person',
        41 => 'http://xmlns.com/foaf/0.1/Project',
    ];

    $resource = static fn (int $id, string $type, string $title, ?int $template, ?int $class, ?string $modified = '2026-06-09 05:37:09'): array => [
        'id' => $id,
        'resource_type' => $type,
        'title' => $title,
        'resource_class_id' => $class,
        'resource_template_id' => $template,
        'created' => '2026-03-26 10:11:06',
        'modified' => $modified,
    ];
    $source->resourceRows = [
        $resource(149, OmekaSource::ITEM, 'Ulli Beier', 4, 40),
        $resource(2390, OmekaSource::ITEM, 'Yoruba (African people)', 6, 5),
        $resource(6259, OmekaSource::ITEM_SET, 'Beier estate', null, null),
        $resource(7392, OmekaSource::ITEM, 'Volume 8: Yoruba Architecture and Wall Painting', 10, null),
        $resource(8001, OmekaSource::MEDIA, 'Scan', null, null, null),
        $resource(8002, OmekaSource::MEDIA, 'Interview', null, null, null),
    ];

    $value = static fn (int $resource, int $property, string $type, ?string $text, ?string $uri = null, ?int $link = null, int $visible = 0, ?string $lang = null): array => [
        'resource_id' => $resource,
        'property_id' => $property,
        'type' => $type,
        'lang' => $lang,
        'value' => $text,
        'uri' => $uri,
        'value_resource_id' => $link,
        'target_visible' => $link === null ? 0 : $visible,
    ];
    $source->valueRows = [
        $value(149, 1, 'literal', 'Ulli Beier'),
        $value(149, 10, 'uri', 'GND 124120881', 'https://d-nb.info/gnd/124120881'),

        $value(2390, 1, 'literal', 'Yoruba (African people)'),
        $value(2390, 10, 'uri', 'sh85149367', 'http://id.loc.gov/authorities/subjects/sh85149367'),
        $value(2390, 10, 'uri', null, 'https://www.youtube.com/playlist?list=PLDlHB4qY36WjCq9RIsxLzeckaPB7nQxz9'),

        $value(6259, 1, 'literal', 'Beier estate'),

        $value(7392, 1, 'literal', 'Volume 8: Yoruba Architecture and Wall Painting'),
        $value(7392, 346, 'literal', 'Volume 8: Yoruba Architecture (translated)', null, null, 0, 'en'),
        $value(7392, 1, 'literal', 'Title with an invalid language tag', null, null, 0, 'English language'),
        $value(7392, 3, 'resource:item', null, null, 2390, 1),
        $value(7392, 3, 'resource:item', null, null, 9999, 0),
        $value(7392, 500, 'resource:item', null, null, 149, 1),
        $value(7392, 7, 'numeric:timestamp', '1975'),
        $value(7392, 7, 'numeric:timestamp', '1975-03'),
        $value(7392, 7, 'numeric:timestamp', '1975-03-12'),
        $value(7392, 7, 'numeric:timestamp', '1975-03-12T10:30'),
        $value(7392, 7, 'numeric:timestamp', '1975-13'),
        $value(7392, 106, 'numeric:integer', '42'),
        $value(7392, 10, 'literal', 'KG_00001_NachlassUB'),
        $value(7392, 10, 'uri', null, 'Volume 8: Yoruba Architecture'),
        $value(7392, 666, 'uri', 'Digital Collections', 'https://collections.uni-bayreuth.de/example/beier-volume-8'),
        $value(7392, 660, 'literal', 'abg-99-0000'),
        // Marked public by mistake upstream: still never published.
        $value(7392, 663, 'literal', '65f1c0ffee0000000000abcd'),
        $value(7392, 661, 'resource:item', null, null, 4702, 1),
        $value(7392, 12, 'literal', '   '),
        $value(7392, 1, 'literal', 'Volume 8: Yoruba Architecture and Wall Painting'),
    ];
    $source->memberships = [['item_id' => 7392, 'item_set_id' => 6259]];
    $source->itemMediaRows = [['item_id' => 7392, 'id' => 8001], ['item_id' => 7392, 'id' => 8002]];
    $source->mediaRows = [
        [
            'id' => 8001, 'item_id' => 7392, 'ingester' => 'upload', 'source' => 'KG_00001_scan_internal.tif',
            'media_type' => 'image/jpeg', 'storage_id' => 'a1b2c3', 'extension' => 'jpg', 'has_original' => 1,
        ],
        [
            'id' => 8002, 'item_id' => 7392, 'ingester' => 'youtube', 'source' => 'https://www.youtube.com/watch?v=abc123',
            'media_type' => null, 'storage_id' => null, 'extension' => null, 'has_original' => 0,
        ],
    ];
    return $source;
}

function amiraProjector(?FakeSource $source = null): ResourceProjector
{
    $config = moduleConfig()['projection'];
    return new ResourceProjector(
        $source ?? amiraSource(),
        new UriScheme(FIXTURE_BASE),
        new IdentityLinks($config['identity_links']),
        $config
    );
}
