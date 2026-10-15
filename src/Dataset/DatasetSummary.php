<?php
declare(strict_types=1);

namespace DRELinkedData\Dataset;

use DRELinkedData\Dump\ChangeTracker;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Projection\IdentityLinks;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Settings\DatasetSettings;

/**
 * The plain-array view model behind the public page block and the admin
 * status panel: what the dataset is, what the last dump contains, whether it
 * is out of date, and where every machine-readable entry point lives.
 * Templates only format it. {@see dataset()} is the same information in the
 * shape the dreLinkedData()->dataset() helper contract promises.
 */
final class DatasetSummary
{
    /**
     * @param \Closure():PrefixMap $prefixes
     * @param (\Closure(int):bool)|null $isVisible whether a resource id is public
     */
    public function __construct(
        private readonly DumpStore $store,
        private readonly UriScheme $uris,
        private readonly IdentityLinks $identityLinks,
        private readonly \Closure $prefixes,
        private readonly ?\Closure $isVisible = null,
        private readonly ?ChangeTracker $changes = null,
    ) {
    }

    /**
     * The dataset as the view helper contract describes it (README, "For the
     * theme and DRE SEO").
     *
     * @return array<string,mixed>
     */
    public function dataset(DatasetSettings $settings, ?string $landingPage = null): array
    {
        $state = $this->store->state();
        $distributions = [];
        if ($state !== null) {
            $distributions[] = [
                'label' => 'N-Triples dump (gzip)',
                'url' => $this->uris->dump(),
                'type' => Format::NTriples->mediaType(),
                'encoding' => 'application/gzip',
                'bytes' => isset($state['bytes']) ? (int) $state['bytes'] : null,
            ];
        }
        return [
            'uri' => $this->uris->dataset()->value,
            'void' => $this->uris->voidDocument(),
            'landingPage' => $landingPage,
            'title' => $settings->title,
            'description' => $settings->description,
            'licence' => $settings->licence,
            'publisher' => ['name' => $settings->publisherName, 'uri' => $settings->publisherUri],
            'modified' => is_string($state['generated_at'] ?? null) ? $state['generated_at'] : null,
            'triples' => isset($state['triples']) ? (int) $state['triples'] : null,
            'entities' => isset($state['resources']) ? (int) array_sum((array) $state['resources']) : null,
            'distributions' => $distributions,
        ];
    }

    /**
     * @param int|null $exampleId a resource to show as the worked example;
     *                            defaults to the dump's richest item. One that
     *                            is not public is ignored: its documents 404.
     * @return array<string,mixed>
     */
    public function build(DatasetSettings $settings, ?int $exampleId = null): array
    {
        $state = $this->store->state();
        if ($exampleId !== null && $this->isVisible !== null && !($this->isVisible)($exampleId)) {
            $exampleId = null;
        }

        $documents = static fn (callable $url): array => array_map(
            static fn (Format $format): array => [
                'label' => $format->label(),
                'type' => $format->mediaType(),
                'url' => $url($format),
            ],
            Format::cases()
        );

        $exampleId ??= isset($state['example_resource']) ? (int) $state['example_resource'] : null;
        $example = null;
        if ($exampleId) {
            $example = [
                'id' => $exampleId,
                'uri' => $this->uris->resource($exampleId)->value,
                'documents' => $documents(fn (Format $format): string => $this->uris->resourceDocument($exampleId, $format)),
            ];
        }

        $authorities = $this->identityLinks->authorities();
        $prefixes = ($this->prefixes)();
        $linksets = [];
        foreach ((array) ($state['linksets'] ?? []) as $linkset) {
            $authority = (string) ($linkset['authority'] ?? '');
            $predicate = (string) ($linkset['predicate'] ?? '');
            $compact = $prefixes->compact($predicate);
            $linksets[] = [
                'label' => (string) ($authorities[$authority]['label'] ?? $authority),
                'predicate' => $compact ? $compact[0] . ':' . $compact[1] : $predicate,
                'triples' => (int) ($linkset['triples'] ?? 0),
            ];
        }

        $vocabularies = [];
        $byNamespace = array_flip($prefixes->all());
        foreach ((array) ($state['vocabularies'] ?? []) as $namespace) {
            $vocabularies[] = ['prefix' => (string) ($byNamespace[$namespace] ?? ''), 'namespace' => (string) $namespace];
        }

        $resources = (array) ($state['resources'] ?? []);
        return [
            'title' => $settings->title,
            'description' => $settings->description,
            'publisherName' => $settings->publisherName,
            'publisherUri' => $settings->publisherUri,
            'licence' => $settings->licence,
            'licenceLabel' => $settings->licence !== null ? self::licenceLabel($settings->licence) : null,
            'available' => $state !== null,
            'generatedAt' => $state['generated_at'] ?? null,
            'triples' => isset($state['triples']) ? (int) $state['triples'] : null,
            'resources' => [
                'items' => (int) ($resources['items'] ?? 0),
                'itemSets' => (int) ($resources['item_sets'] ?? 0),
                'media' => (int) ($resources['media'] ?? 0),
            ],
            'bytes' => isset($state['bytes']) ? (int) $state['bytes'] : null,
            'sha256' => isset($state['sha256']) ? (string) $state['sha256'] : null,
            'dumpUrl' => $this->uris->dump(),
            'void' => $documents(fn (Format $format): string => $this->uris->voidDocument($format)),
            'voidUrl' => $this->uris->voidDocument(),
            'uriPattern' => $this->uris->resourceSpace() . '{id}',
            'example' => $example,
            'linksets' => $linksets,
            'vocabularies' => $vocabularies,
            // ['since' => ISO date, 'count' => n] when the data changed after
            // the dump was taken; null when it is up to date (or never made).
            'changes' => $state !== null ? $this->changes?->pending($state) : null,
        ];
    }

    /**
     * "CC0 1.0", "CC BY 4.0", … for Creative Commons licence URIs; anything
     * else is shown as its URI.
     */
    public static function licenceLabel(string $uri): string
    {
        if (preg_match('~^https?://creativecommons\.org/publicdomain/zero/([\d.]+)/?$~i', $uri, $match)) {
            return 'CC0 ' . $match[1];
        }
        if (preg_match('~^https?://creativecommons\.org/publicdomain/mark/([\d.]+)/?$~i', $uri, $match)) {
            return 'Public Domain Mark ' . $match[1];
        }
        if (preg_match('~^https?://creativecommons\.org/licenses/([a-z-]+)/([\d.]+)/?~i', $uri, $match)) {
            return 'CC ' . strtoupper($match[1]) . ' ' . $match[2];
        }
        return $uri;
    }
}
