<?php
declare(strict_types=1);

use DRELinkedData\Projection\IdentityLinks;
use DRELinkedData\Projection\ResourceDescription;
use DRELinkedData\Projection\ResourceProjector;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\Serializer\NTriplesSerializer;

/** N-Triples of one projected resource, for line-level assertions. */
function projectedLines(int $id, ?FakeSource $source = null): string
{
    $description = amiraProjector($source)->describeOne($id);
    assertTrue($description !== null, "resource {$id} should be described");
    return (new NTriplesSerializer())->serialize($description->triples);
}

const R = '<' . FIXTURE_BASE . '/resource/';

test('a research item keeps its values and drops Omeka bookkeeping', function (): void {
    $nt = projectedLines(7392);
    assertContainsText(R . '7392> <http://www.w3.org/2000/01/rdf-schema#label> "Volume 8: Yoruba Architecture and Wall Painting" .', $nt);
    assertContainsText(R . '7392> <http://purl.org/dc/terms/title> "Volume 8: Yoruba Architecture and Wall Painting" .', $nt);
    assertContainsText('<http://purl.org/spar/fabio/hasTranslatedTitle> "Volume 8: Yoruba Architecture (translated)"@en .', $nt);
    assertContainsText('<http://am-digital.org/ontology/dre/id> "abg-99-0000" .', $nt);
    assertContainsText('<http://am-digital.org/ontology/dre/collectionUrl> <https://collections.uni-bayreuth.de/example/beier-volume-8> .', $nt);
    assertNotContainsText('omeka.org/s/vocabs/o#', $nt, 'no Omeka internals');
    assertNotContainsText('/api/items/', $nt, 'links use resource URIs, not API URLs');
});

test('an item with no class gets the fallback class; classed items and sets do not', function (): void {
    $type = '<http://www.w3.org/1999/02/22-rdf-syntax-ns#type>';
    assertContainsText(R . '7392> ' . $type . ' <https://schema.org/CreativeWork> .', projectedLines(7392));
    assertNotContainsText('CreativeWork', projectedLines(149));
    assertNotContainsText('CreativeWork', projectedLines(6259));
});

test('an authority file is published as a SKOS concept in the schemes of its item sets', function (): void {
    $nt = projectedLines(2390);
    assertContainsText(R . '2390> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://www.w3.org/2004/02/skos/core#Concept> .', $nt);
    assertNotContainsText('AuthorityFile', $nt, 'a concept is not a document');
    assertContainsText(R . '2390> <http://www.w3.org/2004/02/skos/core#prefLabel> "Yoruba (African people)" .', $nt);
    assertContainsText(R . '2390> <http://www.w3.org/2004/02/skos/core#inScheme> ' . R . '6260> .', $nt);
    assertNotContainsText('prefLabel', projectedLines(149), 'only concepts get SKOS labels');
});

test('full texts and transcripts are not published', function (): void {
    $nt = projectedLines(7392);
    assertNotContainsText('FULL TEXT', $nt);
    assertNotContainsText('bibo/content', $nt);
});

test('an author list is a real rdf:List in value order, with flat creators', function (): void {
    $nt = projectedLines(7392);
    $rdf = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    $node = static fn (int $n): string => '<' . FIXTURE_BASE . '/resource/7392#authorList-' . $n . '>';
    assertContainsText(R . '7392> <http://purl.org/ontology/bibo/authorList> ' . $node(1) . ' .', $nt);
    assertContainsText($node(1) . ' <' . $rdf . 'first> ' . R . '149> .', $nt);
    assertContainsText($node(1) . ' <' . $rdf . 'rest> ' . $node(2) . ' .', $nt);
    assertContainsText($node(2) . ' <' . $rdf . 'first> "Doe, Jane" .', $nt);
    assertContainsText($node(2) . ' <' . $rdf . 'rest> <' . $rdf . 'nil> .', $nt);
    assertSameValue(1, substr_count($nt, '<http://purl.org/ontology/bibo/authorList>'), 'the list has one head');
    assertContainsText(R . '7392> <http://purl.org/dc/terms/creator> ' . R . '149> .', $nt);
    assertNotContainsText('<http://purl.org/dc/terms/creator> "Doe, Jane"', $nt, 'dcterms:creator wants an agent');
    assertNotContainsText('authorList-3', $nt, 'a hidden author takes no place in the list');
});

