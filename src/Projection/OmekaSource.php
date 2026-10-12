<?php
declare(strict_types=1);

namespace DRELinkedData\Projection;

use Doctrine\DBAL\Connection;

/**
 * Read-only access to Omeka's tables, with visibility decided in SQL.
 *
 * Everything public-facing goes through {@see visible()}: a resource is
 * published when it is public, and a media only when its item is public too —
 * the same rule Omeka applies to anonymous API reads. Values must be public
 * themselves, and a link to a resource that is not visible is dropped rather
 * than downgraded to a literal, so not even a private item's id leaks.
 *
 * Plain DBAL rather than the API: describing a batch of 250 resources is a
 * handful of queries instead of 250 hydrated representations. Ids are always
 * inlined as cast integers — PDO's emulated prepares cannot bind lists, and
 * the casts make inlining safe. Entity class names are always bound, because
 * MySQL and SQLite disagree about backslashes inside string literals.
 */
class OmekaSource
{
    public const ITEM = 'Omeka\Entity\Item';
    public const ITEM_SET = 'Omeka\Entity\ItemSet';
    public const MEDIA = 'Omeka\Entity\Media';

    private const TYPE_PARAMS = ['item' => self::ITEM, 'itemSet' => self::ITEM_SET, 'media' => self::MEDIA];

    /** @var array<int,array{iri:string,term:string}> property id => IRI and prefixed term */
    private array $properties = [];

    /** @var array<int,string> resource class id => IRI */
    private array $classes = [];

    /** @var array<int,true> property ids already looked up, found or not */
    private array $propertiesSeen = [];

    /** @var array<int,true> class ids already looked up, found or not */
    private array $classesSeen = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Visible resources among $ids, ordered by id.
     *
     * @param int[] $ids
     * @return array<int,array<string,mixed>>
     */
    public function resources(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return $this->connection->fetchAllAssociative(
            'SELECT r.id, r.resource_type, r.title, r.resource_class_id, r.resource_template_id, r.created, r.modified
             FROM resource r
             WHERE r.id IN (' . self::idList($ids) . ') AND ' . self::visible('r') . '
             ORDER BY r.id',
            self::TYPE_PARAMS
        );
    }

