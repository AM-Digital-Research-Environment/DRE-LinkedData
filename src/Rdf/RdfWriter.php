<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

use DRELinkedData\Rdf\Serializer\JsonLdSerializer;
use DRELinkedData\Rdf\Serializer\NTriplesSerializer;
use DRELinkedData\Rdf\Serializer\TurtleSerializer;

/**
 * One entry point for all three serialisations. The prefix table is built
 * lazily from Omeka's vocabulary table, so a request that 404s or redirects
 * never pays for the query.
 */
final class RdfWriter
{
    private ?PrefixMap $prefixes = null;

    /** @param \Closure():PrefixMap $prefixFactory */
    public function __construct(private readonly \Closure $prefixFactory)
    {
    }

    /** @param iterable<Triple> $triples */
    public function write(iterable $triples, Format $format): string
    {
        return match ($format) {
            Format::NTriples => (new NTriplesSerializer())->serialize($triples),
            Format::Turtle => (new TurtleSerializer($this->prefixes()))->serialize($triples),
            Format::JsonLd => (new JsonLdSerializer($this->prefixes()))->serialize($triples),
        };
    }

    public function prefixes(): PrefixMap
    {
        return $this->prefixes ??= ($this->prefixFactory)();
    }
}
