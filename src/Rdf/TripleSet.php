<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

use DRELinkedData\Rdf\Serializer\NTriplesSerializer;

/**
 * Statements without duplicates, in first-seen order. Two terms are the same
 * statement when they serialise to the same N-Triples line, which is RDF's
 * own notion of term equality.
 */
final class TripleSet
{
    /** @var array<string,Triple> */
    private array $triples = [];

    /** Add a statement; false when it was already there. */
    public function add(Iri $subject, Iri $predicate, Iri|Literal $object): bool
    {
        $key = NTriplesSerializer::iri($subject) . ' ' . NTriplesSerializer::iri($predicate) . ' ' . NTriplesSerializer::term($object);
        if (isset($this->triples[$key])) {
            return false;
        }
        $this->triples[$key] = new Triple($subject, $predicate, $object);
        return true;
    }

    /** @return Triple[] */
    public function all(): array
    {
        return array_values($this->triples);
    }
}
