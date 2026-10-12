<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

/**
 * An RDF literal: a language-tagged string, a typed value, or (with neither) a
 * plain xsd:string. A literal never carries both — RDF 1.1 reserves the
 * language tag for rdf:langString.
 */
final class Literal
{
    /**
     * @param string|null $datatype full datatype IRI; null means xsd:string
     */
    public function __construct(
        public readonly string $value,
        public readonly ?string $language = null,
        public readonly ?string $datatype = null,
    ) {
        if ($language !== null && $datatype !== null) {
            throw new \InvalidArgumentException('A literal cannot have both a language tag and a datatype.');
        }
    }

    /**
     * An xsd:dateTime in UTC from anything DateTimeImmutable parses — Omeka's
     * `Y-m-d H:i:s` columns (stored in UTC) or an ISO 8601 string.
     */
    public static function dateTime(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            $date = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        return new self($date->format('Y-m-d\TH:i:s\Z'), null, Vocab::XSD . 'dateTime');
    }
}
