<?php
declare(strict_types=1);

namespace DRELinkedData\Controller;

use DRELinkedData\Projection\ResourceProjector;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\ContentNegotiator;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\RdfWriter;
use DRELinkedData\Service\DefaultSite;
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
 *
 * The identifier names the thing; the documents describe it. A resource that
 * is missing or not public is a 404 on every route, so existence never leaks.
 */
class ResourceController extends AbstractRdfController
{
    public function __construct(
        private readonly ResourceProjector $projector,
        private readonly UriScheme $uris,
        private readonly RdfWriter $writer,
        private readonly DefaultSite $site,
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
            $format = ContentNegotiator::rdfFormat($this->accept());
            $slug = $this->site->slug();
            if ($format === null && $slug !== null) {
                return $this->seeOther($this->url()->fromRoute('site/resource-id', [
                    'site-slug' => $slug,
                    'controller' => $description->siteController(),
                    'id' => $id,
                ]));
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
            $description->modified
        );
    }
}
