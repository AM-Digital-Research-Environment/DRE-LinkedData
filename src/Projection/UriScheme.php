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
 *   {base}/linked-data/void        the VoID/DCAT description (+ .ttl/.nt/.jsonld)
 *   {base}/linked-data/void#dataset   the dataset itself
 *   {base}/linked-data/dump.nt.gz  the full N-Triples dump
 *
 * Resource ids are Omeka's: the sync never deletes or renumbers items, so they
 * are stable, and the site slug is kept out of the identifier on purpose — a
 * renamed site must not change what every resource is called.
 */
final class UriScheme
{
    private readonly string $base;

    public function __construct(string $base)
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
