<?php
declare(strict_types=1);

namespace DRELinkedData\Projection;

use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\Iri;
use DRELinkedData\Rdf\Literal;
use DRELinkedData\Rdf\Serializer\NTriplesSerializer;
use DRELinkedData\Rdf\Triple;
use DRELinkedData\Rdf\Vocab;

/**
 * Projects Omeka resources into clean RDF.
 *
 * What a consumer gets, compared with Omeka's own `?format=turtle`:
 *
 *   • Subjects and links use the module's {@see UriScheme}, not `/api/items/{id}`.
 *   • Omeka bookkeeping (o:owner, o:resource_template, o:is_public, …) is gone;
 *     the resource class, the title as rdfs:label and the property values remain.
 *   • Literals carry their language tag, and Numeric Data Types values become
 *     xsd:gYear / gYearMonth / date / dateTime / integer / duration.
 *   • Authority identifiers also yield owl:sameAs / skos:exactMatch links
 *     ({@see IdentityLinks}); a DOI is published bare, as bibo:doi expects,
 *     with a schema:sameAs link to its https://doi.org/ IRI.
 *   • Resource classes can be replaced (fabio:AuthorityFile → skos:Concept);
 *     a concept gets skos:prefLabel and skos:inScheme its item sets, and an
 *     item with no class at all gets a fallback (schema:CreativeWork).
 *   • List properties (bibo:authorList, bibo:editorList) become real rdf:Lists
 *     on hash IRIs, in Omeka's value order, plus a flat dcterms:creator /
 *     dcterms:contributor for each linked member.
 *   • Coordinates are xsd:decimal.
 *   • Item-set membership is schema:isPartOf, kept apart from dcterms:isPartOf,
 *     which the sync already uses for "parent project".
 *   • Items point at their media with schema:associatedMedia; a media states
 *     the work it encodes, its file URL and its media type.
 *
 * Visibility is enforced by {@see OmekaSource}; this class only ever sees
 * public resources and public values. Value annotations are not projected.
 */
final class ResourceProjector
{
    /**
     * Ingesters whose `source` is a public web address. An upload's source is
     * the original file name and a sideload's a server path; neither is published.
     */
    private const URL_SOURCE_INGESTERS = ['url', 'youtube', 'iiif', 'iiif_presentation', 'oembed'];

    /** @var array<string,true> */
    private readonly array $excludedTerms;

    /** @var array<string,string[]> kind => class IRIs */
    private readonly array $kindClasses;

    /** @var string[] */
    private readonly array $itemFallbackClasses;

    /** @var array<string,string> class IRI => published class IRI */
    private readonly array $classReplacements;

    /** @var array<string,string> list property term => flat predicate IRI */
    private readonly array $listProperties;

    /** @var array<string,true> */
    private readonly array $decimalProperties;

    /**
     * @param array<string,mixed> $config the 'projection' config block
     */
    public function __construct(
        private readonly OmekaSource $source,
        private readonly UriScheme $uris,
        private readonly IdentityLinks $identityLinks,
        array $config,
    ) {
        $this->excludedTerms = array_fill_keys((array) ($config['excluded_terms'] ?? []), true);
        $this->kindClasses = [
            ResourceDescription::ITEM_SET => array_values((array) ($config['item_set_classes'] ?? [])),
            ResourceDescription::MEDIA => array_values((array) ($config['media_classes'] ?? [])),
        ];
        $this->itemFallbackClasses = array_values((array) ($config['item_fallback_classes'] ?? []));
        $this->classReplacements = (array) ($config['class_replacements'] ?? []);
        $this->listProperties = (array) ($config['list_properties'] ?? []);
        $this->decimalProperties = array_fill_keys((array) ($config['decimal_properties'] ?? []), true);
    }

    public function describeOne(int $id): ?ResourceDescription
    {
        return $this->describe([$id])[$id] ?? null;
    }

