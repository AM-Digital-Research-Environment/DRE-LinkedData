<?php
declare(strict_types=1);

/**
 * DRELinkedData — Omeka S module.
 *
 * Linked Open Data for the Africa Multiple DRE site:
 *
 *   • /resource/{id} identifies every public item, item set and media. It
 *     303-redirects browsers to the record page and RDF clients to
 *     /resource/{id}.ttl | .nt | .jsonld | .rdf — a clean projection of the
 *     record (see Projection\ResourceProjector), not Omeka's internal JSON-LD.
 *   • The record pages themselves (item, media, and item-set pages, which
 *     Omeka routes as a filtered item browse) answer an RDF Accept header with
 *     the same 303, and advertise their RDF documents with <link rel="alternate">.
 *   • An admin-triggered job, or scripts/generate-dump.php from cron, writes
 *     the whole public graph to files/dre-linked-data/dump.nt.gz, served at
 *     /linked-data/dump.nt.gz. Item, item-set and media saves are counted so
 *     the admin and the page block can say when the dump is out of date.
 *   • /linked-data/void (and /.well-known/void) describe the dataset in VoID
 *     and DCAT.
 *   • A "Linked open data" page block presents it all to visitors.
 *
 * Owns the `dre_ld_*` settings and the files/dre-linked-data directory;
 * uninstall removes both. No third-party Composer dependencies.
 */

namespace DRELinkedData;

