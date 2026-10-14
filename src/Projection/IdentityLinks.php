<?php
declare(strict_types=1);

namespace DRELinkedData\Projection;

/**
 * Turns authority identifiers into identity links.
 *
 * The sync stores Wikidata, GND and Library of Congress URIs as
 * `dcterms:identifier` values. Generic Linked Data clients do not follow
 * dcterms:identifier, so a matching URI also yields an owl:sameAs (entities:
 * persons, places, organisations, journals) or skos:exactMatch (concepts in the
 * authority template) to its canonical form — `https://www.wikidata.org/wiki/Q…`
 * page URLs become `http://www.wikidata.org/entity/Q…`.
 *
 * Only configured authorities qualify. Authority items also carry YouTube
 * playlist and GEPRIS URLs under dcterms:identifier, and those are not the
 * same thing as the concept, so an allowlist rather than "any URI" is the rule.
 *
 * DOIs are handled apart ({@see doi()}): they sit in their own property
 * (bibo:doi), identify the work's published version rather than the record,
 * and link with schema:sameAs.
 */
final class IdentityLinks
{
    /** @var array<string,true> */
    private readonly array $properties;

    /**
     * @param array<string,mixed> $config the 'projection.identity_links' block
     */
    public function __construct(private readonly array $config)
    {
        $this->properties = array_fill_keys((array) ($config['properties'] ?? []), true);
    }

    public function appliesTo(string $term): bool
    {
        return isset($this->properties[$term]);
    }

    /**
     * @return array{authority:string,iri:string,predicate:string}|null
     */
    public function match(string $uri, ?int $templateId): ?array
    {
        foreach ((array) ($this->config['authorities'] ?? []) as $authority => $definition) {
            $pattern = (string) ($definition['pattern'] ?? '');
            if ($pattern === '' || !preg_match($pattern, trim($uri))) {
                continue;
            }
            $canonical = (string) preg_replace($pattern, (string) ($definition['canonical'] ?? '$0'), trim($uri));
            return [
                'authority' => (string) $authority,
                'iri' => $canonical,
                'predicate' => $this->predicateFor($templateId),
            ];
        }
        return null;
    }

    public function holdsDoi(string $term): bool
    {
        return in_array($term, (array) ($this->config['doi']['properties'] ?? []), true);
    }

    /**
     * A DOI stored bare (`10.1017/…`), with a `doi:` prefix or as a resolver
     * URL (`https://doi.org/…`, `http://dx.doi.org/…`), as the bare DOI and its
     * link to the https://doi.org/ IRI.
     *
     * @return array{doi:string,authority:string,iri:string,predicate:string}|null
     */
    public function doi(string $value): ?array
    {
        if (!preg_match('~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)?(10\.[0-9]{4,9}/\S+)$~i', trim($value), $match)) {
            return null;
        }
        $doi = rawurldecode($match[1]);
        return [
            'doi' => $doi,
            'authority' => 'doi',
            'iri' => 'https://doi.org/' . $doi,
            'predicate' => (string) ($this->config['doi']['predicate'] ?? 'https://schema.org/sameAs'),
        ];
    }

    /**
     * The link targets, for VoID linksets and the page block: the configured
     * authorities, plus `doi` when DOI properties are configured.
     *
     * @return array<string,array<string,mixed>> authority => definition
     */
    public function authorities(): array
    {
        $authorities = (array) ($this->config['authorities'] ?? []);
        if (!empty($this->config['doi']['properties'])) {
            $authorities['doi'] = (array) $this->config['doi'];
        }
        return $authorities;
    }

    private function predicateFor(?int $templateId): string
    {
        $byTemplate = (array) ($this->config['template_predicates'] ?? []);
        if ($templateId !== null && isset($byTemplate[$templateId])) {
            return (string) $byTemplate[$templateId];
        }
        return (string) ($this->config['default_predicate'] ?? 'http://www.w3.org/2002/07/owl#sameAs');
    }
}
