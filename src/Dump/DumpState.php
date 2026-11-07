<?php
declare(strict_types=1);

namespace DRELinkedData\Dump;

/**
 * What the last successful dump run produced: `state.json`, typed.
 *
 * {@see DumpGenerator} builds one, {@see DumpStore} writes it next to the dump
 * and reads it back, and everything that describes the dump (the VoID, the
 * page block, the admin panel, the change tracker, the dump route's ETag)
 * reads these properties instead of picking through an array. Reading is
 * lenient: a field that is missing or of the wrong type in the file (an older
 * version, a hand edit) takes its empty value rather than failing the page.
 */
final class DumpState
{
    /** Bumped when the file's shape changes. */
    public const VERSION = 2;

    /**
     * @param string[] $vocabularies namespaces of the predicates and classes used
     * @param list<array{authority:string,predicate:string,triples:int}> $linksets
     */
    public function __construct(
        /** When the run finished (ISO 8601, UTC): the dump's dcterms:modified. */
        public readonly ?string $generatedAt,
        /** When the run started: changes after this may be missing from the dump. */
        public readonly ?string $startedAt = null,
        /** When the first dump was published: the dataset's dcterms:issued. */
        public readonly ?string $firstGeneratedAt = null,
        public readonly float $durationSeconds = 0.0,
        public readonly int $triples = 0,
        public readonly int $items = 0,
        public readonly int $itemSets = 0,
        public readonly int $media = 0,
        public readonly ?int $bytes = null,
        public readonly ?string $sha256 = null,
        public readonly array $vocabularies = [],
        public readonly array $linksets = [],
        /** The richest item, the VoID's void:exampleResource. */
        public readonly ?int $exampleResource = null,
    ) {
    }

    /** @param array<string,mixed> $data the decoded state.json */
    public static function fromArray(array $data): self
    {
        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $int = static fn (mixed $value): ?int => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
        $resources = is_array($data['resources'] ?? null) ? $data['resources'] : [];

        $linksets = [];
        foreach (is_array($data['linksets'] ?? null) ? $data['linksets'] : [] as $linkset) {
            if (is_array($linkset)) {
                $linksets[] = [
                    'authority' => (string) ($linkset['authority'] ?? ''),
                    'predicate' => (string) ($linkset['predicate'] ?? ''),
                    'triples' => (int) ($linkset['triples'] ?? 0),
                ];
            }
        }
        $example = $int($data['example_resource'] ?? null);

        return new self(
            $string('generated_at'),
            $string('started_at'),
            $string('first_generated_at'),
            is_numeric($data['duration_seconds'] ?? null) ? (float) $data['duration_seconds'] : 0.0,
            (int) ($int($data['triples'] ?? null) ?? 0),
            (int) ($int($resources['items'] ?? null) ?? 0),
            (int) ($int($resources['item_sets'] ?? null) ?? 0),
            (int) ($int($resources['media'] ?? null) ?? 0),
            $int($data['bytes'] ?? null),
            $string('sha256'),
            array_values(array_filter(is_array($data['vocabularies'] ?? null) ? $data['vocabularies'] : [], 'is_string')),
            $linksets,
            $example !== null && $example > 0 ? $example : null,
        );
    }

    /** @return array<string,mixed> the state.json shape */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'started_at' => $this->startedAt,
            'generated_at' => $this->generatedAt,
            'first_generated_at' => $this->firstGeneratedAt,
            'duration_seconds' => $this->durationSeconds,
            'triples' => $this->triples,
            'resources' => $this->resources(),
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
            'vocabularies' => $this->vocabularies,
            'linksets' => $this->linksets,
            'example_resource' => $this->exampleResource,
        ];
    }

    /** @return array{items:int,item_sets:int,media:int} */
    public function resources(): array
    {
        return ['items' => $this->items, 'item_sets' => $this->itemSets, 'media' => $this->media];
    }

    /** Every resource in the dump: the VoID's void:entities. */
    public function entities(): int
    {
        return $this->items + $this->itemSets + $this->media;
    }

    /**
     * The point after which a change may be missing from the dump: the run's
     * start, or its end for a state that does not record the start.
     */
    public function since(): ?string
    {
        return $this->startedAt ?? $this->generatedAt;
    }
}
