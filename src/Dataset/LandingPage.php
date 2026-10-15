<?php
declare(strict_types=1);

namespace DRELinkedData\Dataset;

use DRELinkedData\Projection\OmekaSource;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Service\DefaultSite;

/**
 * The dataset's landing page: the public site page that carries the Linked
 * open data block, on the default site first.
 *
 * It is the page catalogues and dataset search engines should send people to
 * (`dcat:landingPage`, the schema.org Dataset's `url`), and the page a browser
 * asking `/linked-data/void` for HTML is redirected to. Without such a page
 * there is none, and callers fall back to the site's home. Looked up once per
 * request.
 */
final class LandingPage
{
    public const BLOCK_LAYOUT = 'dreLinkedData';

    private bool $resolved = false;
    private ?string $url = null;

    public function __construct(
        private readonly OmekaSource $source,
        private readonly DefaultSite $site,
        private readonly UriScheme $uris,
    ) {
    }

    public function url(): ?string
    {
        if (!$this->resolved) {
            $this->resolved = true;
            try {
                $siteId = $this->site->get()?->id();
                $pages = $this->source->pagesWithBlock(self::BLOCK_LAYOUT, $siteId !== null ? (int) $siteId : null);
                $this->url = $pages ? $this->uris->sitePage($pages[0]['site_slug'], $pages[0]['page_slug']) : null;
            } catch (\Throwable) {
                $this->url = null;
            }
        }
        return $this->url;
    }
}
