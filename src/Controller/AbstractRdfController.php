<?php
declare(strict_types=1);

namespace DRELinkedData\Controller;

use DRELinkedData\Rdf\Format;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;

/**
 * Response plumbing shared by the public endpoints: RDF bodies with validators
 * and CORS, 303 redirects for content negotiation, and bare 404s.
 *
 * CORS is open (`*`) because everything served here is public data and
 * browser-based Linked Data clients cannot read it otherwise.
 */
abstract class AbstractRdfController extends AbstractActionController
{
    protected function accept(): ?string
    {
        $request = $this->getRequest();
        if (!$request instanceof HttpRequest) {
            return null;
        }
        $header = $request->getHeaders()->get('Accept');
        return $header ? $header->getFieldValue() : null;
    }

    protected function rdf(string $body, Format $format, ?string $lastModified, bool $negotiated = false): Response
    {
        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', $format->mediaType() . '; charset=utf-8');
        $headers->addHeaderLine('ETag', $etag);
        $headers->addHeaderLine('Cache-Control', 'public, max-age=300');
        $headers->addHeaderLine('Access-Control-Allow-Origin', '*');
        if ($negotiated) {
            $headers->addHeaderLine('Vary', 'Accept');
        }
        $modified = $this->httpDate($lastModified);
        if ($modified !== null) {
            $headers->addHeaderLine('Last-Modified', $modified);
        }
        if ($this->matchesEtag($etag)) {
            $response->setStatusCode(304);
            return $response;
        }
        $response->setContent($body);
        return $response;
    }

    protected function seeOther(string $location): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(303);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Location', $location);
        $headers->addHeaderLine('Vary', 'Accept');
        $headers->addHeaderLine('Access-Control-Allow-Origin', '*');
        return $response;
    }

    protected function notFound(): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(404);
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/plain; charset=utf-8');
        $response->setContent("Not found.\n");
        return $response;
    }

    protected function matchesEtag(string $etag): bool
    {
        $request = $this->getRequest();
        if (!$request instanceof HttpRequest) {
            return false;
        }
        $header = $request->getHeaders()->get('If-None-Match');
        if (!$header) {
            return false;
        }
        $candidates = array_map('trim', explode(',', $header->getFieldValue()));
        return in_array($etag, $candidates, true) || in_array('W/' . $etag, $candidates, true) || in_array('*', $candidates, true);
    }

    protected function httpDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('D, d M Y H:i:s \G\M\T');
        } catch (\Exception) {
            return null;
        }
    }
}
