<?php
declare(strict_types=1);

namespace DRELinkedData\View\Helper;

use DRELinkedData\Projection\ResourceDescription;
use DRELinkedData\Projection\SameAs;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\Format;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * The module's view helper contract, shared with the DRE theme and DRE SEO
 * (both guard on the helper's existence, so the module stays optional).
 *
 * `$this->dreLinkedData($resource)` — the resource's Linked Data identifier,
 * its record page, its identity links and its RDF documents, the documents
 * shaped like DRE SEO's citation downloads so the record apparatus can list
 * them beside BibTeX and RIS:
 *
 *     [
 *       'uri'       => '{base}/resource/41980',
 *       'page'      => '{base}/s/amira/item/41980',   // null without a site
 *       'sameAs'    => ['http://www.wikidata.org/entity/Q1', …],
 *       'downloads' => [
 *         ['label' => 'Turtle',    'type' => 'text/turtle',           'url' => '…/resource/41980.ttl'],
 *         ['label' => 'N-Triples', 'type' => 'application/n-triples', 'url' => '…/resource/41980.nt'],
 *         ['label' => 'JSON-LD',   'type' => 'application/ld+json',   'url' => '…/resource/41980.jsonld'],
 *         ['label' => 'RDF/XML',   'type' => 'application/rdf+xml',   'url' => '…/resource/41980.rdf'],
 *       ],
 *     ]
 *
 * It is an empty array for anything not publicly visible (the documents would
 * 404) and for anything that is not an item, item set or media.
 *
 * `$this->dreLinkedData()` returns the helper itself, and `->dataset()`
 * describes the whole dataset ({@see \DRELinkedData\Dataset\DatasetSummary::dataset()}),
 * or [] when the module cannot describe it.
 */
class LinkedData extends AbstractHelper
{
    /** @var array<string,mixed>|null */
    private ?array $dataset = null;

    /**
     * @param \Closure():array<string,mixed> $datasetFactory
     */
    public function __construct(
        private readonly UriScheme $uris,
        private readonly SameAs $sameAs,
        private readonly \Closure $datasetFactory,
    ) {
    }

    /**
     * @return self|array{uri:string,page:?string,sameAs:string[],downloads:array<int,array{label:string,type:string,url:string}>}|array{}
     */
    public function __invoke(?AbstractResourceEntityRepresentation $resource = null): self|array
    {
        if ($resource === null) {
            return $this;
        }
        $kind = self::kind($resource);
        if ($kind === null || !self::isVisible($resource)) {
            return [];
        }
        $id = (int) $resource->id();
        $template = $resource->resourceTemplate();
        return [
            'uri' => $this->uris->resource($id)->value,
            'page' => $this->uris->page($id, $kind),
            'sameAs' => $this->sameAs->of($id, $template ? (int) $template->id() : null),
            'downloads' => array_map(
                fn (Format $format): array => [
                    'label' => $format->label(),
                    'type' => $format->mediaType(),
                    'url' => $this->uris->resourceDocument($id, $format),
                ],
                Format::cases()
            ),
        ];
    }

    /**
     * The dataset: its IRI, VoID document, title, description, licence,
     * publisher, figures and distributions, and its landing page (the public
     * page carrying the Linked open data block, or null). Built once per request.
     *
     * @return array<string,mixed>
     */
    public function dataset(): array
    {
        if ($this->dataset === null) {
            try {
                $this->dataset = ($this->datasetFactory)();
            } catch (\Throwable) {
                $this->dataset = [];
            }
        }
        return $this->dataset;
    }

    /** The visibility rule {@see \DRELinkedData\Projection\OmekaSource} applies in SQL. */
    public static function isVisible(AbstractResourceEntityRepresentation $resource): bool
    {
        if (!$resource->isPublic()) {
            return false;
        }
        if ($resource instanceof MediaRepresentation) {
            return $resource->item()->isPublic();
        }
        return $resource instanceof ItemRepresentation || $resource instanceof ItemSetRepresentation;
    }

    private static function kind(AbstractResourceEntityRepresentation $resource): ?string
    {
        return match (true) {
            $resource instanceof ItemRepresentation => ResourceDescription::ITEM,
            $resource instanceof ItemSetRepresentation => ResourceDescription::ITEM_SET,
            $resource instanceof MediaRepresentation => ResourceDescription::MEDIA,
            default => null,
        };
    }
}
