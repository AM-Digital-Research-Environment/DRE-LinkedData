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
 *
 * A JSON-LD processor reads any `prefix:rest` string whose prefix is a context
 * term as a compact IRI — absolute IRIs included. So when an IRI written in
 * full has a scheme that is also a prefix in use (`geo:12.3,4.5` beside the
 * `geo` vocabulary, or a `doi:` IRI beside a `doi` prefix), that prefix is
 * dropped from the context and the document is built again without it.
 */
final class JsonLdSerializer
{
    public function __construct(private readonly PrefixMap $prefixes)
    {
    }

    /** @param iterable<Triple> $triples */
    public function serialize(iterable $triples): string
    {
        $triples = is_array($triples) ? $triples : iterator_to_array($triples, false);

        /** @var array<string,true> $banned prefixes that must stay out of the context */
        $banned = [];
        // Each pass bans at least one more prefix or ends, so this terminates.
        do {
            [$document, $collisions] = $this->build($triples, $banned);
            $banned += $collisions;
        } while ($collisions !== []);

        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * @param Triple[] $triples
     * @param array<string,true> $banned
     * @return array{0:array<string,mixed>,1:array<string,true>} the document, and
     *         the used prefixes some full IRI in it would be mistaken for
     */
    private function build(array $triples, array $banned): array
    {
        $used = [];
        /** @var array<string,true> $schemes schemes of the IRIs written in full */
        $schemes = [];
        /** @var array<string,array<string,mixed>> $nodes */
        $nodes = [];
        /** @var array<string,array<string,true>> $seen subject => dedupe key => true */
        $seen = [];

        foreach ($triples as $triple) {
            $subject = $triple->subject->value;
            if (!isset($nodes[$subject])) {
                $nodes[$subject] = ['@id' => $this->full($triple->subject, $schemes)];
            }

            $key = NTriplesSerializer::iri($triple->predicate) . ' ' . NTriplesSerializer::term($triple->object);
            if (isset($seen[$subject][$key])) {
                continue;
            }
            $seen[$subject][$key] = true;

            if ($triple->predicate->value === Vocab::RDF_TYPE && $triple->object instanceof Iri) {
                $nodes[$subject]['@type'][] = $this->name($triple->object->value, $used, $banned, $schemes);
                continue;
            }
            $property = $this->name($triple->predicate->value, $used, $banned, $schemes);
            $nodes[$subject][$property][] = $this->value($triple->object, $used, $banned, $schemes);
        }

        $context = [];
        foreach ($this->prefixes->all() as $prefix => $namespace) {
            if (isset($used[$prefix])) {
                $context[$prefix] = (new Iri($namespace))->value;
            }
        }

        $collisions = [];
        foreach ($context as $prefix => $namespace) {
            if (isset($schemes[strtolower((string) $prefix)])) {
                $collisions[(string) $prefix] = true;
            }
        }

        return [['@context' => (object) $context, '@graph' => array_values($nodes)], $collisions];
    }

    /**
     * @param array<string,true> $used
     * @param array<string,true> $banned
     * @param array<string,true> $schemes
     * @return array<string,string>
     */
    private function value(Iri|Literal $term, array &$used, array $banned, array &$schemes): array
    {
        if ($term instanceof Iri) {
            return ['@id' => $this->full($term, $schemes)];
        }
        $value = ['@value' => $term->value];
        if ($term->language !== null) {
            $value['@language'] = $term->language;
        } elseif ($term->datatype !== null && $term->datatype !== Vocab::XSD . 'string') {
            $value['@type'] = $this->name($term->datatype, $used, $banned, $schemes);
        }
        return $value;
    }

    /**
     * @param array<string,true> $used
     * @param array<string,true> $banned
     * @param array<string,true> $schemes
     */
    private function name(string $iri, array &$used, array $banned, array &$schemes): string
    {
        $compact = $this->prefixes->compact($iri, true);
        if ($compact === null || isset($banned[$compact[0]])) {
            return $this->full(new Iri($iri), $schemes);
        }
        $used[$compact[0]] = true;
        return $compact[0] . ':' . $compact[1];
    }

    /**
     * An IRI written in full, noting its scheme — unless it is hierarchical
     * (`scheme://…`), which JSON-LD never reads as a compact IRI.
     *
     * @param array<string,true> $schemes
     */
    private function full(Iri $iri, array &$schemes): string
    {
        $scheme = $iri->scheme();
        if ($scheme !== '' && !str_starts_with(substr($iri->value, strlen($scheme) + 1), '//')) {
            $schemes[$scheme] = true;
        }
        return $iri->value;
    }
}
