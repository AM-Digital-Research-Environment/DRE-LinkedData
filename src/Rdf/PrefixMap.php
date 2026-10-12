<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

/**
 * Prefix ↔ namespace table for the Turtle and JSON-LD serialisers.
 *
 * The module's fixed prefixes ({@see Vocab::PREFIXES}) come first, then
 * Omeka's vocabulary table. A prefix already bound to a different namespace is
 * not rebound, and a namespace already reachable through a prefix is not given
 * a second one, so the output never declares two names for one thing.
 */
final class PrefixMap
{
    /** @var array<string,string> prefix => namespace */
    private array $prefixes = [];

    /** @param array<string,string> $prefixes prefix => namespace */
    public function __construct(array $prefixes = Vocab::PREFIXES)
    {
        foreach ($prefixes as $prefix => $namespace) {
            $this->add((string) $prefix, (string) $namespace);
        }
    }

    /**
     * @param array<int,array{prefix:string,namespace_uri:string}> $rows Omeka's vocabulary table
     */
    public static function withVocabularies(array $rows): self
    {
        $map = new self();
        foreach ($rows as $row) {
            $map->add((string) $row['prefix'], (string) $row['namespace_uri']);
        }
        return $map;
    }

    public function add(string $prefix, string $namespace): void
    {
        if (!preg_match('~^[A-Za-z][A-Za-z0-9_-]*$~', $prefix) || $namespace === '') {
            return;
        }
        if (isset($this->prefixes[$prefix]) || in_array($namespace, $this->prefixes, true)) {
            return;
        }
        $this->prefixes[$prefix] = $namespace;
    }

    /** @return array<string,string> prefix => namespace */
    public function all(): array
    {
        return $this->prefixes;
    }

    /**
     * Split an IRI into [prefix, local name] using the longest matching
     * namespace whose remainder is a safe local name in both Turtle and JSON-LD.
     *
     * JSON-LD 1.1 only treats a term as a prefix when its IRI ends in a
     * gen-delim, so `$jsonLd` additionally requires a namespace ending in `/`
     * or `#` — otherwise the compact form would not expand back to the IRI.
     *
     * @return array{0:string,1:string}|null
     */
    public function compact(string $iri, bool $jsonLd = false): ?array
    {
        $best = null;
        $bestLength = 0;
        foreach ($this->prefixes as $prefix => $namespace) {
            $length = strlen($namespace);
            if ($length <= $bestLength || !str_starts_with($iri, $namespace)) {
                continue;
            }
            if ($jsonLd && !in_array(substr($namespace, -1), ['/', '#'], true)) {
                continue;
            }
            $local = substr($iri, $length);
            if ($local !== '' && !preg_match('~^[A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_-])?$~', $local)) {
                continue;
            }
            $best = [$prefix, $local];
            $bestLength = $length;
        }
        return $best;
    }

    /** The namespace an IRI belongs to, or null when no prefix covers it. */
    public function namespaceOf(string $iri): ?string
    {
        $compact = $this->compact($iri);
        return $compact === null ? null : $this->prefixes[$compact[0]];
    }
}
