<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf\Serializer;

use DRELinkedData\Rdf\Iri;
use DRELinkedData\Rdf\Literal;
use DRELinkedData\Rdf\Triple;
use DRELinkedData\Rdf\Vocab;

/**
 * N-Triples (RDF 1.1). Line-based and stateless, so the dump job streams it one
 * resource at a time straight into the gzip file.
 */
final class NTriplesSerializer
{
    /** @param iterable<Triple> $triples */
    public function serialize(iterable $triples): string
    {
        $out = '';
        foreach ($triples as $triple) {
            $out .= $this->line($triple);
        }
        return $out;
    }

    public function line(Triple $triple): string
    {
        return self::iri($triple->subject) . ' '
            . self::iri($triple->predicate) . ' '
            . self::term($triple->object) . " .\n";
    }

    public static function iri(Iri $iri): string
    {
        return '<' . $iri->value . '>';
    }

    public static function term(Iri|Literal $term): string
    {
        if ($term instanceof Iri) {
            return self::iri($term);
        }
        $out = '"' . self::escape($term->value) . '"';
        if ($term->language !== null) {
            return $out . '@' . $term->language;
        }
        if ($term->datatype !== null && $term->datatype !== Vocab::XSD . 'string') {
            return $out . '^^<' . (new Iri($term->datatype))->value . '>';
        }
        return $out;
    }

    /**
     * String escaping shared with Turtle: the ECHAR set, then any remaining C0
     * control or DEL as \uXXXX. Invalid UTF-8 is scrubbed first, because one
     * bad byte would otherwise make the whole document unparseable.
     */
    public static function escape(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            "\x08" => '\\b',
            "\x0C" => '\\f',
        ]);
        return (string) preg_replace_callback(
            '~[\x00-\x1F\x7F]~',
            static fn (array $match): string => sprintf('\\u%04X', ord($match[0])),
            $value
        );
    }
}
