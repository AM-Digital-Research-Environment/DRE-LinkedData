<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

/**
 * An absolute IRI.
 *
 * Construction never fails: the characters N-Triples forbids inside `<…>`
 * (controls, space, `<>"{}|^` and backslash) are percent-encoded, so a dirty
 * `uri` value from the source data still serialises as valid RDF in every
 * format. Whether a string is an IRI at all is a separate question, answered by
 * {@see Iri::isAbsolute()} before construction.
 */
final class Iri
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $this->value = (string) preg_replace_callback(
            '~[\x00-\x20<>"{}|^`\\\\]~',
            static fn (array $match): string => sprintf('%%%02X', ord($match[0])),
            trim($value)
        );
    }

    /**
     * A scheme followed by something: `https://…`, `urn:…`, `mailto:…`.
     * "Volume 8: Yoruba Architecture" is not one — a scheme has no spaces.
     */
    public static function isAbsolute(string $value): bool
    {
        return (bool) preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*:\S~', trim($value));
    }
}
