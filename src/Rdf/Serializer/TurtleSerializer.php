<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf\Serializer;

use DRELinkedData\Rdf\Iri;
use DRELinkedData\Rdf\Literal;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\Triple;
use DRELinkedData\Rdf\Vocab;

/**
 * Turtle for people: statements grouped by subject and predicate, `a` for
 * rdf:type, prefixed names wherever a declared prefix covers the IRI, and only
 * the prefixes actually used declared at the top.
 */
final class TurtleSerializer
{
    public function __construct(private readonly PrefixMap $prefixes)
    {
    }

    /** @param iterable<Triple> $triples */
    public function serialize(iterable $triples): string
    {
        /** @var array<string,array<string,array<string,Iri|Literal>>> $graph subject => predicate => key => object */
        $graph = [];
        foreach ($triples as $triple) {
            $object = $triple->object;
            $graph[$triple->subject->value][$triple->predicate->value][NTriplesSerializer::term($object)] = $object;
        }

        $used = [];
        $blocks = [];
        foreach ($graph as $subject => $predicates) {
            $lines = [];
            foreach ($predicates as $predicate => $objects) {
                $name = $predicate === Vocab::RDF_TYPE ? 'a' : $this->name($predicate, $used);
                // A loop, not array_map: an arrow function would capture $used
                // by value, and the prefixes objects use would go undeclared.
                $terms = [];
                foreach ($objects as $object) {
                    $terms[] = $this->term($object, $used);
                }
                $lines[] = $name . ' ' . implode(', ', $terms);
            }
            $blocks[] = $this->name($subject, $used) . "\n    " . implode(" ;\n    ", $lines) . ' .';
        }

        $header = '';
        foreach ($this->prefixes->all() as $prefix => $namespace) {
            if (isset($used[$prefix])) {
                $header .= '@prefix ' . $prefix . ': <' . (new Iri($namespace))->value . "> .\n";
            }
        }

        return ($header !== '' ? $header . "\n" : '') . implode("\n\n", $blocks) . "\n";
    }

    /** @param array<string,true> $used */
    private function term(Iri|Literal $term, array &$used): string
    {
        if ($term instanceof Iri) {
            return $this->name($term->value, $used);
        }
        $out = '"' . NTriplesSerializer::escape($term->value) . '"';
        if ($term->language !== null) {
            return $out . '@' . $term->language;
        }
        if ($term->datatype !== null && $term->datatype !== Vocab::XSD . 'string') {
            return $out . '^^' . $this->name($term->datatype, $used);
        }
        return $out;
    }

    /** @param array<string,true> $used */
    private function name(string $iri, array &$used): string
    {
        $compact = $this->prefixes->compact($iri);
        if ($compact === null) {
            return NTriplesSerializer::iri(new Iri($iri));
        }
        $used[$compact[0]] = true;
        return $compact[0] . ':' . $compact[1];
    }
}