    /**
     * The next page of visible resource ids after $afterId (keyset paging).
     *
     * @return int[]
     */
    public function idsAfter(int $afterId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.id FROM resource r
             WHERE r.id > ' . max(0, $afterId) . ' AND ' . self::visible('r') . '
             ORDER BY r.id LIMIT ' . max(1, $limit),
            self::TYPE_PARAMS
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Public values of $ids, in Omeka's order, each flagged with whether its
     * linked resource (if any) is visible.
     *
     * @param int[] $ids
     * @return array<int,array<string,mixed>>
     */
    public function values(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return $this->connection->fetchAllAssociative(
            'SELECT v.resource_id, v.property_id, v.type, v.lang, v.value, v.uri, v.value_resource_id,
                    CASE WHEN t.id IS NOT NULL AND ' . self::visible('t') . ' THEN 1 ELSE 0 END AS target_visible
             FROM value v
             LEFT JOIN resource t ON t.id = v.value_resource_id
             WHERE v.resource_id IN (' . self::idList($ids) . ') AND v.is_public = 1
             ORDER BY v.resource_id, v.id',
            self::TYPE_PARAMS
        );
    }

    /**
     * Public item sets each item belongs to.
     *
     * @param int[] $itemIds
     * @return array<int,array{item_id:int|string,item_set_id:int|string}>
     */
    public function itemSetMemberships(array $itemIds): array
    {
        if (!$itemIds) {
            return [];
        }
        return $this->connection->fetchAllAssociative(
            'SELECT iis.item_id, iis.item_set_id
             FROM item_item_set iis
             JOIN resource s ON s.id = iis.item_set_id
             WHERE iis.item_id IN (' . self::idList($itemIds) . ') AND s.is_public = 1
             ORDER BY iis.item_id, iis.item_set_id'
        );
    }

    /**
     * Public media of the given (already visible) items, in display order.
     *
     * @param int[] $itemIds
     * @return array<int,array{item_id:int|string,id:int|string}>
     */
    public function itemMedia(array $itemIds): array
    {
        if (!$itemIds) {
            return [];
        }
        return $this->connection->fetchAllAssociative(
            'SELECT m.item_id, m.id
             FROM media m
             JOIN resource r ON r.id = m.id
             WHERE m.item_id IN (' . self::idList($itemIds) . ') AND r.is_public = 1
             ORDER BY m.item_id, m.position, m.id'
        );
    }

    /**
     * File and ingest details of the given (already visible) media.
     *
     * @param int[] $mediaIds
     * @return array<int,array<string,mixed>>
     */
    public function media(array $mediaIds): array
    {
        if (!$mediaIds) {
            return [];
        }
        return $this->connection->fetchAllAssociative(
            'SELECT m.id, m.item_id, m.ingester, m.source, m.media_type, m.storage_id, m.extension, m.has_original
             FROM media m
             WHERE m.id IN (' . self::idList($mediaIds) . ')
             ORDER BY m.id'
        );
    }

    /**
     * The given properties, looked up on first use and remembered.
     *
     * Only the ids asked for are queried: a single resource document needs a
     * dozen of the 668 properties AMIRA's vocabularies define, and loading the
     * whole table was two-thirds of a request's cost. The dump job asks batch by
     * batch, so each property is still fetched once per run.
     *
     * @param int[] $ids
     * @return array<int,array{iri:string,term:string}> property id => IRI and
     *         prefixed term, for the ids that exist
     */
    public function properties(array $ids): array
    {
        $missing = self::unseen($ids, $this->propertiesSeen);
        if ($missing) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT p.id, p.local_name, v.namespace_uri, v.prefix
                 FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id
                 WHERE p.id IN (' . self::idList($missing) . ')'
            );
            foreach ($rows as $row) {
                $this->properties[(int) $row['id']] = [
                    'iri' => $row['namespace_uri'] . $row['local_name'],
                    'term' => $row['prefix'] . ':' . $row['local_name'],
                ];
            }
        }
        return array_intersect_key($this->properties, array_flip(array_map('intval', $ids)));
    }

    /**
     * The given resource classes, looked up on first use and remembered.
     *
     * @param int[] $ids
     * @return array<int,string> resource class id => IRI, for the ids that exist
     */
    public function classes(array $ids): array
    {
        $missing = self::unseen($ids, $this->classesSeen);
        if ($missing) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT c.id, c.local_name, v.namespace_uri
                 FROM resource_class c JOIN vocabulary v ON v.id = c.vocabulary_id
                 WHERE c.id IN (' . self::idList($missing) . ')'
            );
            foreach ($rows as $row) {
                $this->classes[(int) $row['id']] = $row['namespace_uri'] . $row['local_name'];
            }
        }
        return array_intersect_key($this->classes, array_flip(array_map('intval', $ids)));
    }

    /** @return array<int,array{prefix:string,namespace_uri:string}> */
    public function vocabularies(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT prefix, namespace_uri FROM vocabulary ORDER BY id'
        );
    }

    /**
     * SQL predicate: resource alias $alias is visible to an anonymous reader.
     * Expects the :item, :itemSet and :media parameters to be bound.
     */
    private static function visible(string $alias): string
    {
        return "({$alias}.is_public = 1 AND ({$alias}.resource_type IN (:item, :itemSet)
            OR ({$alias}.resource_type = :media AND EXISTS (
                SELECT 1 FROM media {$alias}_m JOIN resource {$alias}_i ON {$alias}_i.id = {$alias}_m.item_id
                WHERE {$alias}_m.id = {$alias}.id AND {$alias}_i.is_public = 1))))";
    }

    /**
     * The ids not looked up before, marking them as looked up — an id that
     * matches no row (a deleted property) is not queried again either.
     *
     * @param int[] $ids
     * @param array<int,true> $seen
     * @return int[]
     */
    private static function unseen(array $ids, array &$seen): array
    {
        $missing = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $missing[] = $id;
            }
        }
        return $missing;
    }

    /** @param int[] $ids */
    private static function idList(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }
}
