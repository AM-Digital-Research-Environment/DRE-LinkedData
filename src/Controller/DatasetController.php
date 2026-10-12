<?php
declare(strict_types=1);

namespace DRELinkedData\Controller;

use DRELinkedData\Dataset\DatasetDescription;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\ContentNegotiator;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\RdfWriter;
use DRELinkedData\Service\DefaultSite;
use DRELinkedData\Settings\DatasetSettings;
use Laminas\Http\Response;
use Laminas\Http\Response\Stream;
use Omeka\Settings\Settings;

/**
 * Dataset-level endpoints:
 *
 *   GET /linked-data/void[.ttl|.nt|.jsonld]   VoID + DCAT description
 *   GET /.well-known/void                     303 → /linked-data/void (RFC 8615 / VoID §6.2)
 *   GET /linked-data/dump.nt.gz               the full dump, gzipped N-Triples
 *
 * The description has no HTML form — the page block is the human view — so an
 * unqualified request negotiates among the RDF formats and defaults to Turtle.
 */
class DatasetController extends AbstractRdfController
{
    public function __construct(
        private readonly DatasetDescription $description,
        private readonly DumpStore $store,
        private readonly UriScheme $uris,
        private readonly RdfWriter $writer,
        private readonly DefaultSite $site,
        private readonly Settings $settings,
    ) {
    }

    public function voidAction(): Response
    {
        $extension = $this->params()->fromRoute('format');
        $negotiated = $extension === null || $extension === '';
        $format = $negotiated
            ? Format::fromMediaType((string) ContentNegotiator::negotiate($this->accept(), Format::mediaTypes())) ?? Format::Turtle
            : Format::tryFrom((string) $extension);
        if ($format === null) {
            return $this->notFound();
        }

        $slug = $this->site->slug();
        $homepage = $slug !== null
            ? $this->url()->fromRoute('site', ['site-slug' => $slug], ['force_canonical' => true])
            : null;
        $state = $this->store->state();
        $triples = $this->description->triples(
            DatasetSettings::fromSettings($this->settings, $this->site->title()),
            $state,
            $homepage,
            $negotiated ? null : $format
        );

        return $this->rdf(
            $this->writer->write($triples, $format),
            $format,
            is_string($state['generated_at'] ?? null) ? $state['generated_at'] : null,
            $negotiated
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

        $etag = '"' . substr((string) ($state['sha256'] ?? hash_file('sha256', $path)), 0, 32) . '"';
        $response = new Stream();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/gzip');
        $headers->addHeaderLine('ETag', $etag);
        $headers->addHeaderLine('Cache-Control', 'public, max-age=0, must-revalidate');
        $headers->addHeaderLine('Access-Control-Allow-Origin', '*');
        $modified = $this->httpDate(is_string($state['generated_at'] ?? null) ? $state['generated_at'] : null);
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
        $headers->addHeaderLine('Content-Length', (string) filesize($path));
        $response->setStatusCode(200);
        $response->setStream($handle);
        $response->setStreamName($filename);
        return $response;
    }
}
