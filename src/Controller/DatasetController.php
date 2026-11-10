<?php
declare(strict_types=1);

namespace DRELinkedData\Controller;

use DRELinkedData\Dataset\DatasetDescription;
use DRELinkedData\Dataset\LandingPage;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\ContentNegotiator;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\RdfWriter;
use DRELinkedData\Service\DefaultSite;
use DRELinkedData\Settings\DatasetSettings;
use Laminas\Http\Response;
use Laminas\Http\Response\Stream;

/**
 * Dataset-level endpoints:
 *
 *   GET /linked-data/void[.ttl|.nt|.jsonld|.rdf]   VoID + DCAT description
 *   GET /.well-known/void                          303 → /linked-data/void (RFC 8615 / VoID §6.2)
 *   GET /linked-data/dump.nt.gz                    the full dump, gzipped N-Triples
 *
 * The description's human form is the site page carrying the "Linked open
 * data" block: a browser asking /linked-data/void for HTML is sent there.
 * Without such a page, and for every other client, the extension-less URL
 * negotiates among the RDF formats and defaults to Turtle.
 */
class DatasetController extends AbstractRdfController
{
    public function __construct(
        private readonly DatasetDescription $description,
        private readonly DumpStore $store,
        private readonly UriScheme $uris,
        private readonly RdfWriter $writer,
        private readonly DefaultSite $site,
        private readonly LandingPage $landingPage,
        /** @var \Closure():DatasetSettings only the VoID needs them */
        private readonly \Closure $datasetSettings,
    ) {
    }

    public function voidAction(): Response
    {
        $extension = $this->params()->fromRoute('format');
        $negotiated = $extension === null || $extension === '';
        if ($negotiated) {
            $accept = $this->accept();
            $offers = Format::mediaTypes(true);
            // Only a client that names HTML can prefer it; RDF clients never
            // pay for the page lookup.
            $page = $accept !== null && stripos($accept, 'html') !== false ? $this->landingPage->url() : null;
            if ($page !== null) {
                $offers[] = ContentNegotiator::HTML;
            }
            $chosen = ContentNegotiator::negotiate($accept, $offers);
            if ($chosen === ContentNegotiator::HTML && $page !== null) {
                return $this->seeOther($page);
            }
            $format = Format::fromMediaType((string) $chosen) ?? Format::Turtle;
        } else {
            $format = Format::tryFrom((string) $extension);
        }
        if ($format === null) {
            return $this->notFound();
        }

        $slug = $this->site->slug();
        $homepage = $slug !== null
            ? $this->url()->fromRoute('site', ['site-slug' => $slug], ['force_canonical' => true])
            : null;
        $state = $this->store->state();
        $triples = $this->description->triples(
            ($this->datasetSettings)(),
            $state,
            $homepage,
            $negotiated ? null : $format,
            $this->landingPage->url()
        );

        return $this->rdf(
            $this->writer->write($triples, $format),
            $format,
            $state?->generatedAt,
            $negotiated,
            self::documentLinks(
                $format,
                fn (Format $other): string => $this->uris->voidDocument($other),
                $this->uris->dataset()->value
            ),
            $negotiated ? $this->uris->voidDocument($format) : null
        );
    }

    public function wellKnownAction(): Response
    {
        return $this->seeOther($this->uris->voidDocument());
    }

    public function dumpAction(): Response
    {
        $path = $this->store->dumpPath();
        $state = $this->store->state();
        $handle = $state !== null ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            return $this->notFound();
        }

        // The state's checksum is the ETag only when it describes the file
        // actually opened: while a new dump is swapped in, state.json and
        // dump.nt.gz are renamed one after the other.
        $stat = fstat($handle) ?: [];
        $size = (int) ($stat['size'] ?? 0);
        $mtime = (int) ($stat['mtime'] ?? 0);
        $sha256 = (string) $state->sha256;
        $etag = $sha256 !== '' && $state->bytes === $size
            ? '"' . substr($sha256, 0, 32) . '"'
            : 'W/"' . dechex($size) . '-' . dechex($mtime) . '"';

        $response = new Stream();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/gzip');
        $headers->addHeaderLine('ETag', $etag);
        $headers->addHeaderLine('Cache-Control', 'public, max-age=0, must-revalidate');
        self::cors($response);
        $headers->addHeaderLine('Link', '<' . $this->uris->voidDocument(Format::Turtle) . '>; rel="describedby"; type="text/turtle"');
        $modified = $this->httpDate($state->generatedAt);
        if ($modified !== null) {
            $headers->addHeaderLine('Last-Modified', $modified);
        }
        if ($this->matchesEtag($etag)) {
            fclose($handle);
            $response->setStatusCode(304);
            return $response;
        }

        $filename = ($this->site->slug() ?? 'omeka') . '-linked-data.nt.gz';
        $headers->addHeaderLine('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $headers->addHeaderLine('Content-Length', (string) $size);
        $response->setStatusCode(200);
        $response->setStream($handle);
        $response->setStreamName($filename);
        return $response;
    }
}
