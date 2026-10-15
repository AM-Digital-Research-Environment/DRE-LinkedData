<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

/**
 * The RDF serialisations the module serves. The backing value is the file
 * extension used in document URLs (`/resource/7392.ttl`). Case order is the
 * server preference when a client accepts several formats equally: Turtle
 * first, RDF/XML last.
 */
enum Format: string
{
    case Turtle = 'ttl';
    case NTriples = 'nt';
    case JsonLd = 'jsonld';
    case RdfXml = 'rdf';

    public function mediaType(): string
    {
        return match ($this) {
            self::Turtle => 'text/turtle',
            self::NTriples => 'application/n-triples',
            self::JsonLd => 'application/ld+json',
            self::RdfXml => 'application/rdf+xml',
        };
    }

    /**
     * The Content-Type header value. Only Turtle takes a charset parameter:
     * N-Triples and JSON-LD are UTF-8 by definition and their registrations
     * define no such parameter, and RDF/XML declares its encoding itself.
     */
    public function contentType(): string
    {
        return $this === self::Turtle ? $this->mediaType() . '; charset=utf-8' : $this->mediaType();
    }

    public function label(): string
    {
        return match ($this) {
            self::Turtle => 'Turtle',
            self::NTriples => 'N-Triples',
            self::JsonLd => 'JSON-LD',
            self::RdfXml => 'RDF/XML',
        };
    }

    /** The W3C format IRI, for void:feature. */
    public function formatIri(): string
    {
        return Vocab::FORMATS . match ($this) {
            self::Turtle => 'Turtle',
            self::NTriples => 'N-Triples',
            self::JsonLd => 'JSON-LD',
            self::RdfXml => 'RDF_XML',
        };
    }

    public static function fromMediaType(string $mediaType): ?self
    {
        foreach (self::cases() as $format) {
            if ($format->mediaType() === $mediaType) {
                return $format;
            }
        }
        return null;
    }

    /** @return string[] media types in server preference order */
    public static function mediaTypes(): array
    {
        return array_map(static fn (self $format): string => $format->mediaType(), self::cases());
    }

    /** The route constraint matching every extension (`ttl|nt|jsonld|rdf`). */
    public static function extensionPattern(): string
    {
        return implode('|', array_map(static fn (self $format): string => $format->value, self::cases()));
    }
}
