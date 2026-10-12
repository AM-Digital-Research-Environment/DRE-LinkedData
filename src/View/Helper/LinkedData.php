<?php
declare(strict_types=1);

namespace DRELinkedData\View\Helper;

use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\Format;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * `$this->dreLinkedData($resource)` — the resource's Linked Data identifier and
 * its RDF documents, shaped like DRE SEO's citation downloads so the theme's
 * record apparatus can list them beside BibTeX and RIS. The theme guards on
 * the helper's existence, so the module stays optional.
 *
 * Returns an empty array for anything not publicly visible: the documents
 * would 404 for that resource anyway.
 */
class LinkedData extends AbstractHelper
{
    public function __construct(private readonly UriScheme $uris)
    {
    }

    /**
     * @return array{uri:string,downloads:array<int,array{label:string,type:string,url:string}>}|array{}
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource): array
    {
        if (!self::isVisible($resource)) {
            return [];
        }
        $id = (int) $resource->id();
        return [
            'uri' => $this->uris->resource($id)->value,
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
}