test('a DOI is published bare and linked by its resolver IRI', function (): void {
    $description = amiraProjector()->describeOne(7392);
    $nt = projectedLines(7392);
    assertContainsText(R . '7392> <http://purl.org/ontology/bibo/doi> "10.1017/S0021853721000372" .', $nt);
    assertContainsText(R . '7392> <https://schema.org/sameAs> <https://doi.org/10.1017/S0021853721000372> .', $nt);
    assertContainsText('<http://purl.org/ontology/bibo/doi> "10.1086/ahr/110.2.<123>" .', $nt, 'percent-encoding is undone in the literal');
    assertContainsText('<https://schema.org/sameAs> <https://doi.org/10.1086/ahr/110.2.%3C123%3E> .', $nt, 'and restored in the IRI');
    assertNotContainsText('<http://purl.org/ontology/bibo/doi> <', $nt, 'bibo:doi never holds an IRI');
    assertSameValue(2, count(array_filter($description->identityLinks, static fn (array $link): bool => $link['authority'] === 'doi')));
});

test('coordinates are decimals when they are numbers', function (): void {
    $nt = projectedLines(149);
    assertContainsText('<http://www.w3.org/2003/01/geo/wgs84_pos#lat> "49.92885"^^<http://www.w3.org/2001/XMLSchema#decimal> .', $nt);
    assertContainsText('<http://www.w3.org/2003/01/geo/wgs84_pos#lat> "about 50 degrees north" .', $nt);
});

test('duplicate values collapse to one triple', function (): void {
    $nt = projectedLines(7392);
    assertSameValue(1, substr_count($nt, '<http://purl.org/dc/terms/title> "Volume 8: Yoruba Architecture and Wall Painting"'));
});

test('links to visible resources use resource URIs; links to hidden ones vanish', function (): void {
    $nt = projectedLines(7392);
    assertContainsText(R . '7392> <http://purl.org/dc/terms/subject> ' . R . '2390> .', $nt);
    assertContainsText(R . '7392> <http://id.loc.gov/vocabulary/relators/aut> ' . R . '149> .', $nt);
    assertNotContainsText('9999', $nt, 'a private target must not leak, not even as an id');
});

test('excluded terms are never published, even when marked public', function (): void {
    $nt = projectedLines(7392);
    assertNotContainsText('mongoId', $nt);
    assertNotContainsText('65f1c0ffee', $nt);
    assertNotContainsText('dre/security', $nt);
    assertNotContainsText('4702', $nt);
});

test('numeric timestamps keep their precision as XSD types', function (): void {
    $nt = projectedLines(7392);
    $xsd = 'http://www.w3.org/2001/XMLSchema#';
    assertContainsText('"1975"^^<' . $xsd . 'gYear>', $nt);
    assertContainsText('"1975-03"^^<' . $xsd . 'gYearMonth>', $nt);
    assertContainsText('"1975-03-12"^^<' . $xsd . 'date>', $nt);
    assertContainsText('"1975-03-12T10:30:00"^^<' . $xsd . 'dateTime>', $nt);
    assertContainsText('<http://purl.org/dc/terms/created> "1975-13" .', $nt, 'an impossible month stays a plain literal');
    assertContainsText('"42"^^<' . $xsd . 'integer>', $nt);
});

test('language tags are validated and blank literals skipped', function (): void {
    $nt = projectedLines(7392);
    assertContainsText('"Title with an invalid language tag" .', $nt);
    assertNotContainsText('English language', $nt);
    assertNotContainsText('dc/terms/language', $nt);
});

