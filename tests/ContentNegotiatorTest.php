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

test('unsupported types are not acceptable', function (): void {
    assertSameValue(null, ContentNegotiator::negotiate('application/rdf+xml', Format::mediaTypes()));
    assertSameValue(null, ContentNegotiator::rdfFormat('application/rdf+xml'), 'falls back to the page');
    assertSameValue(null, ContentNegotiator::negotiate('text/turtle;q=0', ['text/turtle']));
});
