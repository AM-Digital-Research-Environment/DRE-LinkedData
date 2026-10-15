<?php
declare(strict_types=1);

use DRELinkedData\Rdf\ContentNegotiator;
use DRELinkedData\Rdf\Format;

test('browsers and bare clients get the record page', function (): void {
    $chrome = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
    $firefox = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
    foreach ([$chrome, $firefox, '*/*', '', null, 'text/*', 'garbage'] as $accept) {
        assertSameValue(null, ContentNegotiator::rdfFormat($accept), 'Accept: ' . var_export($accept, true));
    }
});

test('RDF clients get the format they name', function (): void {
    assertSameValue(Format::Turtle, ContentNegotiator::rdfFormat('text/turtle'));
    assertSameValue(Format::NTriples, ContentNegotiator::rdfFormat('application/n-triples'));
    assertSameValue(Format::JsonLd, ContentNegotiator::rdfFormat('application/ld+json'));
    assertSameValue(Format::JsonLd, ContentNegotiator::rdfFormat('application/ld+json; profile="http://www.w3.org/ns/json-ld#compacted"'));
    assertSameValue(Format::NTriples, ContentNegotiator::rdfFormat('application/n-triples;q=0.9, text/turtle;q=0.5'));
    assertSameValue(Format::Turtle, ContentNegotiator::rdfFormat('text/turtle, */*;q=0.1'));
    assertSameValue(Format::Turtle, ContentNegotiator::rdfFormat('text/html;q=0, text/turtle'));
});

test('quality ties go to the server preference', function (): void {
    assertSameValue('text/html', ContentNegotiator::negotiate('text/turtle, text/html', ['text/html', 'text/turtle']));
    assertSameValue('text/turtle', ContentNegotiator::negotiate('*/*', ['text/turtle', 'application/ld+json']));
});

test('a resource URI refuses only headers it cannot answer at all', function (): void {
    foreach (['application/xml', 'application/rdf+xml;q=0, application/xml', 'text/turtle;q=0', 'image/png'] as $accept) {
        assertTrue(!ContentNegotiator::acceptable($accept), $accept . ' should be a 406');
    }
    foreach ([null, '', 'garbage', '*/*', 'text/*', 'application/rdf+xml', 'application/rdf+xml, */*;q=0.1', 'text/html'] as $accept) {
        assertTrue(ContentNegotiator::acceptable($accept), var_export($accept, true) . ' should be answered');
    }
    assertSameValue(['text/html', 'text/turtle', 'application/n-triples', 'application/ld+json', 'application/rdf+xml'], ContentNegotiator::offers());
});

test('RDF/XML clients get RDF/XML, but Turtle wins every tie', function (): void {
    assertSameValue(Format::RdfXml, ContentNegotiator::rdfFormat('application/rdf+xml'));
    assertSameValue(Format::RdfXml, ContentNegotiator::rdfFormat('application/rdf+xml, text/turtle;q=0.5'));
    assertSameValue(Format::Turtle, ContentNegotiator::rdfFormat('application/rdf+xml, text/turtle'));
    assertSameValue(Format::NTriples, ContentNegotiator::rdfFormat('application/*'), 'a wildcard never lands on RDF/XML');
});

test('unsupported types are not acceptable', function (): void {
    assertSameValue(null, ContentNegotiator::negotiate('application/xml', Format::mediaTypes()));
    assertSameValue(null, ContentNegotiator::rdfFormat('application/xml'), 'falls back to the page');
    assertSameValue(null, ContentNegotiator::negotiate('text/turtle;q=0', ['text/turtle']));
});

test('formats know their media types, content types and extensions', function (): void {
    assertSameValue('text/turtle; charset=utf-8', Format::Turtle->contentType());
    assertSameValue('application/n-triples', Format::NTriples->contentType(), 'no charset parameter on N-Triples');
    assertSameValue('application/ld+json', Format::JsonLd->contentType(), 'no charset parameter on JSON-LD');
    assertSameValue('application/rdf+xml', Format::RdfXml->contentType());
    assertSameValue('http://www.w3.org/ns/formats/RDF_XML', Format::RdfXml->formatIri());
    assertSameValue('ttl|nt|jsonld|rdf', Format::extensionPattern());
    assertSameValue(Format::RdfXml, Format::fromMediaType('application/rdf+xml'));
});