test('a uri value that is not an IRI is published as a literal', function (): void {
    $nt = projectedLines(7392);
    assertContainsText('<http://purl.org/dc/terms/identifier> "Volume 8: Yoruba Architecture" .', $nt);
    assertContainsText('<http://purl.org/dc/terms/identifier> "KG_00001_NachlassUB" .', $nt);
});

test('item-set membership and media use schema.org, apart from dcterms:isPartOf', function (): void {
    $nt = projectedLines(7392);
    assertContainsText(R . '7392> <https://schema.org/isPartOf> ' . R . '6259> .', $nt);
    assertContainsText(R . '7392> <https://schema.org/associatedMedia> ' . R . '8001> .', $nt);
    assertContainsText(R . '7392> <https://schema.org/associatedMedia> ' . R . '8002> .', $nt);
    assertNotContainsText('<http://purl.org/dc/terms/isPartOf> ' . R . '6259>', $nt);
});

test('an item set is typed as a collection', function (): void {
    $nt = projectedLines(6259);
    assertContainsText(R . '6259> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <http://purl.org/dc/dcmitype/Collection> .', $nt);
    assertContainsText('"Beier estate"', $nt);
});

test('an uploaded file is published by URL, never by its original filename', function (): void {
    $nt = projectedLines(8001);
    assertContainsText(R . '8001> <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> <https://schema.org/MediaObject> .', $nt);
    assertContainsText(R . '8001> <https://schema.org/encodesCreativeWork> ' . R . '7392> .', $nt);
    assertContainsText('<https://schema.org/contentUrl> <' . FIXTURE_BASE . '/files/original/a1b2c3.jpg> .', $nt);
    assertContainsText('<https://schema.org/encodingFormat> "image/jpeg" .', $nt);
    assertNotContainsText('KG_00001_scan_internal', $nt);
    assertNotContainsText('schema.org/url>', $nt);
});

test('a web-ingested media points at its source', function (): void {
    $nt = projectedLines(8002);
    assertContainsText('<https://schema.org/url> <https://www.youtube.com/watch?v=abc123> .', $nt);
    assertNotContainsText('contentUrl', $nt);
});

test('authority identifiers become identity links, by template', function (): void {
    $person = amiraProjector()->describeOne(149);
    assertContainsText(R . '149> <http://www.w3.org/2002/07/owl#sameAs> <https://d-nb.info/gnd/124120881> .', projectedLines(149));
    assertSameValue([['authority' => 'gnd', 'predicate' => 'http://www.w3.org/2002/07/owl#sameAs']], $person->identityLinks);

    $subject = projectedLines(2390);
    assertContainsText(R . '2390> <http://www.w3.org/2004/02/skos/core#exactMatch> <http://id.loc.gov/authorities/subjects/sh85149367> .', $subject);
    assertNotContainsText('sameAs', $subject, 'an authority concept is matched, not identical');
    assertContainsText('<http://purl.org/dc/terms/identifier> "https://www.youtube.com/playlist?list=PLDlHB4qY36WjCq9RIsxLzeckaPB7nQxz9" .', $subject);
    assertContainsText(R . '2390> <http://www.w3.org/2000/01/rdf-schema#seeAlso> <https://www.youtube.com/playlist?list=PLDlHB4qY36WjCq9RIsxLzeckaPB7nQxz9> .', $subject, 'a URI that is no identity stays a link');
    assertSameValue(1, substr_count($subject, 'exactMatch'), 'the YouTube playlist is an identifier, not an identity');
});

