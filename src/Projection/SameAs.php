<?php
declare(strict_types=1);

namespace DRELinkedData\Projection;

use DRELinkedData\Rdf\Iri;

/**
 * The identity links of one resource, without projecting it: the canonical
 * owl:sameAs / skos:exactMatch targets of its authority identifiers and the
 * https://doi.org/ IRIs of its DOIs, exactly as the RDF states them
 * ({@see IdentityLinks::linkFor()}). One query per resource, remembered for
 * the request, for the view helper's `sameAs` key.
 */
final class SameAs
{
    /** @var array<int,string[]> */
    private array $memo = [];

    public function __construct(
        private readonly OmekaSource $source,
        private readonly IdentityLinks $identityLinks,
    ) {
    }

    /**
     * @param int|null $templateId the resource template (it decides
     *                             owl:sameAs versus skos:exactMatch; both are listed)
     * @return string[] IRIs in value order, without duplicates
     */
    public function of(int $id, ?int $templateId): array
    {
        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }
        $iris = [];
        foreach ($this->source->termValues($id, $this->identityLinks->terms()) as $value) {
            $link = $this->identityLinks->linkFor($value['term'], $value['uri'], $value['value'], $templateId);
            if ($link !== null) {
                $iris[(new Iri($link['iri']))->value] = true;
            }
        }
        return $this->memo[$id] = array_keys($iris);
    }
}
