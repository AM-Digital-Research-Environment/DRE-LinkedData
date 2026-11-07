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

    /**
     * Media types some clients ask for instead of the registered one, each
     * answered with the format it maps to (served under its registered type).
     * A JSON client that sends only `application/json` can read JSON-LD, and
     * Turtle is valid N3. They come after every registered type in
     * {@see mediaTypes()}, so they never win a tie.
     */
    public const ALIASES = [
        'application/json' => self::JsonLd,
        'text/n3' => self::Turtle,
        'application/x-turtle' => self::Turtle,
    ];

    /** The format for a registered media type or one of its {@see ALIASES}. */
    public static function fromMediaType(string $mediaType): ?self
    {
        foreach (self::cases() as $format) {
            if ($format->mediaType() === $mediaType) {
                return $format;
            }
        }
        return self::ALIASES[$mediaType] ?? null;
    }

    /**
     * @param bool $withAliases also the {@see ALIASES}, after the registered types
     * @return string[] media types in server preference order
     */
    public static function mediaTypes(bool $withAliases = false): array
    {
        $types = array_map(static fn (self $format): string => $format->mediaType(), self::cases());
        return $withAliases ? [...$types, ...array_keys(self::ALIASES)] : $types;
    }

    /**
     * Label, media type and URL of the document in every format, in case
     * order: the shape the view helper's `downloads` and the page block's
     * format lists share.
     *
     * @param \Closure(self):string $url
     * @return list<array{label:string,type:string,url:string}>
     */
    public static function documents(\Closure $url): array
    {
        return array_map(
            static fn (self $format): array => [
                'label' => $format->label(),
                'type' => $format->mediaType(),
                'url' => $url($format),
            ],
            self::cases()
        );
    }

    /** The route constraint matching every extension (`ttl|nt|jsonld|rdf`). */
    public static function extensionPattern(): string
    {
        return implode('|', array_map(static fn (self $format): string => $format->value, self::cases()));
    }
}