test('wikidata page URLs are linked by their entity IRI', function (): void {
    $source = amiraSource();
    $source->valueRows[] = [
        'resource_id' => 149, 'property_id' => 10, 'type' => 'uri', 'lang' => null, 'value' => 'Q707283',
        'uri' => 'https://www.wikidata.org/wiki/Q707283', 'value_resource_id' => null, 'target_visible' => 0,
    ];
    assertContainsText('<http://www.w3.org/2002/07/owl#sameAs> <http://www.wikidata.org/entity/Q707283> .', projectedLines(149, $source));
});

test('a batch asks for its own properties and classes, once', function (): void {
    $source = amiraSource();
    amiraProjector($source)->describe([149, 6259, 7392]);

    assertSameValue(1, count($source->propertyRequests), 'one lookup per batch, not per resource');
    $requested = $source->propertyRequests[0];
    sort($requested);
    assertSameValue([1, 3, 7, 10, 12, 106, 110, 111, 112, 200, 346, 500, 660, 661, 663, 666], $requested, 'exactly the properties the batch uses');
    assertSameValue([[40]], $source->classRequests, 'only the person has a class');

    amiraProjector($source)->describe([]);
    assertSameValue(1, count($source->propertyRequests), 'an empty batch looks nothing up');
});

test('describe() returns visible resources only, keyed by id', function (): void {
    $descriptions = amiraProjector()->describe([7392, 424242, 6259]);
    assertSameValue([6259, 7392], array_keys($descriptions));
    assertSameValue(ResourceDescription::ITEM_SET, $descriptions[6259]->kind);
    assertSameValue('item-set', $descriptions[6259]->siteController());
    assertSameValue(null, amiraProjector()->describeOne(424242));
});

test('a resource document states what it describes, when, and under what licence', function (): void {
    $projector = amiraProjector();
    $triples = $projector->document($projector->describeOne(7392), Format::Turtle, 'https://creativecommons.org/publicdomain/zero/1.0/');
    $nt = (new NTriplesSerializer())->serialize($triples);
    $document = '<' . FIXTURE_BASE . '/resource/7392.ttl>';
    assertContainsText($document . ' <http://xmlns.com/foaf/0.1/primaryTopic> ' . R . '7392> .', $nt);
    assertContainsText($document . ' <http://purl.org/dc/terms/modified> "2026-06-09T05:37:09Z"^^<http://www.w3.org/2001/XMLSchema#dateTime> .', $nt);
    assertContainsText($document . ' <http://purl.org/dc/terms/license> <https://creativecommons.org/publicdomain/zero/1.0/> .', $nt);
    assertContainsText($document . ' <http://rdfs.org/ns/void#inDataset> <' . FIXTURE_BASE . '/linked-data/void#dataset> .', $nt);

    $unlicensed = (new NTriplesSerializer())->serialize($projector->document($projector->describeOne(7392), Format::Turtle));
    assertNotContainsText('license', $unlicensed, 'no licence is claimed when none is configured');
});

test('dcterms:identifier holds literals; the link lives in the identity predicate', function (): void {
    $nt = projectedLines(149);
    $identifier = '<http://purl.org/dc/terms/identifier>';
    assertContainsText(R . '149> ' . $identifier . ' "https://d-nb.info/gnd/124120881" .', $nt, 'the URI, as a string');
    assertContainsText(R . '149> ' . $identifier . ' "GND 124120881" .', $nt, 'and its label, which is not lost');
    assertNotContainsText($identifier . ' <', $nt, 'dcterms:identifier never holds an IRI (its range is rdfs:Literal)');
    assertContainsText(R . '149> <http://www.w3.org/2002/07/owl#sameAs> <https://d-nb.info/gnd/124120881> .', $nt);
    assertNotContainsText('seeAlso', $nt, 'an identity needs no seeAlso');
    assertNotContainsText($identifier . ' <', projectedLines(7392));
    assertNotContainsText($identifier . ' <', projectedLines(2390));
});

test('the label comes from public values only, never from Omeka\'s title column', function (): void {
    $nt = projectedLines(7400);
    assertNotContainsText('Secret working title', $nt, 'resource.title holds the private title value');
    assertNotContainsText('rdf-schema#label', $nt, 'no public title, no label');
    assertNotContainsText('prefLabel', $nt);
});

