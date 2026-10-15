<?php
declare(strict_types=1);

namespace DRELinkedData\Dataset;

use DRELinkedData\Projection\IdentityLinks;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\Iri;
use DRELinkedData\Rdf\Literal;
use DRELinkedData\Rdf\Triple;
use DRELinkedData\Rdf\Vocab;
use DRELinkedData\Settings\DatasetSettings;

/**
 * The VoID + DCAT description served at /linked-data/void.
 *
 * VoID is what Linked Data tooling reads (URI space, dump, vocabularies,
 * linksets to other datasets); DCAT is what data catalogues harvest (title,
 * publisher, licence, a distribution with media type and size). Both describe
 * the same dataset node, so one document serves both audiences.
 *
 * Title, description and keywords are tagged `@en`: the editorial settings
 * are written in English. A contact point (a vCard with the configured email)
 * is stated only when an address is configured; dcterms:issued is the first
 * dump ever published, and the distribution carries its SHA-256 as an
 * spdx:Checksum, as DCAT 3 recommends.
 *
 * The figures come from the last dump run's state, so they describe exactly
 * the file a client will download. Before the first run the description still
 * says what the dataset is and where its resources live, just without counts
 * or a distribution.
 */
final class DatasetDescription
{
    /** @param string[] $keywords dcat:keyword values */
    public function __construct(
        private readonly UriScheme $uris,
        private readonly IdentityLinks $identityLinks,
        private readonly array $keywords = [],
    ) {
    }

