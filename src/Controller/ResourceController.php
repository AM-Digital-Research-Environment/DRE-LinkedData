<?php
declare(strict_types=1);

namespace DRELinkedData\Controller;

use DRELinkedData\Projection\ResourceProjector;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\ContentNegotiator;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\RdfWriter;
use DRELinkedData\Settings\DatasetSettings;
use Laminas\Http\Response;
use Omeka\Settings\Settings;

/**
 * The resource URIs, following the W3C "Cool URIs" 303 pattern:
 *
 *   GET /resource/{id}          303 → the record page (browsers) or
 *                               303 → /resource/{id}.{ext} (RDF clients)
 *   GET /resource/{id}.ttl      Turtle
 *   GET /resource/{id}.nt       N-Triples
 *   GET /resource/{id}.jsonld   JSON-LD
 *   GET /resource/{id}.rdf      RDF/XML
 *   GET /resource/{id}/         301 → /resource/{id}
 *
 * The identifier names the thing; the documents describe it. A resource that
 * is missing or not public is a 404 on every route, so existence never leaks.
 * Every redirect target is absolute, built on the configured base URI.
 */
class ResourceController extends AbstractRdfController
{
    public function __construct(
        private readonly ResourceProjector $projector,
        private readonly UriScheme $uris,
        private readonly RdfWriter $writer,
        private readonly Settings $settings,
    ) {
    }

    public function indexAction(): Response
    {
        $id = (int) $this->params()->fromRoute('id', 0);
        $description = $id > 0 ? $this->projector->describeOne($id) : null;
        if ($description === null) {
            return $this->notFound();
        }

        $extension = $this->params()->fromRoute('format');
        if ($extension === null || $extension === '') {
            $accept = $this->accept();
            if (!ContentNegotiator::acceptable($accept)) {
                return $this->notAcceptable(ContentNegotiator::offers());
            }
            $format = ContentNegotiator::rdfFormat($accept);
            $page = $format === null ? $this->uris->page($id, $description->kind) : null;
            if ($page !== null) {
                return $this->seeOther($page);
            }
            return $this->seeOther($this->uris->resourceDocument($id, $format ?? Format::Turtle));
        }

        $format = Format::tryFrom((string) $extension);
        if ($format === null) {
            return $this->notFound();
        }
        $licence = DatasetSettings::fromSettings($this->settings, '')->licence;
        return $this->rdf(
            $this->writer->write($this->projector->document($description, $format, $licence), $format),
            $format,
            $description->modified,
            false,
            self::documentLinks(
                $format,
                fn (Format $other): string => $this->uris->resourceDocument($id, $other),
                $this->uris->resource($id)->value
            )
        );
    }

    /** `/resource/{id}/` is not the identifier; send clients to the one that is. */
    public function slashAction(): Response
    {
        return $this->movedPermanently($this->uris->resource((int) $this->params()->fromRoute('id', 0))->value);
    }
}
