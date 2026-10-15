<?php
declare(strict_types=1);

namespace DRELinkedData\Projection;

use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\Iri;

/**
 * Every IRI the module mints, in one place.
 *
 *   {base}/resource/{id}           the thing (item, item set or media) — 303s
 *   {base}/resource/{id}.{ext}     the RDF document describing it
 *   {base}/s/{slug}/item/{id}      its record page on the default site (also
 *                                  item-set/{id}, media/{id}): foaf:isPrimaryTopicOf
 *   {base}/linked-data/void        the VoID/DCAT description (+ .ttl/.nt/.jsonld/.rdf)
 *   {base}/linked-data/void#dataset   the dataset itself
 *   {base}/linked-data/dump.nt.gz  the full N-Triples dump
 *
 * Resource ids are Omeka's: the sync never deletes or renumbers items, so they
 * are stable, and the site slug is kept out of the identifier on purpose — a
 * renamed site must not change what every resource is called. Record pages do
 * carry the slug; they are pages, not identifiers. They are built on the same
 * base, which assumes Omeka is served at the root of the base URI (as AMIRA is).
 */
final class UriScheme
{
    private readonly string $base;

    private ?string $slug = null;
    private bool $slugResolved = false;

    /**
     * @param (\Closure():?string)|null $siteSlug the default site's slug,
     *        resolved on first use (a 404 never pays for the lookup); null
     *        when record pages are unknown
     */
    public function __construct(string $base, private readonly ?\Closure $siteSlug = null)
    {
        $this->base = rtrim(trim($base), '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    public function resource(int $id): Iri
    {
        return new Iri($this->base . '/resource/' . $id);
    }

    public function resourceDocument(int $id, Format $format): string
    {
        return $this->base . '/resource/' . $id . '.' . $format->value;
    }

    public function resourceSpace(): string
    {
        return $this->base . '/resource/';
    }

    /**
     * The record page of a resource of the given kind on the default site,
     * or null when there is no site.
     *
     * @param string $kind one of the ResourceDescription kinds
     */
    public function page(int $id, string $kind): ?string
    {
        $slug = $this->siteSlug();
        if ($slug === null || $slug === '') {
            return null;
        }
        return $this->base . '/s/' . rawurlencode($slug) . '/' . ResourceDescription::siteControllerFor($kind) . '/' . $id;
    }

    /** A site page (`/s/{site}/page/{page}`). */
    public function sitePage(string $siteSlug, string $pageSlug): string
    {
        return $this->base . '/s/' . rawurlencode($siteSlug) . '/page/' . rawurlencode($pageSlug);
    }

    public function siteSlug(): ?string
    {
        if (!$this->slugResolved) {
            $this->slugResolved = true;
            $this->slug = $this->siteSlug !== null ? ($this->siteSlug)() : null;
        }
        return $this->slug;
    }

    public function voidDocument(?Format $format = null): string
    {
        return $this->base . '/linked-data/void' . ($format ? '.' . $format->value : '');
    }

    public function dataset(): Iri
    {
        return new Iri($this->voidDocument() . '#dataset');
    }

    public function distribution(): Iri
    {
        return new Iri($this->voidDocument() . '#dump');
    }

    /** The dump's SHA-256 checksum node (spdx:Checksum). */
    public function distributionChecksum(): Iri
    {
        return new Iri($this->voidDocument() . '#dump-sha256');
    }

    /** The dataset's contact point (vcard:Kind). */
    public function contactPoint(): Iri
    {
        return new Iri($this->voidDocument() . '#contact');
    }

    public function linkset(string $authority, string $predicate): Iri
    {
        $local = preg_replace('~^.*[/#]~', '', $predicate) ?: 'link';
        return new Iri($this->voidDocument() . '#links-' . rawurlencode($authority) . '-' . rawurlencode($local));
    }

    /** The node standing for another dataset our linksets point into. */
    public function linkTarget(string $authority): Iri
    {
        return new Iri($this->voidDocument() . '#dataset-' . rawurlencode($authority));
    }

    public function dump(): string
    {
        return $this->base . '/linked-data/dump.nt.gz';
    }

    public function originalFile(string $storageId, ?string $extension): Iri
    {
        $extension = trim((string) $extension);
        return new Iri($this->base . '/files/original/' . rawurlencode($storageId)
            . ($extension !== '' ? '.' . rawurlencode($extension) : ''));
    }
}