use DRELinkedData\Controller\AbstractRdfController;
use DRELinkedData\Dataset\DatasetSummary;
use DRELinkedData\Dump\ChangeTracker;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\ContentNegotiator;
use DRELinkedData\Service\DefaultSite;
use DRELinkedData\Service\Factory;
use DRELinkedData\Settings\DatasetSettings;
use Laminas\EventManager\EventInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    /** Site routes whose show pages negotiate: the route's controller segment. */
    private const SITE_RESOURCE_CONTROLLERS = ['item', 'item-set', 'media'];

    /** Omeka's item-set page: an item browse filtered by `item-set-id`. */
    public const ITEM_SET_ROUTE = 'site/item-set';

    /** The module's own routes all start with this. */
    private const ROUTE_PREFIX = 'dre-linked-data-';

    /** Set when the module answers a record page itself (the RDF 303). */
    private bool $answeredRecordPage = false;

    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        foreach (array_merge(DatasetSettings::KEYS, DatasetSettings::INTERNAL_KEYS) as $key) {
            $settings->delete($key);
        }
        try {
            DumpStore::inFilesPath(Factory::filesPath($services))->clear();
        } catch (\Throwable) {
            // The dump is disposable; a leftover directory is harmless.
        }
    }

    /**
     * Opens the public endpoints to everyone, lets the record pages answer
     * RDF clients, and keeps the session out of the module's responses. The
     * route listener runs after routing (and after Laminas has resolved the
     * controller namespace), before any controller work; the finish listener
     * runs just before the response is sent.
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $services = $event->getApplication()->getServiceManager();
        $services->get('Omeka\Acl')->allow(null, [
            Controller\ResourceController::class,
            Controller\DatasetController::class,
        ]);

        $events = $event->getApplication()->getEventManager();
        $events->attach(MvcEvent::EVENT_ROUTE, [$this, 'negotiateRecordPage'], -1000);
        // SendResponseListener sends at -10000.
        $events->attach(MvcEvent::EVENT_FINISH, [$this, 'releaseSession'], -9000);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        foreach ([
            'Omeka\Controller\Site\Item',
            'Omeka\Controller\Site\ItemSet',
            'Omeka\Controller\Site\Media',
        ] as $controller) {
            $sharedEventManager->attach($controller, 'view.show.after', [$this, 'addAlternateLinks']);
        }
        // /s/{slug}/item-set/{id} is the Item controller's browse action.
        $sharedEventManager->attach('Omeka\Controller\Site\Item', 'view.browse.after', [$this, 'addItemSetAlternateLinks']);

        foreach ([
            'Omeka\Api\Adapter\ItemAdapter',
            'Omeka\Api\Adapter\ItemSetAdapter',
            'Omeka\Api\Adapter\MediaAdapter',
        ] as $adapter) {
            foreach (['api.create.post', 'api.update.post', 'api.delete.post'] as $apiEvent) {
                $sharedEventManager->attach($adapter, $apiEvent, [$this, 'recordChange']);
            }
        }
    }

    // ─── Record pages ───────────────────────────────────────────────────────

    /**
     * `/s/{slug}/item/{id}` (and `media/{id}`, `item-set/{id}`) asked for in
     * RDF gets a 303 to the RDF document, so a client that only knows the page
     * URL still reaches the data. Browsers keep the page: they prefer text/html.
     */
    public function negotiateRecordPage(MvcEvent $event): ?HttpResponse
    {
        $match = $event->getRouteMatch();
        $request = $event->getRequest();
        $response = $event->getResponse();
        if ($match === null || !$request instanceof HttpRequest || !$response instanceof HttpResponse) {
            return null;
        }
        $id = self::recordPageId($match);
        if ($id === null) {
            return null;
        }

        // The page now varies by Accept, whichever way this goes.
        $response->getHeaders()->addHeaderLine('Vary', 'Accept');
        $accept = $request->getHeaders()->get('Accept');
        $format = ContentNegotiator::rdfFormat($accept ? $accept->getFieldValue() : null);
        if ($format === null) {
            return null;
        }

        $uris = $this->getServiceLocator()->get(UriScheme::class);
        $response->setStatusCode(303);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Location', $uris->resourceDocument($id, $format));
        $headers->addHeaderLine('Access-Control-Allow-Origin', '*');
        $headers->addHeaderLine('Access-Control-Expose-Headers', AbstractRdfController::EXPOSED_HEADERS);
        $this->answeredRecordPage = true;
        return $response;
    }

    /**
     * The resource id of a record page route match: `site/resource-id` on the
     * item, item-set and media controllers' show action, or `site/item-set`.
     */
    public static function recordPageId(\Laminas\Router\RouteMatch $match): ?int
    {
        $route = $match->getMatchedRouteName();
        if ($route === self::ITEM_SET_ROUTE) {
            $id = (int) $match->getParam('item-set-id', 0);
            return $id > 0 ? $id : null;
        }
        if ($route !== 'site/resource-id') {
            return null;
        }
        $controller = strtolower((string) $match->getParam('__CONTROLLER__', $match->getParam('controller')));
        $controller = substr((string) strrchr('\\' . $controller, '\\'), 1);
        $controller = $controller === 'itemset' ? 'item-set' : $controller;
        if (!in_array($controller, self::SITE_RESOURCE_CONTROLLERS, true)
            || $match->getParam('action', 'show') !== 'show'
        ) {
            return null;
        }
        $id = (int) $match->getParam('id', 0);
        return $id > 0 ? $id : null;
    }

    public function addAlternateLinks(EventInterface $event): void
    {
        $view = $event->getTarget();
        if (!$view instanceof PhpRenderer) {
            return;
        }
        $resource = $view->item ?? $view->itemSet ?? $view->media ?? null;
        if ($resource instanceof AbstractResourceEntityRepresentation) {
            self::appendAlternates($view, $resource);
        }
    }

    /** The item-set page is an item browse: alternates for the item set itself. */
    public function addItemSetAlternateLinks(EventInterface $event): void
    {
        $view = $event->getTarget();
        if (!$view instanceof PhpRenderer) {
            return;
        }
        $itemSet = $view->itemSet ?? null;
        if (!$itemSet instanceof ItemSetRepresentation) {
            return;
        }
        $route = null;
        try {
            $route = $this->getServiceLocator()->get('Application')->getMvcEvent()->getRouteMatch()?->getMatchedRouteName();
        } catch (\Throwable) {
            // No application (a test, a job): the itemSet variable is enough.
        }
        if ($route === null || $route === self::ITEM_SET_ROUTE) {
            self::appendAlternates($view, $itemSet);
        }
    }

    private static function appendAlternates(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): void
    {
        foreach ($view->dreLinkedData($resource)['downloads'] ?? [] as $document) {
            $view->headLink()->appendAlternate($document['url'], $document['type'], $document['label']);
        }
    }

    // ─── Responses without a session ────────────────────────────────────────

    /**
     * Omeka starts the PHP session during bootstrap, before routing (the
     * locale listener asks the authentication service, whose storage is the
     * session), so it cannot be skipped for the module's routes. What can be
     * done is to leave no trace of it on their responses: the session is
     * closed without writing (no session row for anonymous RDF clients; a
     * logged-in user's session is untouched), and the cookie and the
     * `nocache` limiter's Expires / Pragma / Cache-Control lines are removed
     * before Laminas sends its own headers, so the documents stay cacheable.
     */
    public function releaseSession(MvcEvent $event): void
    {
        if (!$this->isSessionless($event)) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        if (!headers_sent()) {
            foreach (['Set-Cookie', 'Expires', 'Pragma', 'Cache-Control'] as $name) {
                header_remove($name);
            }
        }
    }

    /** Responses of the module's own routes, and its record-page 303s. */
    public function isSessionless(MvcEvent $event): bool
    {
        if ($this->answeredRecordPage) {
            return true;
        }
        $name = $event->getRouteMatch()?->getMatchedRouteName();
        return is_string($name) && str_starts_with($name, self::ROUTE_PREFIX);
    }

    // ─── Dump staleness ─────────────────────────────────────────────────────

    /**
     * Counts a saved or deleted item, item set or media toward "the dump is
     * out of date". A new private resource does not change the public graph
     * and is not counted; an update or delete may have, and is.
     */
    public function recordChange(EventInterface $event): void
    {
        try {
            $request = $event->getParam('request');
            if ($request !== null && $request->getOperation() === 'create') {
                $entity = $event->getParam('response')?->getContent();
                if (is_object($entity) && method_exists($entity, 'isPublic') && !$entity->isPublic()) {
                    return;
                }
            }
            $this->getServiceLocator()->get(ChangeTracker::class)->record();
        } catch (\Throwable) {
            // Never let staleness tracking break a save.
        }
    }

    // ─── Module configuration form ──────────────────────────────────────────

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);

        $data = [];
        foreach (DatasetSettings::KEYS as $key) {
            $data[$key] = (string) $settings->get($key, '');
        }
        $form->setData($data);

        $status = '';
        try {
            $effectiveBase = Factory::baseUri($services);
            $form->get(DatasetSettings::BASE_URI)->setAttribute('placeholder', $effectiveBase);
            $summary = $services->get(DatasetSummary::class)->build(
                DatasetSettings::fromSettings($settings, $services->get(DefaultSite::class)->title())
            );
            $status = $renderer->partial('dre-linked-data/admin/status', [
                'summary' => $summary,
                'baseUriWarnings' => self::baseUriWarnings((string) $settings->get(DatasetSettings::BASE_URI, ''), $effectiveBase),
            ]);
        } catch (\Throwable $exception) {
            $services->get('Omeka\Logger')->err('DRELinkedData: could not build the configuration status panel.', [
                'exception' => $exception,
            ]);
        }

        return $status . $renderer->formCollection($form, false);
    }

    /**
     * What is wrong with the base URI for publication: unset (identifiers
     * follow whatever address Omeka is reached at), or not https.
     *
     * @return string[] untranslated messages
     */
    public static function baseUriWarnings(string $configured, string $effective): array
    {
        $warnings = [];
        $configured = trim($configured);
        if ($configured === '') {
            $warnings[] = 'No base URI is set. Identifiers currently follow the address Omeka is reached at, which can change; set the base URI to the public https address before publishing.'; // @translate
        }
        $base = $configured !== '' ? $configured : $effective;
        if (stripos($base, 'http://') === 0) {
            $warnings[] = 'The base URI uses http. Permanent identifiers in production should use https.'; // @translate
        }
        return $warnings;
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);

        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addFormErrors($form);
            return false;
        }

        $data = $form->getData();
        foreach (DatasetSettings::KEYS as $key) {
            $settings->set($key, trim((string) ($data[$key] ?? '')));
        }

        if (!empty($data[Form\ConfigForm::REGENERATE])) {
            $job = $services->get('Omeka\Job\Dispatcher')->dispatch(Job\GenerateDump::class);
            $controller->messenger()->addSuccess(sprintf(
                $services->get('MvcTranslator')->translate('Generating the Linked Data dump in the background (job #%d).'), // @translate
                $job->getId()
            ));
        }
        return true;
    }
}
