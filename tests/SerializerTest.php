<?php
declare(strict_types=1);

use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\Iri;
use DRELinkedData\Rdf\Literal;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\RdfWriter;
use DRELinkedData\Rdf\Serializer\JsonLdSerializer;
use DRELinkedData\Rdf\Serializer\NTriplesSerializer;
use DRELinkedData\Rdf\Serializer\TurtleSerializer;
use DRELinkedData\Rdf\Triple;
use DRELinkedData\Rdf\Vocab;

/** @return Triple[] */
function sampleGraph(): array
{
    $s = new Iri('https://data.example.org/resource/1');
    $o = new Iri('https://data.example.org/resource/2');
    return [
        new Triple($s, new Iri(Vocab::RDF_TYPE), new Iri('http://xmlns.com/foaf/0.1/Person')),
        new Triple($s, new Iri('http://purl.org/dc/terms/title'), new Literal('Ulli "Beier"')),
        new Triple($s, new Iri('http://purl.org/dc/terms/title'), new Literal('Ulli Beier', 'de')),
        new Triple($s, new Iri('http://purl.org/dc/terms/created'), new Literal('1922', null, Vocab::XSD . 'gYear')),
        new Triple($s, new Iri('http://purl.org/dc/terms/relation'), $o),
        new Triple($s, new Iri('http://example.org/vocab#has%20odd'), new Literal('x', null, Vocab::XSD . 'string')),
    ];
}

test('N-Triples escapes literals and IRIs to the letter of RDF 1.1', function (): void {
    $s = new Iri('https://data.example.org/resource/1');
    $nt = (new NTriplesSerializer())->serialize([
        new Triple($s, new Iri('http://purl.org/dc/terms/description'), new Literal("Line one\nline \"two\"\t\\ end\x01")),
        new Triple($s, new Iri('http://purl.org/dc/terms/source'), new Iri('https://example.org/a path/{x}')),
        new Triple($s, new Iri('http://purl.org/dc/terms/title'), new Literal("bad \xC3\x28 byte")),
    ]);
    assertContainsText('"Line one\\nline \\"two\\"\\t\\\\ end\\u0001" .', $nt);
    assertContainsText('<https://example.org/a%20path/%7Bx%7D> .', $nt);
    assertTrue(mb_check_encoding($nt, 'UTF-8'), 'output must be valid UTF-8');
    assertSameValue(3, substr_count($nt, " .\n"));
});

test('N-Triples writes datatypes and language tags, and omits xsd:string', function (): void {
    $nt = (new NTriplesSerializer())->serialize(sampleGraph());
    assertContainsText('"Ulli Beier"@de .', $nt);
    assertContainsText('"1922"^^<http://www.w3.org/2001/XMLSchema#gYear> .', $nt);
    assertContainsText('<http://example.org/vocab#has%20odd> "x" .', $nt);
});

test('Turtle groups statements, uses `a`, and declares only the prefixes it uses', function (): void {
    $ttl = (new TurtleSerializer(new PrefixMap()))->serialize(sampleGraph());
    assertContainsText("@prefix dcterms: <http://purl.org/dc/terms/> .\n", $ttl);
    assertContainsText("@prefix foaf: <http://xmlns.com/foaf/0.1/> .\n", $ttl);
    assertContainsText("@prefix xsd: <http://www.w3.org/2001/XMLSchema#> .\n", $ttl);
    assertNotContainsText('@prefix skos:', $ttl);
    assertNotContainsText('@prefix rdf:', $ttl, '`a` needs no rdf prefix');
    assertContainsText("<https://data.example.org/resource/1>\n    a foaf:Person ;\n", $ttl);
    assertContainsText('dcterms:title "Ulli \\"Beier\\"", "Ulli Beier"@de ;', $ttl);
    assertContainsText('dcterms:created "1922"^^xsd:gYear ;', $ttl);
    assertContainsText('<http://example.org/vocab#has%20odd> "x" .', $ttl, 'an unsafe local name stays a full IRI');
});