test('the label follows the template\'s title property, skipping blank values', function (): void {
    $nt = projectedLines(7401);
    assertContainsText(R . '7401> <http://www.w3.org/2000/01/rdf-schema#label> "Template title" .', $nt);
    assertNotContainsText('rdf-schema#label> "Generic title"', $nt);
    assertContainsText('<http://purl.org/dc/terms/title> "Generic title" .', $nt, 'dcterms:title itself is still published');
});

test('every resource is the primary topic of its record page, dated', function (): void {
    $foaf = '<http://xmlns.com/foaf/0.1/isPrimaryTopicOf>';
    $page = static fn (string $path): string => '<' . FIXTURE_BASE . '/s/amira/' . $path . '>';
    assertContainsText(R . '7392> ' . $foaf . ' ' . $page('item/7392') . ' .', projectedLines(7392));
    assertContainsText(R . '6259> ' . $foaf . ' ' . $page('item-set/6259') . ' .', projectedLines(6259));
    assertContainsText(R . '8001> ' . $foaf . ' ' . $page('media/8001') . ' .', projectedLines(8001));
    assertContainsText($page('item/7392') . ' <http://purl.org/dc/terms/modified> "2026-06-09T05:37:09Z"^^<http://www.w3.org/2001/XMLSchema#dateTime> .', projectedLines(7392));
    assertContainsText($page('media/8001') . ' <http://purl.org/dc/terms/modified> "2026-03-26T10:11:06Z"^^<http://www.w3.org/2001/XMLSchema#dateTime> .', projectedLines(8001), 'never modified: the creation date');

    $config = moduleConfig()['projection'];
    $siteless = new ResourceProjector(amiraSource(), new UriScheme(FIXTURE_BASE), new IdentityLinks($config['identity_links']), $config);
    $nt = (new NTriplesSerializer())->serialize($siteless->describeOne(7392)->triples);
    assertNotContainsText('isPrimaryTopicOf', $nt, 'no site, no page');
});

test('an item set that holds concepts is a SKOS concept scheme', function (): void {
    $type = '<http://www.w3.org/1999/02/22-rdf-syntax-ns#type>';
    assertContainsText(R . '6260> ' . $type . ' <http://www.w3.org/2004/02/skos/core#ConceptScheme> .', projectedLines(6260));
    assertContainsText(R . '6260> ' . $type . ' <http://purl.org/dc/dcmitype/Collection> .', projectedLines(6260));
    assertNotContainsText('ConceptScheme', projectedLines(6259), 'a collection of research items is not a scheme');
});

test('impossible calendar dates are not typed; leap days are', function (): void {
    $nt = projectedLines(7400);
    $xsd = 'http://www.w3.org/2001/XMLSchema#';
    assertContainsText('<http://purl.org/dc/terms/created> "2021-02-31" .', $nt);
    assertContainsText('"2024-02-29"^^<' . $xsd . 'date>', $nt);
    assertContainsText('<http://purl.org/dc/terms/created> "2023-02-29T10:00" .', $nt);
    assertSameValue(null, ResourceProjector::literal('numeric:timestamp', '2021-04-31')->datatype);
    assertSameValue($xsd . 'date', ResourceProjector::literal('numeric:timestamp', '2000-02-29')->datatype);
    assertSameValue(null, ResourceProjector::literal('numeric:timestamp', '1900-02-29')->datatype);
});

test('numeric intervals are plain literals', function (): void {
    assertContainsText('<http://purl.org/dc/terms/created> "1975-03/1980" .', projectedLines(7400));
    $literal = ResourceProjector::literal('numeric:interval', ' 1975-03-12/1980 ', 'en');
    assertSameValue(['1975-03-12/1980', null, null], [$literal->value, $literal->language, $literal->datatype]);
});
