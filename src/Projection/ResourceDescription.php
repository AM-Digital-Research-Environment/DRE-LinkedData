<?php
declare(strict_types=1);

namespace DRELinkedData\Projection;

use DRELinkedData\Rdf\Triple;

/**
 * The projected RDF for one visible resource, plus what the controllers and
 * the dump job need to know about it without re-reading the database.
 */
final class ResourceDescription
{
    public const ITEM = 'item';
    public const ITEM_SET = 'item_set';
    public const MEDIA = 'media';

    /**
     * @param Triple[] $triples
     * @param array<int,array{authority:string,predicate:string}> $identityLinks one entry per link triple
     * @param int[] $itemSetIds the public item sets an item belongs to
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly ?string $modified,
        public readonly array $triples,
        public readonly array $identityLinks = [],
        /** The resource template, which (with the item sets) decides the dump's named graph. */
        public readonly ?int $templateId = null,
        public readonly array $itemSetIds = [],
    ) {
    }

    /** The site route controller segment for this kind (`/s/{slug}/{controller}/{id}`). */
    public function siteController(): string
    {
        return self::siteControllerFor($this->kind);
    }

    public static function siteControllerFor(string $kind): string
    {
        return match ($kind) {
            self::ITEM_SET => 'item-set',
            self::MEDIA => 'media',
            default => 'item',
        };
    }
}