    /**
     * @param array<string,mixed>|null $state the dump state ({@see \DRELinkedData\Dump\DumpStore::state()})
     * @param Format|null $format the document's own format, or null when it
     *                            was served at the negotiated, extension-less URL
     * @param string|null $landingPage the page carrying the Linked open data
     *                                 block ({@see LandingPage}); the homepage
     *                                 stands in when there is none
     * @return Triple[]
     */
    public function triples(DatasetSettings $settings, ?array $state, ?string $homepage, ?Format $format, ?string $landingPage = null): array
    {
        $triples = [];
        $add = static function (Iri $subject, string $namespace, string $local, Iri|Literal $object) use (&$triples): void {
            $triples[] = new Triple($subject, Vocab::iri($namespace, $local), $object);
        };
        $dataset = $this->uris->dataset();
        $integer = static fn (int $value): Literal => new Literal((string) $value, null, Vocab::XSD . 'integer');

        $add(new Iri($this->uris->voidDocument($format)), Vocab::FOAF, 'primaryTopic', $dataset);

        $add($dataset, Vocab::RDF, 'type', Vocab::iri(Vocab::VOID, 'Dataset'));
        $add($dataset, Vocab::RDF, 'type', Vocab::iri(Vocab::DCAT, 'Dataset'));
        if ($settings->title !== '') {
            $add($dataset, Vocab::DCTERMS, 'title', new Literal($settings->title, 'en'));
        }
        if ($settings->description !== '') {
            $add($dataset, Vocab::DCTERMS, 'description', new Literal($settings->description, 'en'));
        }
        foreach ($this->keywords as $keyword) {
            if (is_string($keyword) && trim($keyword) !== '') {
                $add($dataset, Vocab::DCAT, 'keyword', new Literal(trim($keyword), 'en'));
            }
        }
        if ($settings->contactEmail !== null) {
            $contact = $this->uris->contactPoint();
            $add($dataset, Vocab::DCAT, 'contactPoint', $contact);
            $add($contact, Vocab::RDF, 'type', Vocab::iri(Vocab::VCARD, 'Kind'));
            if ($settings->publisherName !== '') {
                $add($contact, Vocab::VCARD, 'fn', new Literal($settings->publisherName));
            }
            $add($contact, Vocab::VCARD, 'hasEmail', new Iri('mailto:' . $settings->contactEmail));
        }

        // dcterms:publisher wants an agent, not a string: a configured IRI, or
        // a minted one when only a name is known.
        $publisher = $settings->publisherUri !== null
            ? new Iri($settings->publisherUri)
            : ($settings->publisherName !== '' ? new Iri($this->uris->voidDocument() . '#publisher') : null);
        if ($publisher !== null) {
            $add($dataset, Vocab::DCTERMS, 'publisher', $publisher);
            $add($publisher, Vocab::RDF, 'type', Vocab::iri(Vocab::FOAF, 'Agent'));
            if ($settings->publisherName !== '') {
                $add($publisher, Vocab::FOAF, 'name', new Literal($settings->publisherName));
            }
        }
        if ($settings->licence !== null) {
            $add($dataset, Vocab::DCTERMS, 'license', new Iri($settings->licence));
        }
        if ($homepage !== null && $homepage !== '') {
            $add($dataset, Vocab::FOAF, 'homepage', new Iri($homepage));
        }
        // The dataset's own page, where its figures, dump and URIs are
        // explained, is what a catalogue should link to, not the site's home.
        $landing = $landingPage !== null && $landingPage !== '' ? $landingPage : $homepage;
        if ($landing !== null && $landing !== '') {
            $add($dataset, Vocab::DCAT, 'landingPage', new Iri($landing));
        }

        $add($dataset, Vocab::VOID, 'uriSpace', new Literal($this->uris->resourceSpace()));
        foreach (Format::cases() as $served) {
            $add($dataset, Vocab::VOID, 'feature', new Iri($served->formatIri()));
        }

        if ($state === null) {
            return $triples;
        }

        $modified = Literal::dateTime(is_string($state['generated_at'] ?? null) ? $state['generated_at'] : null);
        $issued = Literal::dateTime(is_string($state['first_generated_at'] ?? null) ? $state['first_generated_at'] : null);
        if ($issued !== null) {
            $add($dataset, Vocab::DCTERMS, 'issued', $issued);
        }
        if ($modified !== null) {
            $add($dataset, Vocab::DCTERMS, 'modified', $modified);
        }
        $add($dataset, Vocab::VOID, 'triples', $integer((int) ($state['triples'] ?? 0)));
        $add($dataset, Vocab::VOID, 'entities', $integer((int) array_sum((array) ($state['resources'] ?? []))));
        $add($dataset, Vocab::VOID, 'dataDump', new Iri($this->uris->dump()));
        foreach ((array) ($state['vocabularies'] ?? []) as $namespace) {
            if (is_string($namespace) && Iri::isAbsolute($namespace)) {
                $add($dataset, Vocab::VOID, 'vocabulary', new Iri($namespace));
            }
        }
        if (!empty($state['example_resource'])) {
            $add($dataset, Vocab::VOID, 'exampleResource', $this->uris->resource((int) $state['example_resource']));
        }

        $distribution = $this->uris->distribution();
        $add($dataset, Vocab::DCAT, 'distribution', $distribution);
        $add($distribution, Vocab::RDF, 'type', Vocab::iri(Vocab::DCAT, 'Distribution'));
        $add($distribution, Vocab::DCTERMS, 'title', new Literal('N-Triples dump (gzip)', 'en'));
        $add($distribution, Vocab::DCAT, 'accessURL', new Iri($this->uris->dump()));
        $add($distribution, Vocab::DCAT, 'downloadURL', new Iri($this->uris->dump()));
        $add($distribution, Vocab::DCAT, 'mediaType', new Iri(Vocab::IANA_MEDIA . Format::NTriples->mediaType()));
        $add($distribution, Vocab::DCAT, 'compressFormat', new Iri(Vocab::IANA_MEDIA . 'application/gzip'));
        if (isset($state['bytes'])) {
            $add($distribution, Vocab::DCAT, 'byteSize', new Literal((string) (int) $state['bytes'], null, Vocab::XSD . 'nonNegativeInteger'));
        }
        $sha256 = strtolower((string) ($state['sha256'] ?? ''));
        if (preg_match('~^[0-9a-f]{64}$~', $sha256)) {
            $checksum = $this->uris->distributionChecksum();
            $add($distribution, Vocab::SPDX, 'checksum', $checksum);
            $add($checksum, Vocab::RDF, 'type', Vocab::iri(Vocab::SPDX, 'Checksum'));
            $add($checksum, Vocab::SPDX, 'algorithm', Vocab::iri(Vocab::SPDX, 'checksumAlgorithm_sha256'));
            $add($checksum, Vocab::SPDX, 'checksumValue', new Literal($sha256, null, Vocab::XSD . 'hexBinary'));
        }
        if ($modified !== null) {
            $add($distribution, Vocab::DCTERMS, 'modified', $modified);
        }
        if ($settings->licence !== null) {
            $add($distribution, Vocab::DCTERMS, 'license', new Iri($settings->licence));
        }

        // A linkset's objectsTarget is a dataset, not a website: each authority
        // gets one void:Dataset node, described by its homepage and URI space.
        $authorities = $this->identityLinks->authorities();
        $targets = [];
        foreach ((array) ($state['linksets'] ?? []) as $linkset) {
            $authority = (string) ($linkset['authority'] ?? '');
            $predicate = (string) ($linkset['predicate'] ?? '');
            if ($authority === '' || !Iri::isAbsolute($predicate)) {
                continue;
            }
            $node = $this->uris->linkset($authority, $predicate);
            $add($dataset, Vocab::VOID, 'subset', $node);
            $add($node, Vocab::RDF, 'type', Vocab::iri(Vocab::VOID, 'Linkset'));
            $add($node, Vocab::VOID, 'subjectsTarget', $dataset);
            if (isset($authorities[$authority])) {
                $target = $this->uris->linkTarget($authority);
                $add($node, Vocab::VOID, 'objectsTarget', $target);
                $targets[$authority] = $target;
            }
            $add($node, Vocab::VOID, 'linkPredicate', new Iri($predicate));
            $add($node, Vocab::VOID, 'triples', $integer((int) ($linkset['triples'] ?? 0)));
        }
        foreach ($targets as $authority => $target) {
            $definition = $authorities[$authority];
            $add($target, Vocab::RDF, 'type', Vocab::iri(Vocab::VOID, 'Dataset'));
            if (($definition['label'] ?? '') !== '') {
                $add($target, Vocab::DCTERMS, 'title', new Literal((string) $definition['label']));
            }
            if (Iri::isAbsolute((string) ($definition['dataset'] ?? ''))) {
                $add($target, Vocab::FOAF, 'homepage', new Iri((string) $definition['dataset']));
            }
            if (Iri::isAbsolute((string) ($definition['uri_space'] ?? ''))) {
                $add($target, Vocab::VOID, 'uriSpace', new Literal((string) $definition['uri_space']));
            }
        }

        return $triples;
    }
}
