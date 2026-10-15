<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf\Serializer;

use DRELinkedData\Rdf\Iri;
use DRELinkedData\Rdf\Literal;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\Triple;
use DRELinkedData\Rdf\Vocab;

/**
 * RDF/XML (RDF 1.1), for the clients that read nothing else.
 *
 * The plainest form the grammar allows: one `rdf:Description rdf:about` per
 * subject and one property element per statement, `rdf:resource` for IRIs,
 * `xml:lang` or `rdf:datatype` on literals. No abbreviations, so there is no
 * striped-syntax corner for a parser to disagree about. The projection never
 * mints blank nodes (every node, list cells included, is an IRI), so
 * `rdf:nodeID` is never needed, and an `rdf:List` is written as the ordinary
 * `rdf:first` / `rdf:rest` statements it consists of.
 *
 * Property elements need a QName. A declared prefix is used when its local
 * part is an XML name; otherwise the IRI is split before its longest NCName
 * suffix under a generated `ns{n}` prefix. A predicate with no NCName suffix
 * at all (one ending in `/` or a digit) cannot be written in RDF/XML; such a
 * statement is left out and counted in a comment rather than breaking the
 * document. None of the vocabularies Omeka loads has one.
 */
final class RdfXmlSerializer
{
    private const NCNAME_SUFFIX = '~[A-Za-z_][A-Za-z0-9_.\-]*$~';

    public function __construct(private readonly PrefixMap $prefixes)
    {
    }

    /** @param iterable<Triple> $triples */
    public function serialize(iterable $triples): string
    {
        /** @var array<string,array<string,Triple>> $graph subject => dedupe key => triple */
        $graph = [];
        foreach ($triples as $triple) {
            $key = NTriplesSerializer::iri($triple->predicate) . ' ' . NTriplesSerializer::term($triple->object);
            $graph[$triple->subject->value][$key] ??= $triple;
        }

        /** @var array<string,string> $namespaces namespace => prefix, in first-use order */
        $namespaces = [Vocab::RDF => 'rdf'];
        $body = '';
        $skipped = 0;
        foreach ($graph as $subject => $statements) {
            $body .= '  <rdf:Description rdf:about="' . self::attribute($subject) . "\">\n";
            foreach ($statements as $triple) {
                $qname = $this->qname($triple->predicate->value, $namespaces);
                if ($qname === null) {
                    $skipped++;
                    continue;
                }
                $body .= '    ' . self::property($qname, $triple->object) . "\n";
            }
            $body .= "  </rdf:Description>\n";
        }

        $root = '<rdf:RDF';
        foreach ($namespaces as $namespace => $prefix) {
            $root .= "\n    xmlns:" . $prefix . '="' . self::attribute($namespace) . '"';
        }
        $root .= '>';

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . $root . "\n"
            . ($skipped > 0 ? '  <!-- ' . $skipped . " statement(s) with a predicate RDF/XML cannot name were left out -->\n" : '')
            . $body
            . "</rdf:RDF>\n";
    }

    private static function property(string $qname, Iri|Literal $object): string
    {
        if ($object instanceof Iri) {
            return '<' . $qname . ' rdf:resource="' . self::attribute($object->value) . '"/>';
        }
        $attributes = '';
        if ($object->language !== null) {
            $attributes = ' xml:lang="' . self::attribute($object->language) . '"';
        } elseif ($object->datatype !== null && $object->datatype !== Vocab::XSD . 'string') {
            $attributes = ' rdf:datatype="' . self::attribute((new Iri($object->datatype))->value) . '"';
        }
        return '<' . $qname . $attributes . '>' . self::text($object->value) . '</' . $qname . '>';
    }

    /**
     * `prefix:local` for a predicate, declaring the namespace on first use;
     * null when the IRI has no NCName suffix.
     *
     * @param array<string,string> $namespaces namespace => prefix
     */
    private function qname(string $iri, array &$namespaces): ?string
    {
        $compact = $this->prefixes->compact($iri);
        if ($compact !== null && self::isNcName($compact[1]) && self::isUsablePrefix($compact[0])) {
            $namespace = substr($iri, 0, strlen($iri) - strlen($compact[1]));
            $prefix = $namespaces[$namespace] ?? null;
            if ($prefix === null && !in_array($compact[0], $namespaces, true)) {
                $namespaces[$namespace] = $prefix = $compact[0];
            }
            if ($prefix !== null) {
                return $prefix . ':' . $compact[1];
            }
        }

        if (!preg_match(self::NCNAME_SUFFIX, $iri, $match, PREG_OFFSET_CAPTURE) || $match[0][1] === 0) {
            return null;
        }
        $local = $match[0][0];
        $namespace = substr($iri, 0, (int) $match[0][1]);
        if (!isset($namespaces[$namespace])) {
            $n = 0;
            while (in_array('ns' . $n, $namespaces, true)) {
                $n++;
            }
            $namespaces[$namespace] = 'ns' . $n;
        }
        return $namespaces[$namespace] . ':' . $local;
    }

    private static function isNcName(string $name): bool
    {
        return (bool) preg_match('~^[A-Za-z_][A-Za-z0-9_.\-]*$~', $name);
    }

    /** NCName prefixes only, and never one of the reserved `xml…` names. */
    private static function isUsablePrefix(string $prefix): bool
    {
        return self::isNcName($prefix) && stripos($prefix, 'xml') !== 0;
    }

    /** Character data: valid UTF-8, XML 1.0 characters only, markup escaped. */
    private static function text(string $value): string
    {
        return strtr(self::xmlChars($value), [
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
            "\r" => '&#13;',
        ]);
    }

    /** An attribute value: as text, plus quotes and the whitespace attribute normalisation would fold. */
    private static function attribute(string $value): string
    {
        return strtr(self::xmlChars($value), [
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
            '"' => '&quot;',
            "\r" => '&#13;',
            "\n" => '&#10;',
            "\t" => '&#9;',
        ]);
    }

    /**
     * XML 1.0 cannot carry C0 controls other than tab, newline and carriage
     * return, nor U+FFFE / U+FFFF, not even as character references; they
     * become U+FFFD, as invalid UTF-8 does.
     */
    private static function xmlChars(string $value): string
    {
        return (string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]|\x{FFFE}|\x{FFFF}~u', "\u{FFFD}", mb_scrub($value, 'UTF-8'));
    }
}
