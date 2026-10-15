<?php
declare(strict_types=1);

// Omeka S 4.2.1's pinned DBAL/Laminas versions emit PHP 8.5 deprecation
// notices; keep the smoke test focused on module failures.
error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * Omeka-backed integration smoke test.
 *
 * OMEKA_PATH must point at an unpacked official Omeka S release. The script
 * loads Omeka's own Composer runtime rather than adding framework dependencies
 * to this module, and checks what the unit tests cannot:
 *
 *   • the visibility SQL against a real database (SQLite, through Omeka's DBAL),
 *     the public-title label, concept schemes, identity lookups and block pages;
 *   • every factory, the routes, and the controllers dispatched end to end,
 *     with their status codes, CORS, Link and validator headers;
 *   • that Turtle, N-Triples, JSON-LD and RDF/XML all parse — with EasyRdf
 *     and ml/json-ld from Omeka's vendor tree — to the same graph;
 *   • the record-page content negotiation listener, on URLs routed by Omeka's
 *     own route table (item, media, and the item-set browse route);
 *   • the view helper contract, the alternate links and the session release;
 *   • the configuration form's validation and the base URI warnings;
 *   • the page block template, rendered by a real PhpRenderer.
 *
 * Set DRE_LD_RENDER_HTML to a file path to keep the rendered block for a
 * visual check against the theme's CSS.
 */

$moduleRoot = dirname(__DIR__, 2);
$omekaPath = rtrim((string) getenv('OMEKA_PATH'), '/\\');
$autoload = $omekaPath . '/vendor/autoload.php';
if ($omekaPath === '' || !is_readable($autoload)) {
    fwrite(STDERR, "ERROR: OMEKA_PATH must point at an unpacked Omeka S release.\n");
    exit(2);
}
if (!defined('OMEKA_PATH')) {
    define('OMEKA_PATH', $omekaPath);
}

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('DRELinkedData\\', $moduleRoot . '/src/');
require_once $omekaPath . '/application/Module.php';
require_once $moduleRoot . '/Module.php';

use DRELinkedData\Controller\DatasetController;
use DRELinkedData\Controller\ResourceController;
use DRELinkedData\Dataset\DatasetDescription;
use DRELinkedData\Dataset\DatasetSummary;
use DRELinkedData\Dump\ChangeTracker;
use DRELinkedData\Dump\DumpGenerator;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Form\ConfigForm;
use DRELinkedData\Projection\IdentityLinks;
use DRELinkedData\Projection\OmekaSource;
use DRELinkedData\Projection\ResourceProjector;
use DRELinkedData\Projection\SameAs;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Rdf\RdfWriter;
use DRELinkedData\Service\DefaultSite;
use DRELinkedData\Service\Factory;
use DRELinkedData\Settings\DatasetSettings;
use DRELinkedData\Site\BlockLayout\LinkedDataBlock;
use DRELinkedData\View\Helper\LinkedData;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\Http\TreeRouteStack;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\View\Resolver\TemplatePathStack;

final class SmokeSettings extends Omeka\Settings\Settings
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    public function get($id, $default = null)
    {
        return $this->values[$id] ?? $default;
    }

    public function set($id, $value)
    {
        $this->values[$id] = $value;
    }

    public function delete($id)
    {
        unset($this->values[$id]);
    }
}

final class SmokeSite extends Omeka\Api\Representation\SiteRepresentation
{
    public function __construct()
    {
    }

    public function id()
    {
        return 1;
    }

    public function slug()
    {
        return 'amira';
    }

    public function title()
    {
        return 'AMIRA Smoke';
    }
}

final class SmokeDefaultSite extends DefaultSite
{
    public function get(): ?Omeka\Api\Representation\SiteRepresentation
    {
        return new SmokeSite();
    }
}

function smokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reflected(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

/** A graph as sorted N-Triples lines, parsed and re-serialised by EasyRdf. */
function canonicalGraph(string $data, string $format): array
{
    $graph = new EasyRdf\Graph();
    $graph->parse($data, $format, 'https://example.test/');
    $lines = array_values(array_filter(explode("\n", (string) $graph->serialise('ntriples'))));
    sort($lines);
    return $lines;
}

/** @return string[] the same, for a JSON-LD document run through ml/json-ld. */
function canonicalJsonLd(string $json): array
{
    $nquads = (new ML\JsonLD\NQuads())->serialize(ML\JsonLD\JsonLD::toRdf($json));
    return canonicalGraph($nquads, 'ntriples');
}

$base = 'https://example.test';
$filesPath = sys_get_temp_dir() . '/dre-ld-omeka-smoke-' . bin2hex(random_bytes(6));
$exitCode = 0;

try {
    smokeAssert(Omeka\Module::VERSION === '4.2.1', 'The smoke test must use Omeka S 4.2.1.');

    // ── A miniature Omeka database ────────────────────────────────────────
    $connection = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    foreach ([
        'CREATE TABLE vocabulary (id INTEGER PRIMARY KEY, prefix VARCHAR(190), namespace_uri VARCHAR(190))',
        'CREATE TABLE property (id INTEGER PRIMARY KEY, vocabulary_id INTEGER, local_name VARCHAR(190))',
        'CREATE TABLE resource_class (id INTEGER PRIMARY KEY, vocabulary_id INTEGER, local_name VARCHAR(190))',
        'CREATE TABLE resource (id INTEGER PRIMARY KEY, resource_type VARCHAR(255), title TEXT, resource_class_id INTEGER,
            resource_template_id INTEGER, is_public INTEGER, created VARCHAR(32), modified VARCHAR(32))',
        'CREATE TABLE resource_template (id INTEGER PRIMARY KEY, label VARCHAR(190), title_property_id INTEGER)',
        'CREATE TABLE site (id INTEGER PRIMARY KEY, slug VARCHAR(190), is_public INTEGER)',
        'CREATE TABLE site_page (id INTEGER PRIMARY KEY, site_id INTEGER, slug VARCHAR(190), is_public INTEGER)',
        'CREATE TABLE site_page_block (id INTEGER PRIMARY KEY, page_id INTEGER, layout VARCHAR(80), position INTEGER)',
        'CREATE TABLE media (id INTEGER PRIMARY KEY, item_id INTEGER, ingester VARCHAR(255), source TEXT, media_type VARCHAR(190),
            storage_id VARCHAR(190), extension VARCHAR(255), has_original INTEGER, position INTEGER)',
        'CREATE TABLE item_item_set (item_id INTEGER, item_set_id INTEGER)',
        'CREATE TABLE value (id INTEGER PRIMARY KEY, resource_id INTEGER, property_id INTEGER, value_resource_id INTEGER,
            type VARCHAR(255), lang VARCHAR(255), value TEXT, uri TEXT, is_public INTEGER)',
        "INSERT INTO vocabulary VALUES (1, 'dcterms', 'http://purl.org/dc/terms/'), (2, 'dre', 'http://am-digital.org/ontology/dre/'),
            (3, 'foaf', 'http://xmlns.com/foaf/0.1/'), (4, 'fabio', 'http://purl.org/spar/fabio/'), (5, 'bibo', 'http://purl.org/ontology/bibo/')",
        "INSERT INTO property VALUES (1, 1, 'title'), (3, 1, 'subject'), (7, 1, 'created'), (10, 1, 'identifier'), (663, 2, 'mongoId'),
            (21, 1, 'alternative'), (112, 5, 'doi')",
        "INSERT INTO resource_class VALUES (1, 3, 'Person'), (5, 4, 'AuthorityFile')",
        // Template 6 (authority) titles by dcterms:alternative; 10 (research items) has no title property.
        "INSERT INTO resource_template VALUES (6, 'Authority', 21), (10, 'Research item', NULL)",
        "INSERT INTO site VALUES (1, 'amira', 1), (2, 'hidden', 0)",
        "INSERT INTO site_page VALUES (1, 1, 'about', 1), (2, 1, 'data', 1), (3, 2, 'secret-data', 1), (4, 1, 'draft-data', 0)",
        "INSERT INTO site_page_block VALUES (1, 1, 'html', 1), (2, 2, 'dreLinkedData', 1), (3, 3, 'dreLinkedData', 1), (4, 4, 'dreLinkedData', 1)",
    ] as $sql) {
        $connection->executeStatement($sql);
    }
    $resources = [
        // id, type, title, class, template, public
        [1, 'Omeka\Entity\Item', 'Public person', 1, 4, 1],
        [2, 'Omeka\Entity\Item', 'Private item', null, 10, 0],
        [3, 'Omeka\Entity\ItemSet', 'Public collection', null, null, 1],
        [4, 'Omeka\Entity\Media', 'Scan of a public item', null, null, 1],
        [5, 'Omeka\Entity\Media', 'Scan of a private item', null, null, 1],
        [6, 'Omeka\Entity\Media', 'Private scan', null, null, 0],
        [7, 'Omeka\Entity\Item', 'Research item', null, 10, 1],
        [8, 'Omeka\Entity\ItemSet', 'Private collection', null, null, 0],
        [9, 'Omeka\Entity\ValueAnnotation', null, null, null, 1],
        // Omeka copies the first title value into resource.title, private or not.
        [10, 'Omeka\Entity\Item', 'Private working title', null, 10, 1],
        [11, 'Omeka\Entity\Item', 'Yoruba', 5, 6, 1],
        [12, 'Omeka\Entity\ItemSet', 'Subjects', null, null, 1],
    ];
    foreach ($resources as [$id, $type, $title, $class, $template, $public]) {
        $connection->insert('resource', [
            'id' => $id, 'resource_type' => $type, 'title' => $title, 'resource_class_id' => $class,
            'resource_template_id' => $template, 'is_public' => $public,
            'created' => '2026-01-01 00:00:00', 'modified' => '2026-06-09 05:37:09',
        ]);
    }
    foreach ([[4, 1, 'upload', 'internal-name.tif', 'image/jpeg', 'abc', 'jpg', 1, 1], [5, 2, 'upload', 'x.tif', 'image/jpeg', 'def', 'jpg', 1, 1], [6, 1, 'upload', 'y.tif', 'image/jpeg', 'ghi', 'jpg', 1, 2]] as $m) {
        $connection->insert('media', array_combine(['id', 'item_id', 'ingester', 'source', 'media_type', 'storage_id', 'extension', 'has_original', 'position'], $m));
    }
    $connection->insert('item_item_set', ['item_id' => 7, 'item_set_id' => 3]);
    $connection->insert('item_item_set', ['item_id' => 7, 'item_set_id' => 8]);
    $connection->insert('item_item_set', ['item_id' => 11, 'item_set_id' => 12]);
    $values = [
        // resource, property, linked, type, lang, value, uri, public
        [1, 1, null, 'literal', null, 'Public person', null, 1],
        [1, 10, null, 'uri', null, 'GND 124120881', 'https://d-nb.info/gnd/124120881', 1],
        [7, 1, null, 'literal', 'en', 'Research item', null, 1],
        [7, 1, null, 'literal', null, 'Secret title', null, 0],
        [7, 7, null, 'numeric:timestamp', null, '1975-03', null, 1],
        [7, 3, 1, 'resource:item', null, null, null, 1],
        [7, 3, 2, 'resource:item', null, null, null, 1],
        [7, 3, 5, 'resource:media', null, null, null, 1],
        [7, 10, null, 'uri', null, 'Q408', 'https://www.wikidata.org/wiki/Q408', 1],
        [7, 663, null, 'literal', null, '65f1c0ffee', null, 1],
        [9, 1, null, 'literal', null, 'An annotation, never a resource', null, 1],
        [10, 1, null, 'literal', null, 'Private working title', null, 0],
        [10, 7, null, 'numeric:timestamp', null, '2021-02-31', null, 1],
        [11, 1, null, 'literal', null, 'Yoruba', null, 1],
        [11, 21, null, 'literal', null, 'Yoruba (African people)', null, 1],
        [11, 10, null, 'uri', null, 'sh85149367', 'http://id.loc.gov/authorities/subjects/sh85149367', 1],
        [12, 1, null, 'literal', null, 'Subjects', null, 1],
        [7, 112, null, 'uri', null, null, 'https://doi.org/10.1000/abc%23def', 1],
    ];
    foreach ($values as $i => [$resource, $property, $linked, $type, $lang, $text, $uri, $public]) {
        $connection->insert('value', [
            'id' => $i + 1, 'resource_id' => $resource, 'property_id' => $property, 'value_resource_id' => $linked,
            'type' => $type, 'lang' => $lang, 'value' => $text, 'uri' => $uri, 'is_public' => $public,
        ]);
    }

    // ── Services ──────────────────────────────────────────────────────────
    $module = new DRELinkedData\Module();
    $config = $module->getConfig();
    $config['file_store']['local']['base_path'] = $filesPath;

    $settings = new SmokeSettings([
        DatasetSettings::BASE_URI => $base,
        DatasetSettings::PUBLISHER_NAME => 'Africa Multiple Cluster of Excellence',
        DatasetSettings::LICENCE => 'https://creativecommons.org/publicdomain/zero/1.0/',
        DatasetSettings::CONTACT_EMAIL => 'dre@example.test',
    ]);
    $logger = new Laminas\Log\Logger();
    $logger->addWriter(new Laminas\Log\Writer\Mock());

    $services = new Laminas\ServiceManager\ServiceManager($config['service_manager']);
    $services->setService('Config', $config);
    $services->setService('Omeka\Connection', $connection);
    $services->setService('Omeka\Settings', $settings);
    $services->setService('Omeka\Logger', $logger);
    $services->setService('Omeka\ApiManager', reflected(Omeka\Api\Manager::class));
    $services->setAllowOverride(true);
    $services->setService(DefaultSite::class, new SmokeDefaultSite(reflected(Omeka\Api\Manager::class), $settings));

    foreach ([OmekaSource::class, UriScheme::class, IdentityLinks::class, ResourceProjector::class, RdfWriter::class,
        DumpStore::class, DumpGenerator::class, DatasetDescription::class, DatasetSummary::class, ChangeTracker::class, SameAs::class] as $type) {
        smokeAssert($services->get($type) instanceof $type, sprintf('Factory failed for %s.', $type));
    }
    $factory = new Factory();
    smokeAssert($factory($services, 'dreLinkedData') instanceof LinkedDataBlock, 'Block layout factory failed.');
    smokeAssert($factory($services, LinkedData::class) instanceof LinkedData, 'View helper factory failed.');
    smokeAssert($services->get(UriScheme::class)->base() === $base, 'The configured base URI was not used.');

    // ── Visibility, against real SQL ──────────────────────────────────────
    /** @var OmekaSource $source */
    $source = $services->get(OmekaSource::class);
    smokeAssert($source->idsAfter(0, 100) === [1, 3, 4, 7, 10, 11, 12], 'Visible ids: ' . json_encode($source->idsAfter(0, 100)));
    smokeAssert($source->isVisible(4) && !$source->isVisible(5) && !$source->isVisible(2), 'isVisible() disagrees with the visibility rule.');
    smokeAssert($source->itemSetsWithMembersOfClass([3, 12], ['http://www.w3.org/2004/02/skos/core#Concept', 'http://purl.org/spar/fabio/AuthorityFile']) === [12], 'Concept schemes found by class.');
    smokeAssert(array_column($source->termValues(7, ['dcterms:identifier', 'bibo:doi']), 'term') === ['dcterms:identifier', 'bibo:doi'], 'termValues() must find public identifier values.');
    smokeAssert($source->termValues(10, ['dcterms:title']) === [], 'termValues() must skip private values.');
    smokeAssert($source->pagesWithBlock('dreLinkedData', 1) === [['site_id' => 1, 'site_slug' => 'amira', 'page_slug' => 'data']], 'Only public pages of public sites carry the block: ' . json_encode($source->pagesWithBlock('dreLinkedData', 1)));
    smokeAssert($source->idsAfter(3, 1) === [4], 'Keyset paging failed.');

    // Vocabulary lookups fetch only the ids asked for, each at most once.
    $queries = new Doctrine\DBAL\Logging\DebugStack();
    $connection->getConfiguration()->setSQLLogger($queries);
    $lookups = new OmekaSource($connection);
    $properties = $lookups->properties([1, 10, 999]);
    smokeAssert(array_keys($properties) === [1, 10], 'properties() must return only the requested ids that exist.');
    smokeAssert($properties[10] === ['iri' => 'http://purl.org/dc/terms/identifier', 'term' => 'dcterms:identifier'], 'Wrong property mapping.');
    smokeAssert(array_keys($lookups->properties([10, 999])) === [10], 'A cached subset was not returned.');
    smokeAssert($lookups->classes([1]) === [1 => 'http://xmlns.com/foaf/0.1/Person'], 'Wrong class mapping.');
    smokeAssert(count($queries->queries) === 2, 'Repeat and missing ids must not be queried again; ran ' . count($queries->queries) . ' queries.');
    $connection->getConfiguration()->setSQLLogger(null);

    /** @var ResourceProjector $projector */
    $projector = $services->get(ResourceProjector::class);
    /** @var RdfWriter $writer */
    $writer = $services->get(RdfWriter::class);
    foreach ([2, 5, 6, 8, 9, 404] as $hidden) {
        smokeAssert($projector->describeOne($hidden) === null, "Resource {$hidden} must not be described.");
    }
    $item = $projector->describeOne(7);
    $nt = $writer->write($item->triples, Format::NTriples);
    foreach ([
        '<https://example.test/resource/7> <http://purl.org/dc/terms/subject> <https://example.test/resource/1> .',
        '<https://example.test/resource/7> <https://schema.org/isPartOf> <https://example.test/resource/3> .',
        '<https://example.test/resource/7> <http://www.w3.org/2002/07/owl#sameAs> <http://www.wikidata.org/entity/Q408> .',
        '"Research item"@en .',
        '"1975-03"^^<http://www.w3.org/2001/XMLSchema#gYearMonth> .',
    ] as $expected) {
        smokeAssert(str_contains($nt, $expected), "Missing from resource 7:\n{$expected}\n\n{$nt}");
    }
    foreach (['resource/2>', 'resource/5>', 'resource/8>', 'Secret title', 'mongoId', '65f1c0ffee'] as $leak) {
        smokeAssert(!str_contains($nt, $leak), "Leaked into resource 7: {$leak}\n\n{$nt}");
    }
    smokeAssert(str_contains($nt, '<https://example.test/resource/7> <http://xmlns.com/foaf/0.1/isPrimaryTopicOf> <https://example.test/s/amira/item/7> .'), 'Missing the record page link.');
    smokeAssert(str_contains($nt, '<https://example.test/resource/7> <http://purl.org/dc/terms/identifier> "https://www.wikidata.org/wiki/Q408" .'), 'dcterms:identifier must be a literal.');
    smokeAssert(str_contains($nt, '<https://doi.org/10.1000/abc%23def>'), 'A DOI with # must keep it encoded in its IRI.');

    // The private title Omeka copied into resource.title must not become the label.
    $private = $writer->write($projector->describeOne(10)->triples, Format::NTriples);
    smokeAssert(!str_contains($private, 'Private working title') && !str_contains($private, 'rdf-schema#label'), "A private title leaked:\n{$private}");
    smokeAssert(str_contains($private, '"2021-02-31" .'), 'An impossible date must stay a plain literal.');
    // The template's title property decides the label; the concept's item set is its scheme.
    $concept = $writer->write($projector->describeOne(11)->triples, Format::NTriples);
    smokeAssert(str_contains($concept, '<http://www.w3.org/2000/01/rdf-schema#label> "Yoruba (African people)" .'), "The template title property was not used:\n{$concept}");
    smokeAssert(str_contains($concept, '<http://www.w3.org/2004/02/skos/core#inScheme> <https://example.test/resource/12> .'), 'Concept lacks its scheme.');
    $scheme = $writer->write($projector->describeOne(12)->triples, Format::NTriples);
    smokeAssert(str_contains($scheme, '<http://www.w3.org/2004/02/skos/core#ConceptScheme>'), 'The concepts\' item set is not a ConceptScheme.');
    smokeAssert(!str_contains($writer->write($projector->describeOne(3)->triples, Format::NTriples), 'ConceptScheme'), 'A plain item set is no scheme.');
    $media = $writer->write($projector->describeOne(4)->triples, Format::NTriples);
    smokeAssert(str_contains($media, '<https://example.test/files/original/abc.jpg>'), 'Media file URL missing.');
    smokeAssert(!str_contains($media, 'internal-name'), 'An upload filename leaked.');

    // ── Every format parses to the same graph ─────────────────────────────
    $document = $projector->document($item, Format::Turtle, $settings->get(DatasetSettings::LICENCE));
    $fromNt = canonicalGraph($writer->write($document, Format::NTriples), 'ntriples');
    smokeAssert(count($fromNt) === count($document), 'EasyRdf read a different number of N-Triples statements.');
    smokeAssert(canonicalGraph($writer->write($document, Format::Turtle), 'turtle') === $fromNt, 'Turtle and N-Triples disagree.');
    smokeAssert(canonicalJsonLd($writer->write($document, Format::JsonLd)) === $fromNt, 'JSON-LD and N-Triples disagree.');
    smokeAssert(canonicalGraph($writer->write($document, Format::RdfXml), 'rdfxml') === $fromNt, 'RDF/XML and N-Triples disagree.');

    // ── Routes and controllers, dispatched ────────────────────────────────
    $omekaRoutes = (include $omekaPath . '/application/config/routes.config.php')['router']['routes'];
    $router = TreeRouteStack::factory(['routes' => $omekaRoutes + $config['router']['routes']]);
    $route = static function (string $path) use ($router, $base): ?Laminas\Router\RouteMatch {
        $request = new Request();
        $request->setUri($base . $path);
        return $router->match($request);
    };
    foreach ([
        '/resource/7' => ['dre-linked-data-resource', null],
        '/resource/7.ttl' => ['dre-linked-data-resource', 'ttl'],
        '/resource/7.jsonld' => ['dre-linked-data-resource', 'jsonld'],
        '/resource/7.rdf' => ['dre-linked-data-resource', 'rdf'],
        '/resource/7/' => ['dre-linked-data-resource-slash', null],
        '/linked-data/void' => ['dre-linked-data-void', null],
        '/linked-data/void.nt' => ['dre-linked-data-void', 'nt'],
        '/linked-data/dump.nt.gz' => ['dre-linked-data-dump', null],
        '/.well-known/void' => ['dre-linked-data-well-known', null],
    ] as $path => [$name, $format]) {
        $match = $route($path);
        smokeAssert($match !== null && $match->getMatchedRouteName() === $name, "{$path} did not route to {$name}.");
        smokeAssert($match->getParam('format') === $format, "{$path} parsed the wrong format.");
    }
    smokeAssert($route('/resource/7.xml') === null, '/resource/7.xml must not route.');

    $dispatch = static function (string $controllerClass, string $path, ?string $accept = null, array $headers = []) use ($factory, $services, $router, $base, $route): Response {
        $request = new Request();
        $request->setUri($base . $path);
        if ($accept !== null) {
            $request->getHeaders()->addHeaderLine('Accept', $accept);
        }
        foreach ($headers as $name => $value) {
            $request->getHeaders()->addHeaderLine($name, $value);
        }
        $event = new MvcEvent();
        $event->setRouter($router);
        $event->setRouteMatch($route($path));
        $controller = $factory($services, $controllerClass);
        $controller->setEvent($event);
        $result = $controller->dispatch($request, new Response());
        smokeAssert($result instanceof Response, "{$path} did not return a response.");
        return $result;
    };
    $header = static fn (Response $response, string $name): string => $response->getHeaders()->has($name) ? $response->getHeaders()->get($name)->getFieldValue() : '';

    $page = $dispatch(ResourceController::class, '/resource/7', 'text/html,*/*;q=0.8');
    smokeAssert($page->getStatusCode() === 303 && $header($page, 'Location') === $base . '/s/amira/item/7', 'Browsers must be sent to the record page (absolute), got ' . $header($page, 'Location'));
    smokeAssert($header($page, 'Access-Control-Allow-Origin') === '*', 'The 303 needs open CORS.');
    $set = $dispatch(ResourceController::class, '/resource/3', 'text/html');
    smokeAssert($header($set, 'Location') === $base . '/s/amira/item-set/3', 'Item sets must be sent to the item-set page.');
    $rdf = $dispatch(ResourceController::class, '/resource/7', 'application/ld+json');
    smokeAssert($rdf->getStatusCode() === 303 && $header($rdf, 'Location') === $base . '/resource/7.jsonld', 'RDF clients must be sent to the document.');
    smokeAssert(str_contains($header($rdf, 'Vary'), 'Accept'), 'The 303 must vary by Accept.');
    smokeAssert($header($rdf, 'Access-Control-Expose-Headers') === 'ETag, Link, Content-Location', 'The 303 must expose the validators and links.');
    $rdfXml = $dispatch(ResourceController::class, '/resource/7', 'application/rdf+xml');
    smokeAssert($rdfXml->getStatusCode() === 303 && $header($rdfXml, 'Location') === $base . '/resource/7.rdf', 'RDF/XML clients must be sent to the RDF/XML document.');
    $unservable = $dispatch(ResourceController::class, '/resource/7', 'application/xml');
    smokeAssert($unservable->getStatusCode() === 406, 'A format the module does not serve must be a 406, got ' . $unservable->getStatusCode());
    smokeAssert(str_contains($unservable->getContent(), 'application/rdf+xml'), 'The 406 must list the available types.');
    $missing = $dispatch(ResourceController::class, '/resource/424242', 'application/xml');
    smokeAssert($missing->getStatusCode() === 404, 'A missing resource is a 404 before any negotiation.');
    smokeAssert($header($missing, 'Access-Control-Allow-Origin') === '*', 'The 404 needs open CORS.');
    $slash = $dispatch(ResourceController::class, '/resource/7/');
    smokeAssert($slash->getStatusCode() === 301 && $header($slash, 'Location') === $base . '/resource/7', 'A trailing slash must 301 to the identifier.');

    $turtle = $dispatch(ResourceController::class, '/resource/7.ttl');
    smokeAssert($turtle->getStatusCode() === 200, 'The Turtle document failed.');
    smokeAssert($header($turtle, 'Content-Type') === 'text/turtle; charset=utf-8', 'Wrong Turtle media type.');
    smokeAssert($header($turtle, 'Access-Control-Allow-Origin') === '*', 'RDF documents need open CORS.');
    smokeAssert($header($turtle, 'Access-Control-Expose-Headers') === 'ETag, Link, Content-Location', 'RDF documents must expose their headers.');
    $link = $header($turtle, 'Link');
    foreach ([
        '<' . $base . '/resource/7.nt>; rel="alternate"; type="application/n-triples"',
        '<' . $base . '/resource/7.jsonld>; rel="alternate"; type="application/ld+json"',
        '<' . $base . '/resource/7.rdf>; rel="alternate"; type="application/rdf+xml"',
        '<' . $base . '/resource/7>; rel="describes"',
    ] as $expected) {
        smokeAssert(str_contains($link, $expected), "The Link header lacks {$expected}: {$link}");
    }
    smokeAssert(!str_contains($link, '7.ttl>'), 'A document does not list itself as an alternate.');
    smokeAssert(canonicalGraph($turtle->getContent(), 'turtle') === $fromNt, 'The served Turtle is not the projected document.');
    foreach (['nt' => 'application/n-triples', 'jsonld' => 'application/ld+json', 'rdf' => 'application/rdf+xml'] as $extension => $type) {
        $served = $dispatch(ResourceController::class, '/resource/7.' . $extension);
        smokeAssert($served->getStatusCode() === 200 && $header($served, 'Content-Type') === $type, "/resource/7.{$extension} must be {$type} with no charset, got " . $header($served, 'Content-Type'));
    }
    $notModified = $dispatch(ResourceController::class, '/resource/7.ttl', null, ['If-None-Match' => $header($turtle, 'ETag')]);
    smokeAssert($notModified->getStatusCode() === 304, 'A matching ETag must give 304.');
    $unchangedSince = $dispatch(ResourceController::class, '/resource/7.ttl', null, ['If-Modified-Since' => 'Wed, 10 Jun 2026 00:00:00 GMT']);
    smokeAssert($unchangedSince->getStatusCode() === 304, 'If-Modified-Since after the last change must give 304, got ' . $unchangedSince->getStatusCode());
    $changedSince = $dispatch(ResourceController::class, '/resource/7.ttl', null, ['If-Modified-Since' => 'Mon, 01 Jun 2026 00:00:00 GMT']);
    smokeAssert($changedSince->getStatusCode() === 200, 'If-Modified-Since before the last change must give 200.');
    $etagWins = $dispatch(ResourceController::class, '/resource/7.ttl', null, ['If-None-Match' => '"other"', 'If-Modified-Since' => 'Wed, 10 Jun 2026 00:00:00 GMT']);
    smokeAssert($etagWins->getStatusCode() === 200, 'If-None-Match takes precedence over If-Modified-Since.');
    foreach (['/resource/2', '/resource/2.ttl', '/resource/5.nt', '/resource/9.jsonld'] as $hidden) {
        smokeAssert($dispatch(ResourceController::class, $hidden, 'text/turtle')->getStatusCode() === 404, "{$hidden} must be a 404.");
    }

    // ── Dump, then the dataset endpoints that describe it ─────────────────
    $noDump = $dispatch(DatasetController::class, '/linked-data/dump.nt.gz');
    smokeAssert($noDump->getStatusCode() === 404, 'No dump yet must be a 404.');

    $state = $services->get(DumpGenerator::class)->generate();
    smokeAssert(is_array($state) && $state['resources'] === ['items' => 4, 'item_sets' => 2, 'media' => 1], 'Dump counts: ' . json_encode($state['resources'] ?? null));
    $dumpPath = $services->get(DumpStore::class)->dumpPath();
    $dumpGraph = canonicalGraph((string) gzdecode((string) file_get_contents($dumpPath)), 'ntriples');
    smokeAssert(count($dumpGraph) === $state['triples'], 'The dump state miscounts its triples.');
    foreach (['Secret title', 'Private working title', 'mongoId', 'resource/2>', 'resource/5>', 'internal-name'] as $leak) {
        smokeAssert(!str_contains(implode("\n", $dumpGraph), $leak), "Leaked into the dump: {$leak}");
    }

    $dump = $dispatch(DatasetController::class, '/linked-data/dump.nt.gz');
    smokeAssert($dump instanceof Response\Stream && $dump->getStatusCode() === 200, 'The dump must stream.');
    smokeAssert($header($dump, 'Content-Type') === 'application/gzip', 'Wrong dump media type.');
    smokeAssert(str_contains($header($dump, 'Content-Disposition'), 'amira-linked-data.nt.gz'), 'Wrong dump filename.');
    smokeAssert($header($dump, 'ETag') === '"' . substr($state['sha256'], 0, 32) . '"', 'The dump ETag is its checksum when the state describes it.');
    fclose($dump->getStream());
    // Mid-swap, the state may describe another file: the ETag must not lie.
    $statePath = dirname($dumpPath) . '/state.json';
    $stateJson = (string) file_get_contents($statePath);
    file_put_contents($statePath, json_encode(['bytes' => $state['bytes'] + 1] + $state));
    $midSwap = $dispatch(DatasetController::class, '/linked-data/dump.nt.gz');
    smokeAssert(str_starts_with($header($midSwap, 'ETag'), 'W/"'), 'A state that does not match the file must not provide the ETag.');
    fclose($midSwap->getStream());
    file_put_contents($statePath, $stateJson);

    $voidTurtle = $dispatch(DatasetController::class, '/linked-data/void', 'text/turtle');
    smokeAssert($header($voidTurtle, 'Content-Location') === $base . '/linked-data/void.ttl', 'The negotiated VoID needs a Content-Location.');
    smokeAssert(str_contains($header($voidTurtle, 'Link'), '<' . $base . '/linked-data/void#dataset>; rel="describes"'), 'The VoID Link header must name the dataset.');
    $voidPage = $dispatch(DatasetController::class, '/linked-data/void', 'text/html,application/xhtml+xml,*/*;q=0.8');
    smokeAssert($voidPage->getStatusCode() === 303 && $header($voidPage, 'Location') === $base . '/s/amira/page/data', 'Browsers must be sent to the Linked open data page, got ' . $voidPage->getStatusCode() . ' ' . $header($voidPage, 'Location'));
    $voidAny = $dispatch(DatasetController::class, '/linked-data/void', '*/*');
    smokeAssert($voidAny->getStatusCode() === 200 && str_starts_with($header($voidAny, 'Content-Type'), 'text/turtle'), 'A wildcard still gets Turtle.');
    smokeAssert($dispatch(DatasetController::class, '/linked-data/void.ttl')->getContent() !== '', 'The .ttl VoID failed.');
    $voidXml = $dispatch(DatasetController::class, '/linked-data/void.rdf');
    smokeAssert(canonicalGraph($voidXml->getContent(), 'rdfxml') !== [], 'The VoID must be served as RDF/XML too.');
    $voidJsonLd = $dispatch(DatasetController::class, '/linked-data/void.jsonld');
    $voidGraph = canonicalGraph($voidTurtle->getContent(), 'turtle');
    smokeAssert(in_array('<https://example.test/linked-data/void#dataset> <http://rdfs.org/ns/void#triples> "' . $state['triples'] . '"^^<http://www.w3.org/2001/XMLSchema#integer> .', $voidGraph, true), 'VoID lacks the triple count.');
    smokeAssert(count(canonicalJsonLd($voidJsonLd->getContent())) === count($voidGraph), 'VoID JSON-LD and Turtle disagree.');
    smokeAssert(str_contains(implode("\n", $voidGraph), 'void#links-wikidata-sameAs'), 'VoID lacks the Wikidata linkset.');
    foreach (['<http://www.w3.org/2006/vcard/ns#hasEmail> <mailto:dre@example.test>', 'spdx.org/rdf/terms#checksumValue> "' . $state['sha256'] . '"', '/dc/terms/issued>', 'dcat#keyword>'] as $expected) {
        smokeAssert(str_contains(implode("\n", $voidGraph), $expected), "VoID lacks {$expected}");
    }
    $wellKnown = $dispatch(DatasetController::class, '/.well-known/void');
    smokeAssert($wellKnown->getStatusCode() === 303 && $header($wellKnown, 'Location') === $base . '/linked-data/void', 'The well-known redirect failed.');

    // ── Record pages answer RDF clients ───────────────────────────────────
    $module->setServiceLocator($services);
    // Omeka's route table, then Laminas' ModuleRouteListener (which resolves
    // `Item` to `Omeka\Controller\Site\Item` and keeps __CONTROLLER__), then
    // the module's listener: what a real request goes through.
    $negotiate = static function (string $path, string $accept) use ($module, $route, $base): array {
        $request = new Request();
        $request->setUri($base . $path);
        $request->getHeaders()->addHeaderLine('Accept', $accept);
        $event = new MvcEvent();
        $event->setRouteMatch($route($path));
        $event->setRequest($request);
        $event->setResponse(new Response());
        (new Laminas\Mvc\ModuleRouteListener())->onRoute($event);
        return [$module->negotiateRecordPage($event), $event->getResponse(), $event];
    };
    foreach ([
        '/s/amira/item/7' => ['site/resource-id', '/resource/7.ttl'],
        '/s/amira/media/4' => ['site/resource-id', '/resource/4.ttl'],
        '/s/amira/item-set/3' => ['site/item-set', '/resource/3.ttl'],
    ] as $path => [$routeName, $target]) {
        [$result, $response, $event] = $negotiate($path, 'text/turtle');
        smokeAssert($event->getRouteMatch()->getMatchedRouteName() === $routeName, "{$path} routed to " . $event->getRouteMatch()->getMatchedRouteName());
        smokeAssert($result instanceof Response && $result->getStatusCode() === 303, "An RDF Accept on {$path} must 303.");
        smokeAssert($header($response, 'Location') === $base . $target, "{$path} redirected to " . $header($response, 'Location'));
        smokeAssert($header($response, 'Access-Control-Allow-Origin') === '*', "The record-page 303 for {$path} needs open CORS.");
        smokeAssert($module->isSessionless($event), "The record-page 303 for {$path} must drop the session.");
    }
    $freshModule = new DRELinkedData\Module();
    $freshModule->setServiceLocator($services);
    $negotiateFresh = static function (string $path, string $accept) use ($freshModule, $route, $base): array {
        $request = new Request();
        $request->setUri($base . $path);
        $request->getHeaders()->addHeaderLine('Accept', $accept);
        $event = new MvcEvent();
        $event->setRouteMatch($route($path));
        $event->setRequest($request);
        $event->setResponse(new Response());
        (new Laminas\Mvc\ModuleRouteListener())->onRoute($event);
        return [$freshModule->negotiateRecordPage($event), $event->getResponse(), $event];
    };
    [$result, $response, $event] = $negotiateFresh('/s/amira/item-set/3', 'text/html,application/xhtml+xml,*/*;q=0.8');
    smokeAssert($result === null && $header($response, 'Vary') === 'Accept', 'Browsers must keep the page, with Vary: Accept.');
    smokeAssert(!$freshModule->isSessionless($event), 'A record page served as HTML keeps its session.');
    [$result] = $negotiateFresh('/s/amira/item', 'text/turtle');
    smokeAssert($result === null, 'Only item, item-set and media pages negotiate, not the browse page.');
    [$result] = $negotiateFresh('/s/amira/page/data', 'text/turtle');
    smokeAssert($result === null, 'Site pages do not negotiate.');
    $ownRoute = new MvcEvent();
    $ownRoute->setRouteMatch($route('/resource/7.ttl'));
    smokeAssert($freshModule->isSessionless($ownRoute), 'The module\'s own routes are served without a session.');
    $freshModule->releaseSession($ownRoute);

    $sharedEvents = new Laminas\EventManager\SharedEventManager();
    $module->attachListeners($sharedEvents);
    smokeAssert(count($sharedEvents->getListeners(['Omeka\Controller\Site\Item'], 'view.show.after')) === 1, 'Alternate-link listener not attached.');
    smokeAssert(count($sharedEvents->getListeners(['Omeka\Controller\Site\Item'], 'view.browse.after')) === 1, 'Item-set alternate-link listener not attached.');
    smokeAssert(count($sharedEvents->getListeners(['Omeka\Api\Adapter\ItemAdapter'], 'api.update.post')) === 1, 'Change tracking not attached.');

    // Staleness: a public save is counted, a new private resource is not.
    $apiEvent = static function (string $operation, bool $public): Laminas\EventManager\Event {
        $entity = new Omeka\Entity\Item();
        $entity->setIsPublic($public);
        return new Laminas\EventManager\Event('api.' . $operation . '.post', null, [
            'request' => new Omeka\Api\Request($operation, 'items'),
            'response' => new Omeka\Api\Response($entity),
        ]);
    };
    $module->recordChange($apiEvent('create', false));
    $module->recordChange($apiEvent('create', true));
    $module->recordChange($apiEvent('update', false));
    $services->get(ChangeTracker::class)->flush();
    $changes = $settings->get(DatasetSettings::CHANGES);
    smokeAssert(is_array($changes) && $changes['count'] === 2, 'Changes counted: ' . json_encode($changes));
    smokeAssert($services->get(ChangeTracker::class)->pending($services->get(DumpStore::class)->state()) !== null, 'The dump must now be out of date.');

    smokeAssert(DRELinkedData\Module::baseUriWarnings('', 'http://localhost') !== [] && count(DRELinkedData\Module::baseUriWarnings('', 'http://localhost')) === 2, 'An empty, http base needs two warnings.');
    smokeAssert(count(DRELinkedData\Module::baseUriWarnings('http://data.example.test', 'http://data.example.test')) === 1, 'An http base needs a warning.');
    smokeAssert(DRELinkedData\Module::baseUriWarnings('https://data.example.test', 'https://data.example.test') === [], 'An https base is fine.');

    $helper = $factory($services, LinkedData::class);
    $publicItem = new class extends Omeka\Api\Representation\ItemRepresentation {
        public function __construct()
        {
        }

        public function id()
        {
            return 7;
        }

        public function isPublic()
        {
            return true;
        }

        public function resourceTemplate()
        {
            return null;
        }
    };
    $linked = $helper($publicItem);
    smokeAssert(array_keys($linked) === ['uri', 'page', 'sameAs', 'downloads'], 'dreLinkedData() keys: ' . json_encode(array_keys($linked)));
    smokeAssert($linked['uri'] === $base . '/resource/7' && $linked['page'] === $base . '/s/amira/item/7', 'dreLinkedData() uri/page: ' . json_encode($linked));
    smokeAssert($linked['sameAs'] === ['http://www.wikidata.org/entity/Q408', 'https://doi.org/10.1000/abc%23def'], 'dreLinkedData() sameAs: ' . json_encode($linked['sameAs']));
    smokeAssert(array_column($linked['downloads'], 'label') === ['Turtle', 'N-Triples', 'JSON-LD', 'RDF/XML'], 'The first three downloads are the contract\'s, RDF/XML after.');
    smokeAssert($linked['downloads'][0] === ['label' => 'Turtle', 'type' => 'text/turtle', 'url' => $base . '/resource/7.ttl'], 'Turtle download entry.');
    smokeAssert($helper() === $helper, 'dreLinkedData() without a resource returns the helper.');
    $dataset = $helper()->dataset();
    smokeAssert(array_keys($dataset) === ['uri', 'void', 'landingPage', 'title', 'description', 'licence', 'publisher', 'modified', 'triples', 'entities', 'distributions'], 'dataset() keys: ' . json_encode(array_keys($dataset)));
    smokeAssert($dataset['uri'] === $base . '/linked-data/void#dataset' && $dataset['title'] === 'AMIRA Smoke' && $dataset['triples'] === $state['triples'], 'dataset(): ' . json_encode($dataset));
    smokeAssert($dataset['distributions'][0]['bytes'] === $state['bytes'] && $dataset['distributions'][0]['encoding'] === 'application/gzip', 'dataset() distribution.');

    // Alternate links: on the item-set page (an item browse) and on show pages.
    $view = new PhpRenderer();
    $view->getHelperPluginManager()->setService('dreLinkedData', $helper);
    $itemSetRep = new class extends Omeka\Api\Representation\ItemSetRepresentation {
        public function __construct()
        {
        }

        public function id()
        {
            return 3;
        }

        public function isPublic()
        {
            return true;
        }

        public function resourceTemplate()
        {
            return null;
        }
    };
    $view->setVars(['itemSet' => $itemSetRep, 'items' => []]);
    $module->addItemSetAlternateLinks(new Laminas\EventManager\Event('view.browse.after', $view));
    $alternates = html_entity_decode((string) $view->headLink(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    smokeAssert(substr_count($alternates, 'rel="alternate"') === 4 && str_contains($alternates, $base . '/resource/3.ttl'), "Item-set page alternates:\n{$alternates}");

    // ── Configuration form ────────────────────────────────────────────────
    $forms = new Laminas\Form\FormElementManager($services, $config['form_elements']);
    $validate = static function (array $data) use ($forms): bool {
        $form = $forms->build(ConfigForm::class);
        $form->setData($data);
        return $form->isValid();
    };
    smokeAssert($validate([]), 'An empty configuration must be valid.');
    smokeAssert($validate([DatasetSettings::BASE_URI => 'https://data.africamultiple.uni-bayreuth.de', DatasetSettings::LICENCE => 'https://creativecommons.org/licenses/by/4.0/']), 'A valid configuration was rejected.');
    smokeAssert(!$validate([DatasetSettings::BASE_URI => 'https://example.test/?x=1']), 'A base URI with a query must be rejected.');
    smokeAssert(!$validate([DatasetSettings::LICENCE => 'CC0']), 'A licence that is not a URI must be rejected.');
    smokeAssert($validate([DatasetSettings::CONTACT_EMAIL => 'dre@example.test']), 'A contact email was rejected.');
    smokeAssert(!$validate([DatasetSettings::CONTACT_EMAIL => 'not an address']), 'A contact email must be an address.');

    // ── The page block, rendered ──────────────────────────────────────────
    $renderer = new PhpRenderer();
    $renderer->setResolver(new TemplatePathStack(['script_paths' => [$moduleRoot . '/view']]));
    $helpers = $renderer->getHelperPluginManager();
    $helpers->setService('translate', new class extends AbstractHelper {
        public function __invoke($message)
        {
            return $message;
        }
    });
    $helpers->setService('lang', new class extends AbstractHelper {
        public function __invoke()
        {
            return 'en-GB';
        }
    });
    $helpers->setService('i18n', new class extends AbstractHelper {
        public function __invoke()
        {
            return $this;
        }

        public function dateFormat($date, $dateType = 'medium', $timeType = 'none')
        {
            return $date->format('j F Y, H:i');
        }
    });
    $block = new class extends Omeka\Api\Representation\SitePageBlockRepresentation {
        public function __construct()
        {
        }

        public function id()
        {
            return 12;
        }
    };
    $summary = $services->get(DatasetSummary::class)->build(DatasetSettings::fromSettings($settings, 'AMIRA Smoke'));
    smokeAssert($services->get(DatasetSummary::class)->build(DatasetSettings::fromSettings($settings, 'x'), 2)['example']['id'] === 7, 'A private example id must fall back to the dump\'s.');
    $html = $renderer->render('common/block-layout/dre-linked-data', [
        'block' => $block,
        'heading' => 'Linked open data',
        'summary' => $summary,
    ]);
    $dom = new DOMDocument();
    smokeAssert(@$dom->loadHTML('<?xml encoding="utf-8"?>' . $html), 'The block template is not parseable HTML.');
    // escapeHtmlAttr() entity-encodes every URL character; compare decoded.
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach (['class="dre-ld"', 'Download N-Triples', 'href="' . $base . '/linked-data/dump.nt.gz"', $base . '/resource/{id}', 'curl -L -H "Accept: text/turtle" ' . $base . '/resource/7', 'Wikidata', 'owl:sameAs', 'Dump is out of date (2 changes since', 'RDF/XML'] as $expected) {
        smokeAssert(str_contains($text, $expected), "The block lacks: {$expected}");
    }
    $status = html_entity_decode($renderer->render('dre-linked-data/admin/status', [
        'summary' => $summary,
        'baseUriWarnings' => DRELinkedData\Module::baseUriWarnings('', 'http://localhost'),
    ]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach (['Dump is out of date (2 changes since', 'No base URI is set', 'The base URI uses http'] as $expected) {
        smokeAssert(str_contains($status, $expected), "The status panel lacks: {$expected}");
    }

    $render = (string) getenv('DRE_LD_RENDER_HTML');
    if ($render !== '') {
        file_put_contents($render, $html);
    }

    printf(
        "PASS  Omeka S %s integration smoke (SQL visibility, %d routes, controllers, headers, dump, 4 formats agree, conneg on real routes, helper, form, block)\n",
        Omeka\Module::VERSION,
        count($config['router']['routes'])
    );
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("FAIL  %s\n%s\n", $e->getMessage(), $e->getTraceAsString()));
    $exitCode = 1;
} finally {
    DumpStore::inFilesPath($filesPath)->clear();
    @rmdir($filesPath);
}

exit($exitCode);
