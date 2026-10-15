<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

/**
 * Namespaces the module itself writes, independent of the vocabularies loaded
 * into Omeka. Omeka's own prefixes (dcterms, bibo, foaf, dre, …) come from its
 * vocabulary table at runtime; see {@see PrefixMap}.
 */
final class Vocab
{
    public const RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    public const RDFS = 'http://www.w3.org/2000/01/rdf-schema#';
    public const XSD = 'http://www.w3.org/2001/XMLSchema#';
    public const OWL = 'http://www.w3.org/2002/07/owl#';
    public const SKOS = 'http://www.w3.org/2004/02/skos/core#';
    public const SCHEMA = 'https://schema.org/';
    public const VOID = 'http://rdfs.org/ns/void#';
    public const DCAT = 'http://www.w3.org/ns/dcat#';
    public const DCTERMS = 'http://purl.org/dc/terms/';
    public const DCTYPE = 'http://purl.org/dc/dcmitype/';
    public const FOAF = 'http://xmlns.com/foaf/0.1/';
    public const FORMATS = 'http://www.w3.org/ns/formats/';
    public const IANA_MEDIA = 'http://www.iana.org/assignments/media-types/';
    public const VCARD = 'http://www.w3.org/2006/vcard/ns#';
    public const SPDX = 'http://spdx.org/rdf/terms#';

    public const RDF_TYPE = self::RDF . 'type';

    /** The prefixes offered to serialisers ahead of Omeka's vocabulary table. */
    public const PREFIXES = [
        'rdf' => self::RDF,
        'rdfs' => self::RDFS,
        'xsd' => self::XSD,
        'owl' => self::OWL,
        'skos' => self::SKOS,
        'schema' => self::SCHEMA,
        'void' => self::VOID,
        'dcat' => self::DCAT,
        'dcterms' => self::DCTERMS,
        'dctype' => self::DCTYPE,
        'foaf' => self::FOAF,
        'formats' => self::FORMATS,
        'vcard' => self::VCARD,
        'spdx' => self::SPDX,
    ];

    public static function iri(string $namespace, string $localName): Iri
    {
        return new Iri($namespace . $localName);
    }
}
