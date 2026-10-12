<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf\Serializer;

use DRELinkedData\Rdf\Iri;
use DRELinkedData\Rdf\Literal;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\Triple;
use DRELinkedData\Rdf\Vocab;

/**
 * JSON-LD 1.1 in a flattened, compacted shape: one node object per subject in
 * `@graph`, compact IRIs for keys and types, and a `@context` declaring only
 * the prefixes used. Every value is an array, so consumers never have to
 * branch on single versus multiple values.
 */
final class JsonLdSerializer
{
    public function __construct(private readonly PrefixMap $prefixes)
    {
    }

    /** @param iterable<Triple> $triples */
    public function serialize(iterable $triples): string
    {
        $used = [];
        /** @var array<string,array<string,mixed>> $nodes */
        $nodes = [];
        /** @var array<string,array<string,true>> $seen subject => dedupe key => true */
        $seen = [];

        foreach ($triples as $triple) {
            $subject = $triple->subject->value;
            $nodes[$subject] ??= ['@id' => $subject];

            $key = NTriplesSerializer::iri($triple->predicate) . ' ' . NTriplesSerializer::term($triple->object);
            if (isset($seen[$subject][$key])) {
                continue;
            }
            $seen[$subject][$key] = true;

            if ($triple->predicate->value === Vocab::RDF_TYPE && $triple->object instanceof Iri) {
                $nodes[$subject]['@type'][] = $this->name($triple->object->value, $used);
                continue;
            }
            $property = $this->name($triple->predicate->value, $used);
            $nodes[$subject][$property][] = $this->value($triple->object, $used);
        }

        $context = [];
        foreach ($this->prefixes->all() as $prefix => $namespace) {
            if (isset($used[$prefix])) {
                $context[$prefix] = (new Iri($namespace))->value;
            }
        }

        $document = ['@context' => (object) $context, '@graph' => array_values($nodes)];
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * @param array<string,true> $used
     * @return array<string,string>
     */
    private function value(Iri|Literal $term, array &$used): array
    {
        if ($term instanceof Iri) {
            return ['@id' => $term->value];
        }
        $value = ['@value' => $term->value];
        if ($term->language !== null) {
            $value['@language'] = $term->language;
        } elseif ($term->datatype !== null && $term->datatype !== Vocab::XSD . 'string') {
            $value['@type'] = $this->name($term->datatype, $used);
        }
        return $value;
    }

    /** @param array<string,true> $used */
    private function name(string $iri, array &$used): string
    {
        $compact = $this->prefixes->compact($iri, true);
        if ($compact === null) {
            return (new Iri($iri))->value;
        }
        $used[$compact[0]] = true;
        return $compact[0] . ':' . $compact[1];
    }
}
