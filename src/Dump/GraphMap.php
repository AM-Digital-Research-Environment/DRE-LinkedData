<?php
declare(strict_types=1);

namespace DRELinkedData\Dump;

use DRELinkedData\Projection\ResourceDescription;

/**
 * Which named graph of the N-Quads dump a resource goes into: one graph per
 * kind of record (research items, publications, persons, …).
 *
 * The kind is read from Omeka's structure, not from the RDF: media and item
 * sets by their resource type, items by their resource template first and
 * then by their item sets (publications are spread over a growing list of
 * templates, but all sit in one item set). The first graph that matches
 * wins; an item none matches goes to the default graph name. Each resource,
 * with every triple it is the subject of, lands in exactly one graph, so the
 * union of the graphs repeats no triple.
 *
 * Graph names are the dataset's subsets in the VoID description
 * (`/linked-data/void#graph-{key}`): a SPARQL client that asks for a graph
 * by name can dereference the name to read what it holds.
 */
final class GraphMap
{
    /** @var array<string,array{title:string,kind:?string,templates:array<int,true>,item_sets:array<int,true>}> */
    private readonly array $graphs;

    private readonly string $default;

    /**
     * @param array<string,mixed> $config the dump's 'graphs' block: 'default'
     *        and 'list', key => title, kind (item_set|media), templates, item_sets
     */
    public function __construct(array $config)
    {
        $graphs = [];
        foreach ((array) ($config['list'] ?? []) as $key => $graph) {
            $key = (string) $key;
            if (!preg_match('~^[a-z0-9][a-z0-9-]*$~', $key) || !is_array($graph)) {
                continue;
            }
            $graphs[$key] = [
                'title' => (string) ($graph['title'] ?? $key),
                'kind' => isset($graph['kind']) ? (string) $graph['kind'] : null,
                'templates' => array_fill_keys(array_map('intval', (array) ($graph['templates'] ?? [])), true),
                'item_sets' => array_fill_keys(array_map('intval', (array) ($graph['item_sets'] ?? [])), true),
            ];
        }
        $default = (string) ($config['default'] ?? 'other');
        $graphs[$default] ??= ['title' => 'Other records', 'kind' => null, 'templates' => [], 'item_sets' => []];
        $this->graphs = $graphs;
        $this->default = $default;
    }

    /** The key of the graph the resource belongs in. */
    public function graphFor(ResourceDescription $description): string
    {
        if ($description->kind !== ResourceDescription::ITEM) {
            foreach ($this->graphs as $key => $graph) {
                if ($graph['kind'] === $description->kind) {
                    return $key;
                }
            }
            return $this->default;
        }
        if ($description->templateId !== null) {
            foreach ($this->graphs as $key => $graph) {
                if (isset($graph['templates'][$description->templateId])) {
                    return $key;
                }
            }
        }
        foreach ($this->graphs as $key => $graph) {
            foreach ($description->itemSetIds as $itemSetId) {
                if (isset($graph['item_sets'][$itemSetId])) {
                    return $key;
                }
            }
        }
        return $this->default;
    }

    /** @return array<string,string> every graph key => its title, in configured order */
    public function titles(): array
    {
        return array_map(static fn (array $graph): string => $graph['title'], $this->graphs);
    }
}