test('Turtle never compacts an IRI whose local name is unsafe', function (): void {
    $map = new PrefixMap();
    assertSameValue(['dcterms', 'title'], $map->compact('http://purl.org/dc/terms/title'));
    assertSameValue(null, $map->compact('http://purl.org/dc/terms/has space'));
    assertSameValue(null, $map->compact('http://purl.org/dc/terms/ends.'));
    assertSameValue(null, $map->compact('https://data.example.org/resource/1'));
});

test('the prefix map keeps one name per namespace and one namespace per name', function (): void {
    $map = PrefixMap::withVocabularies([
        ['prefix' => 'dcterms', 'namespace_uri' => 'http://example.org/not-dcterms/'],
        ['prefix' => 'dc', 'namespace_uri' => 'http://purl.org/dc/terms/'],
        ['prefix' => 'bibo', 'namespace_uri' => 'http://purl.org/ontology/bibo/'],
    ]);
    $all = $map->all();
    assertSameValue('http://purl.org/dc/terms/', $all['dcterms']);
    assertTrue(!isset($all['dc']), 'a second prefix for dcterms is not added');
    assertSameValue('http://purl.org/ontology/bibo/', $all['bibo']);
    assertSameValue('http://purl.org/ontology/bibo/', $map->namespaceOf('http://purl.org/ontology/bibo/doi'));
});

test('JSON-LD compacts keys and types and keeps every value as an array', function (): void {
    $graph = sampleGraph();
    $graph[] = $graph[1]; // a duplicate statement
    $json = json_decode((new JsonLdSerializer(new PrefixMap()))->serialize($graph), true, 512, JSON_THROW_ON_ERROR);
    $context = $json['@context'];
    ksort($context);
    assertSameValue([
        'dcterms' => 'http://purl.org/dc/terms/',
        'foaf' => 'http://xmlns.com/foaf/0.1/',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
    ], $context, 'only the used prefixes are declared');
    $node = $json['@graph'][0];
    assertSameValue('https://data.example.org/resource/1', $node['@id']);
    assertSameValue(['foaf:Person'], $node['@type']);
    assertSameValue([['@value' => 'Ulli "Beier"'], ['@value' => 'Ulli Beier', '@language' => 'de']], $node['dcterms:title']);
    assertSameValue([['@value' => '1922', '@type' => 'xsd:gYear']], $node['dcterms:created']);
    assertSameValue([['@id' => 'https://data.example.org/resource/2']], $node['dcterms:relation']);
    assertSameValue([['@value' => 'x']], $node['http://example.org/vocab#has%20odd']);
});

test('the writer serialises lazily and builds its prefixes once', function (): void {
    $calls = 0;
    $writer = new RdfWriter(static function () use (&$calls): PrefixMap {
        $calls++;
        return new PrefixMap();
    });
    $writer->write(sampleGraph(), Format::NTriples);
    assertSameValue(0, $calls, 'N-Triples needs no prefixes');
    $writer->write(sampleGraph(), Format::Turtle);
    $writer->write(sampleGraph(), Format::JsonLd);
    assertSameValue(1, $calls);
});

test('a literal cannot carry both a language and a datatype', function (): void {
    try {
        new Literal('x', 'en', Vocab::XSD . 'string');
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Expected an InvalidArgumentException.');
});

test('Omeka datetimes become UTC xsd:dateTime literals', function (): void {
    assertSameValue('2026-06-09T05:37:09Z', Literal::dateTime('2026-06-09 05:37:09')?->value);
    assertSameValue('2026-06-09T03:37:09Z', Literal::dateTime('2026-06-09T05:37:09+02:00')?->value);
    assertSameValue(null, Literal::dateTime(''));
    assertSameValue(null, Literal::dateTime('not a date'));
});