    /**
     * @param int[] $ids
     * @return array<int,ResourceDescription> visible resources only, keyed and ordered by id
     */
    public function describe(array $ids): array
    {
        $rows = $this->source->resources($ids);
        if (!$rows) {
            return [];
        }

        $itemIds = [];
        $mediaIds = [];
        foreach ($rows as $row) {
            if ($row['resource_type'] === OmekaSource::ITEM) {
                $itemIds[] = (int) $row['id'];
            } elseif ($row['resource_type'] === OmekaSource::MEDIA) {
                $mediaIds[] = (int) $row['id'];
            }
        }

        $values = [];
        $propertyIds = [];
        foreach ($this->source->values(array_map(static fn (array $row): int => (int) $row['id'], $rows)) as $value) {
            $values[(int) $value['resource_id']][] = $value;
            $propertyIds[(int) $value['property_id']] = true;
        }
        $classIds = [];
        foreach ($rows as $row) {
            if ($row['resource_class_id'] !== null) {
                $classIds[(int) $row['resource_class_id']] = true;
            }
        }
        // Only the vocabulary terms this batch uses, not the whole tables.
        $properties = $this->source->properties(array_keys($propertyIds));
        $classes = $this->source->classes(array_keys($classIds));
        $itemSets = [];
        foreach ($this->source->itemSetMemberships($itemIds) as $membership) {
            $itemSets[(int) $membership['item_id']][] = (int) $membership['item_set_id'];
        }
        $itemMedia = [];
        foreach ($this->source->itemMedia($itemIds) as $media) {
            $itemMedia[(int) $media['item_id']][] = (int) $media['id'];
        }
        $media = [];
        foreach ($this->source->media($mediaIds) as $row) {
            $media[(int) $row['id']] = $row;
        }

        $descriptions = [];
        foreach ($rows as $row) {
            $kind = match ($row['resource_type']) {
                OmekaSource::ITEM => ResourceDescription::ITEM,
                OmekaSource::ITEM_SET => ResourceDescription::ITEM_SET,
                OmekaSource::MEDIA => ResourceDescription::MEDIA,
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $id = (int) $row['id'];
            $descriptions[$id] = $this->project(
                $row,
                $kind,
                $values[$id] ?? [],
                $itemSets[$id] ?? [],
                $itemMedia[$id] ?? [],
                $media[$id] ?? null,
                $properties,
                $classes
            );
        }
        return $descriptions;
    }

    /**
     * The resource's triples plus statements about the RDF document that
     * carries them: what it describes, when that last changed, under what
     * licence, and which dataset it belongs to.
     *
     * @return Triple[]
     */
    public function document(ResourceDescription $description, Format $format, ?string $licence = null): array
    {
        $document = new Iri($this->uris->resourceDocument($description->id, $format));
        $triples = $description->triples;
        $triples[] = new Triple($document, Vocab::iri(Vocab::FOAF, 'primaryTopic'), $this->uris->resource($description->id));
        $triples[] = new Triple($document, Vocab::iri(Vocab::VOID, 'inDataset'), $this->uris->dataset());
        $modified = Literal::dateTime($description->modified);
        if ($modified !== null) {
            $triples[] = new Triple($document, Vocab::iri(Vocab::DCTERMS, 'modified'), $modified);
        }
        if ($licence !== null && Iri::isAbsolute($licence)) {
            $triples[] = new Triple($document, Vocab::iri(Vocab::DCTERMS, 'license'), new Iri($licence));
        }
        return $triples;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $values
     * @param int[] $itemSetIds
     * @param int[] $mediaIds
     * @param array<string,mixed>|null $media
     * @param array<int,array{iri:string,term:string}> $properties the batch's properties, by id
     * @param array<int,string> $classes the batch's resource classes, by id
     */
    private function project(
        array $row,
        string $kind,
        array $values,
        array $itemSetIds,
        array $mediaIds,
        ?array $media,
        array $properties,
        array $classes
    ): ResourceDescription {
        $id = (int) $row['id'];
        $subject = $this->uris->resource($id);
        $templateId = $row['resource_template_id'] !== null ? (int) $row['resource_template_id'] : null;

        /** @var array<string,Triple> $triples keyed for de-duplication, in first-seen order */
        $triples = [];
        $addAbout = static function (Iri $about, Iri $predicate, Iri|Literal $object) use (&$triples): bool {
            $key = NTriplesSerializer::iri($about) . ' ' . NTriplesSerializer::iri($predicate) . ' ' . NTriplesSerializer::term($object);
            if (isset($triples[$key])) {
                return false;
            }
            $triples[$key] = new Triple($about, $predicate, $object);
            return true;
        };
        $add = static fn (Iri $predicate, Iri|Literal $object): bool => $addAbout($subject, $predicate, $object);
        $links = [];

        $classIris = [];
        if ($row['resource_class_id'] !== null && isset($classes[(int) $row['resource_class_id']])) {
            $class = $classes[(int) $row['resource_class_id']];
            $classIris[] = $this->classReplacements[$class] ?? $class;
        }
        array_push($classIris, ...($this->kindClasses[$kind] ?? []));
        if (!$classIris && $kind === ResourceDescription::ITEM) {
            $classIris = $this->itemFallbackClasses;
        }
        $type = new Iri(Vocab::RDF_TYPE);
        foreach ($classIris as $class) {
            $add($type, new Iri($class));
        }

        $title = trim((string) ($row['title'] ?? ''));
        if ($title !== '') {
            $add(Vocab::iri(Vocab::RDFS, 'label'), new Literal($title));
        }
        if (in_array(Vocab::SKOS . 'Concept', $classIris, true)) {
            if ($title !== '') {
                $add(Vocab::iri(Vocab::SKOS, 'prefLabel'), new Literal($title));
            }
            foreach ($itemSetIds as $itemSetId) {
                $add(Vocab::iri(Vocab::SKOS, 'inScheme'), $this->uris->resource($itemSetId));
            }
        }

        /** @var array<string,array{predicate:Iri,members:array<int,Iri|Literal>}> $lists by term, in value order */
        $lists = [];

        foreach ($values as $value) {
            $property = $properties[(int) $value['property_id']] ?? null;
            if ($property === null || isset($this->excludedTerms[$property['term']])) {
                continue;
            }
            $term = $property['term'];
            $predicate = new Iri($property['iri']);

            if (isset($this->listProperties[$term])) {
                $member = $this->listMember($value);
                if ($member !== null) {
                    $lists[$term] ??= ['predicate' => $predicate, 'members' => []];
                    $lists[$term]['members'][] = $member;
                }
                continue;
            }

            if ($value['value_resource_id'] !== null) {
                if ((int) $value['target_visible'] === 1) {
                    $add($predicate, $this->uris->resource((int) $value['value_resource_id']));
                }
                continue;
            }

            if ($this->identityLinks->holdsDoi($term)) {
                $doi = $this->identityLinks->doi(trim((string) ($value['uri'] ?? '')) ?: (string) ($value['value'] ?? ''));
                if ($doi !== null) {
                    $add($predicate, new Literal($doi['doi']));
                    if ($add(new Iri($doi['predicate']), new Iri($doi['iri']))) {
                        $links[] = ['authority' => $doi['authority'], 'predicate' => $doi['predicate']];
                    }
                    continue;
                }
            }

            $uri = trim((string) ($value['uri'] ?? ''));
            if ($uri !== '') {
                if (!Iri::isAbsolute($uri)) {
                    $add($predicate, new Literal($uri));
                    continue;
                }
                $add($predicate, new Iri($uri));
                $link = $this->identityLinks->appliesTo($term)
                    ? $this->identityLinks->match($uri, $templateId)
                    : null;
                if ($link !== null && $add(new Iri($link['predicate']), new Iri($link['iri']))) {
                    $links[] = ['authority' => $link['authority'], 'predicate' => $link['predicate']];
                }
                continue;
            }

            $literal = isset($this->decimalProperties[$term])
                ? self::decimal((string) $value['type'], (string) ($value['value'] ?? ''))
                : null;
            $literal ??= self::literal((string) $value['type'], (string) ($value['value'] ?? ''), $value['lang'] ?? null);
            if ($literal !== null) {
                $add($predicate, $literal);
            }
        }

        // Each list hangs off hash IRIs of the resource ({id}#authorList-1, -2, …),
        // so the document that describes the resource also describes its lists.
        foreach ($lists as $term => $list) {
            $local = (string) preg_replace('~^.*[:/#]~', '', $term);
            $node = static fn (int $position): Iri => new Iri($subject->value . '#' . $local . '-' . $position);
            $add($list['predicate'], $node(1));
            $count = count($list['members']);
            foreach ($list['members'] as $index => $member) {
                $addAbout($node($index + 1), new Iri(Vocab::RDF . 'first'), $member);
                $addAbout($node($index + 1), new Iri(Vocab::RDF . 'rest'), $index + 1 < $count ? $node($index + 2) : new Iri(Vocab::RDF . 'nil'));
                if ($member instanceof Iri) {
                    $add(new Iri($this->listProperties[$term]), $member);
                }
            }
        }

        if ($kind === ResourceDescription::ITEM) {
            foreach ($itemSetIds as $itemSetId) {
                $add(Vocab::iri(Vocab::SCHEMA, 'isPartOf'), $this->uris->resource($itemSetId));
            }
            foreach ($mediaIds as $mediaId) {
                $add(Vocab::iri(Vocab::SCHEMA, 'associatedMedia'), $this->uris->resource($mediaId));
            }
        } elseif ($kind === ResourceDescription::MEDIA && $media !== null) {
            $add(Vocab::iri(Vocab::SCHEMA, 'encodesCreativeWork'), $this->uris->resource((int) $media['item_id']));
            $storageId = trim((string) ($media['storage_id'] ?? ''));
            if ((int) $media['has_original'] === 1 && $storageId !== '') {
                $add(Vocab::iri(Vocab::SCHEMA, 'contentUrl'), $this->uris->originalFile($storageId, $media['extension'] ?? null));
            }
            $mediaType = trim((string) ($media['media_type'] ?? ''));
            if ($mediaType !== '') {
                $add(Vocab::iri(Vocab::SCHEMA, 'encodingFormat'), new Literal($mediaType));
            }
            $source = trim((string) ($media['source'] ?? ''));
            if (in_array($media['ingester'] ?? '', self::URL_SOURCE_INGESTERS, true) && preg_match('~^https?://\S+$~i', $source)) {
                $add(Vocab::iri(Vocab::SCHEMA, 'url'), new Iri($source));
            }
        }

        return new ResourceDescription(
            $id,
            $kind,
            $row['modified'] ?? $row['created'] ?? null,
            array_values($triples),
            $links
        );
    }

    /**
     * One member of a list property: the linked resource when it is visible,
     * the URI or the literal otherwise; null for a hidden link or a blank value.
     *
     * @param array<string,mixed> $value
     */
    private function listMember(array $value): Iri|Literal|null
    {
        if ($value['value_resource_id'] !== null) {
            return (int) $value['target_visible'] === 1 ? $this->uris->resource((int) $value['value_resource_id']) : null;
        }
        $uri = trim((string) ($value['uri'] ?? ''));
        if ($uri !== '' && Iri::isAbsolute($uri)) {
            return new Iri($uri);
        }
        return self::literal((string) $value['type'], $uri !== '' ? $uri : (string) ($value['value'] ?? ''), $value['lang'] ?? null);
    }

    /** A plain decimal (`49.92885`, `-19`, `48.0`) as xsd:decimal; null for anything else. */
    public static function decimal(string $type, string $text): ?Literal
    {
        $trimmed = trim($text);
        if ($type !== 'literal' || !preg_match('~^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$~', $trimmed)) {
            return null;
        }
        return new Literal($trimmed, null, Vocab::XSD . 'decimal');
    }

    /**
     * A literal for an Omeka value of the given data type: typed for the
     * Numeric Data Types the sync writes, language-tagged otherwise.
     */
    public static function literal(string $type, string $text, mixed $language = null): ?Literal
    {
        if (trim($text) === '') {
            return null;
        }
        $trimmed = trim($text);
        switch ($type) {
            case 'numeric:timestamp':
                return self::timestamp($trimmed) ?? new Literal($trimmed);
            case 'numeric:integer':
                return preg_match('~^[+-]?\d+$~', $trimmed)
                    ? new Literal($trimmed, null, Vocab::XSD . 'integer')
                    : new Literal($trimmed);
            case 'numeric:duration':
                return preg_match('~^-?P(?!$)(\d+Y)?(\d+M)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+(\.\d+)?S)?)?$~', $trimmed)
                    ? new Literal($trimmed, null, Vocab::XSD . 'duration')
                    : new Literal($trimmed);
        }
        $tag = is_string($language) ? trim($language) : '';
        return new Literal($text, preg_match('~^[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*$~', $tag) ? $tag : null);
    }

    /**
     * Numeric Data Types timestamps keep their precision in the lexical form:
     * `2021`, `2021-05`, `2021-05-03`, `2021-05-03T10`, `…T10:30`, `…T10:30:15`.
     * Each maps to the XSD type of that precision; an hour-or-minute time is
     * padded to the full xsd:dateTime. Anything else stays a plain literal.
     */
    private static function timestamp(string $text): ?Literal
    {
        if (!preg_match('~^(-?\d{4,})(?:-(\d{2})(?:-(\d{2})(?:T(\d{2})(?::(\d{2})(?::(\d{2}))?)?)?)?)?$~', $text, $m)) {
            return null;
        }
        $month = (int) ($m[2] ?? 1);
        $day = (int) ($m[3] ?? 1);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31
            || (int) ($m[4] ?? 0) > 23 || (int) ($m[5] ?? 0) > 59 || (int) ($m[6] ?? 0) > 59
        ) {
            return null;
        }
        return match (count($m)) {
            2 => new Literal($text, null, Vocab::XSD . 'gYear'),
            3 => new Literal($text, null, Vocab::XSD . 'gYearMonth'),
            4 => new Literal($text, null, Vocab::XSD . 'date'),
            default => new Literal(
                sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5] ?? '00', $m[6] ?? '00'),
                null,
                Vocab::XSD . 'dateTime'
            ),
        };
    }
}
