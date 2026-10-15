<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

/**
 * An absolute IRI.
 *
 * Construction never fails: the characters N-Triples forbids inside `<…>`
 * (controls, space, `<>"{}|^` and backslash) are percent-encoded, and so is
 * every byte that is not part of valid UTF-8, so a dirty `uri` value from the
 * source data still serialises as valid RDF in every format. Whether a string
 * is an IRI at all is a separate question, answered by {@see Iri::isAbsolute()}
 * before construction.
 */
final class Iri
{
    /** One valid UTF-8 sequence (group 1), or a byte that cannot start one (group 2). */
    private const UTF8_OR_BYTE = '~([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]'
        . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        . '|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|(.)~s';

    public readonly string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = (string) preg_replace_callback(
                self::UTF8_OR_BYTE,
                static fn (array $match): string => isset($match[2]) && $match[2] !== ''
                    ? sprintf('%%%02X', ord($match[2]))
                    : $match[1],
                $value
            );
        }
        $this->value = (string) preg_replace_callback(
            '~[\x00-\x20<>"{}|^`\\\\]~',
            static fn (array $match): string => sprintf('%%%02X', ord($match[0])),
            $value
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

    /** The scheme, lower-cased (`https`, `urn`, `geo`). */
    public function scheme(): string
    {
        $colon = strpos($this->value, ':');
        return $colon === false ? '' : strtolower(substr($this->value, 0, $colon));
    }
}
