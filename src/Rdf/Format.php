<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

/**
 * The RDF serialisations the module serves. The backing value is the file
 * extension used in document URLs (`/resource/7392.ttl`).
 */
enum Format: string
{
    case Turtle = 'ttl';
    case NTriples = 'nt';
    case JsonLd = 'jsonld';

    public function mediaType(): string
    {
        return match ($this) {
            self::Turtle => 'text/turtle',
            self::NTriples => 'application/n-triples',
            self::JsonLd => 'application/ld+json',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Turtle => 'Turtle',
            self::NTriples => 'N-Triples',
            self::JsonLd => 'JSON-LD',
        };
    }

    /** The W3C format IRI, for void:feature. */
    public function formatIri(): string
    {
        return Vocab::FORMATS . match ($this) {
            self::Turtle => 'Turtle',
            self::NTriples => 'N-Triples',
            self::JsonLd => 'JSON-LD',
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
}
