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
     * The literal is the DOI itself, percent-decoded (`%3C` → `<`): a DOI may
     * legitimately contain `#`, `?` or `%`. The IRI encodes those three again,
     * so a decoded `%23` never turns the rest of the DOI into a fragment. In a
     * resolver URL an unencoded `?` or `#` starts the query or fragment, which
     * is not part of the DOI and is dropped.
     *
     * @return array{doi:string,authority:string,iri:string,predicate:string}|null
     */
    public function doi(string $value): ?array
    {
        if (!preg_match('~^(?:(https?://(?:dx\.)?doi\.org/)|doi:\s*)?(10\.[0-9]{4,9}/\S+)$~i', trim($value), $match)) {
            return null;
        }
        $encoded = $match[2];
        if ($match[1] !== '') {
            $encoded = (string) preg_replace('~[?#].*$~s', '', $encoded);
            if (!preg_match('~^10\.[0-9]{4,9}/.~', $encoded)) {
                return null;
            }
        }
        $doi = rawurldecode($encoded);
        return [
            'doi' => $doi,
            'authority' => 'doi',
            'iri' => 'https://doi.org/' . strtr($doi, ['%' => '%25', '#' => '%23', '?' => '%3F']),
            'predicate' => (string) ($this->config['doi']['predicate'] ?? 'https://schema.org/sameAs'),
        ];
    }

    /**
     * The identity link one value yields, exactly as the projection states it:
     * a DOI property links its https://doi.org/ IRI, an identifier property's
     * URI links its authority's canonical IRI. Null for anything else.
     *
     * @return array{authority:string,iri:string,predicate:string}|null
     */
    public function linkFor(string $term, ?string $uri, ?string $text, ?int $templateId): ?array
    {
        $uri = trim((string) $uri);
        if ($this->holdsDoi($term)) {
            $doi = $this->doi($uri !== '' ? $uri : (string) $text);
            if ($doi !== null) {
                unset($doi['doi']);
                return $doi;
            }
        }
        if ($uri !== '' && $this->appliesTo($term)) {
            return $this->match($uri, $templateId);
        }
        return null;
    }

    /** @return string[] every term an identity link can come from */
    public function terms(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->properties),
            array_map('strval', (array) ($this->config['doi']['properties'] ?? []))
        )));
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
