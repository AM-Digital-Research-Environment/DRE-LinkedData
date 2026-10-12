<?php
declare(strict_types=1);

namespace DRELinkedData\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;

/**
 * The site that record pages live on: the configured default site, else the
 * first site. Resolved once per request. AMIRA has exactly one (`amira`).
 */
class DefaultSite
{
    private ?SiteRepresentation $site = null;
    private bool $resolved = false;

    public function __construct(
        private readonly ApiManager $api,
        private readonly Settings $settings,
    ) {
    }

    public function get(): ?SiteRepresentation
    {
        if ($this->resolved) {
            return $this->site;
        }
        $this->resolved = true;
        $defaultSiteId = (int) $this->settings->get('default_site');
        try {
            if ($defaultSiteId) {
                $this->site = $this->api->read('sites', $defaultSiteId)->getContent();
                return $this->site;
            }
        } catch (\Throwable) {
            // fall through to the first site
        }
        try {
            $sites = $this->api->search('sites', ['limit' => 1])->getContent();
            $this->site = $sites[0] ?? null;
        } catch (\Throwable) {
            $this->site = null;
        }
        return $this->site;
    }

    public function slug(): ?string
    {
        return $this->get()?->slug();
    }

    public function title(): string
    {
        return (string) ($this->get()?->title() ?? '');
    }
}
