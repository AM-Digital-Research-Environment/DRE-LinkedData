<?php
declare(strict_types=1);

namespace DRELinkedData\Controller;

use DRELinkedData\Rdf\Format;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;

/**
 * Response plumbing shared by the public endpoints: RDF bodies with an ETag,
 * Link headers and CORS, 303 redirects for content negotiation, and bare 404s.
 *
 * CORS is open (`*`) on every response, errors and redirects included, because
 * everything served here is public data and browser-based Linked Data clients
 * cannot read it otherwise; the validators and the Link header are exposed to
 * them too. These responses never touch the PHP session: the module drops it
 * before they are sent ({@see \DRELinkedData\Module::releaseSession()}).
 */
abstract class AbstractRdfController extends AbstractActionController
{
    public const EXPOSED_HEADERS = 'ETag, Link, Content-Location';

    protected function accept(): ?string
    {
        $request = $this->getRequest();
        if (!$request instanceof HttpRequest) {
            return null;
        }
        $header = $request->getHeaders()->get('Accept');
        return $header ? $header->getFieldValue() : null;
    }

    /**
     * @param string[] $links Link header values (`<url>; rel="…"`)
     * @param string|null $contentLocation the format-specific URL of a negotiated document
     */
    protected function rdf(
        string $body,
        Format $format,
        ?string $lastModified,
        bool $negotiated = false,
        array $links = [],
        ?string $contentLocation = null
    ): Response {
        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', $format->contentType());
        $headers->addHeaderLine('ETag', $etag);
        $headers->addHeaderLine('Cache-Control', 'public, max-age=300');
        self::cors($response);
        if ($negotiated) {
            $headers->addHeaderLine('Vary', 'Accept');
        }
        if ($contentLocation !== null) {
            $headers->addHeaderLine('Content-Location', $contentLocation);
        }
        if ($links) {
            // One header line: Laminas sends repeated generic headers as one.
            $headers->addHeaderLine('Link', implode(', ', $links));
        }
        $modified = $this->httpDate($lastModified);
        if ($modified !== null) {
            $headers->addHeaderLine('Last-Modified', $modified);
        }
        // Only the ETag decides a 304. Last-Modified is the described
        // resource's own date (or the dump's), but a document also changes
        // when a linked resource turns private, a media is added or a setting
        // such as the licence changes, none of which moves that date. An
        // If-Modified-Since 304 would then serve a stale document.
        if ($this->matchesEtag($etag)) {
            $response->setStatusCode(304);
            return $response;
        }
        $response->setContent($body);
        return $response;
    }

    /**
     * The Link header values for an RDF document: the same description in
     * every other format, and what it describes (RFC 6892 `describes`).
     *
     * @param \Closure(Format):string $documentUrl
     * @return string[]
     */
    protected static function documentLinks(Format $current, \Closure $documentUrl, string $describes): array
    {
        $links = [];
        foreach (Format::cases() as $format) {
            if ($format !== $current) {
                $links[] = '<' . $documentUrl($format) . '>; rel="alternate"; type="' . $format->mediaType() . '"';
            }
        }
        $links[] = '<' . $describes . '>; rel="describes"';
        return $links;
    }

    protected function seeOther(string $location): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(303);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Location', $location);
        $headers->addHeaderLine('Vary', 'Accept');
        self::cors($response);
        return $response;
    }

    protected function movedPermanently(string $location): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(301);
        $response->getHeaders()->addHeaderLine('Location', $location);
        self::cors($response);
        return $response;
    }

    /**
     * 406, listing what the client could have asked for instead.
     *
     * @param string[] $offers
     */
    protected function notAcceptable(array $offers): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(406);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'text/plain; charset=utf-8');
        $headers->addHeaderLine('Vary', 'Accept');
        self::cors($response);
        $response->setContent("Not acceptable. Available representations:\n" . implode("\n", $offers) . "\n");
        return $response;
    }

    protected function notFound(): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode(404);
        $response->getHeaders()->addHeaderLine('Content-Type', 'text/plain; charset=utf-8');
        self::cors($response);
        $response->setContent("Not found.\n");
        return $response;
    }

    public static function cors(Response $response): void
    {
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Access-Control-Allow-Origin', '*');
        $headers->addHeaderLine('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);
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

    /** Omeka's `Y-m-d H:i:s` columns are UTC; ISO 8601 strings carry their zone. */
    protected function httpDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $timestamp = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
        return gmdate('D, d M Y H:i:s \G\M\T', $timestamp);
    }
}
