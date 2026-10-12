<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

/**
 * One statement. Subjects are always IRIs: the projection mints an IRI for
 * every node it describes, so no serialiser has to handle blank nodes.
 */
final class Triple
{
    public function __construct(
        public readonly Iri $subject,
        public readonly Iri $predicate,
        public readonly Iri|Literal $object,
    ) {
    }
}
