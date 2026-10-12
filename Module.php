<?php
declare(strict_types=1);

/**
 * DRELinkedData — Omeka S module.
 *
 * Linked Open Data for the Africa Multiple DRE site:
 *
 *   • /resource/{id} identifies every public item, item set and media. It
 *     303-redirects browsers to the record page and RDF clients to
 *     /resource/{id}.ttl | .nt | .jsonld — a clean projection of the record
 *     (see Projection\ResourceProjector), not Omeka's internal JSON-LD.
 *   • The record pages themselves answer an RDF Accept header with the same
 *     303, and advertise their RDF documents with <link rel="alternate">.
 *   • An admin-triggered job writes the whole public graph to
 *     files/dre-linked-data/dump.nt.gz, served at /linked-data/dump.nt.gz.
 *   • /linked-data/void (and /.well-known/void) describe the dataset in VoID
 *     and DCAT.
 *   • A "Linked open data" page block presents it all to visitors.
 *
 * Owns six `dre_ld_*` settings and the files/dre-linked-data directory;
 * uninstall removes both. No third-party Composer dependencies.
 */

namespace DRELinkedData;

use DRELinkedData\Dataset\DatasetSummary;
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
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    /** Site routes whose show pages negotiate: the route's controller segment. */
    private const SITE_RESOURCE_CONTROLLERS = ['item', 'item-set', 'media'];

    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        foreach (DatasetSettings::KEYS as $key) {
            $settings->delete($key);
        }
        try {
            DumpStore::inFilesPath(Factory::filesPath($services))->clear();
        } catch (\Throwable) {
            // The dump is disposable; a leftover directory is harmless.
        }
    }

    /**
     * Opens the public endpoints to everyone, and lets the record pages answer
     * RDF clients. The route listener runs after routing (and after Laminas
     * has resolved the controller namespace), before any controller work.
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $services = $event->getApplication()->getServiceManager();
        $services->get('Omeka\Acl')->allow(null, [
            Controller\ResourceController::class,
            Controller\DatasetController::class,
        ]);

        $event->getApplication()->getEventManager()->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'negotiateRecordPage'],
            -1000
        );
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
    }

    // ─── Record pages ───────────────────────────────────────────────────────

    /**
     * `/s/{slug}/item/{id}` asked for in RDF gets a 303 to the RDF document,
     * so a client that only knows the page URL still reaches the data.
     * Browsers keep the page: they prefer text/html.
     */
    public function negotiateRecordPage(MvcEvent $event): ?HttpResponse
    {
        $match = $event->getRouteMatch();
        $request = $event->getRequest();
        $response = $event->getResponse();
        if ($match === null || $match->getMatchedRouteName() !== 'site/resource-id'
            || !$request instanceof HttpRequest || !$response instanceof HttpResponse
        ) {
            return null;
        }
        $controller = strtolower((string) $match->getParam('__CONTROLLER__', $match->getParam('controller')));
        $controller = substr((string) strrchr('\\' . $controller, '\\'), 1);
        if (!in_array($controller, self::SITE_RESOURCE_CONTROLLERS, true)
            || $match->getParam('action', 'show') !== 'show'
        ) {
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
        $response->getHeaders()->addHeaderLine(
            'Location',
            $uris->resourceDocument((int) $match->getParam('id'), $format)
        );
        return $response;
    }

    public function addAlternateLinks(EventInterface $event): void
    {
        $view = $event->getTarget();
        if (!$view instanceof PhpRenderer) {
            return;
        }
        $resource = $view->item ?? $view->itemSet ?? $view->media ?? null;
        if (!$resource instanceof AbstractResourceEntityRepresentation) {
            return;
        }
        foreach ($view->dreLinkedData($resource)['downloads'] ?? [] as $document) {
            $view->headLink()->appendAlternate($document['url'], $document['type'], $document['label']);
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
            $form->get(DatasetSettings::BASE_URI)->setAttribute(
                'placeholder',
                Factory::baseUri($services)
            );
            $summary = $services->get(DatasetSummary::class)->build(
                DatasetSettings::fromSettings($settings, $services->get(DefaultSite::class)->title())
            );
            $status = $renderer->partial('dre-linked-data/admin/status', ['summary' => $summary]);
        } catch (\Throwable $exception) {
            $services->get('Omeka\Logger')->err('DRELinkedData: could not build the configuration status panel.', [
                'exception' => $exception,
            ]);
        }

        return $status . $renderer->formCollection($form, false);
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
