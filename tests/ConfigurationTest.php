<?php
declare(strict_types=1);

use DRELinkedData\Projection\IdentityLinks;
use DRELinkedData\Projection\SameAs;
use DRELinkedData\Rdf\Format;
use DRELinkedData\Settings\DatasetSettings;

test('authority patterns accept the identifier shapes AMIRA stores, in canonical form', function (): void {
    $links = new IdentityLinks(moduleConfig()['projection']['identity_links']);
    // Every shape below occurs on data.africamultiple.uni-bayreuth.de (survey of 2026-10-05).
    $cases = [
        ['http://www.wikidata.org/entity/Q408', 3, 'wikidata', 'http://www.wikidata.org/entity/Q408'],
        ['https://www.wikidata.org/wiki/Q707283', 2, 'wikidata', 'http://www.wikidata.org/entity/Q707283'],
        ['http://id.loc.gov/authorities/subjects/sh00002539', 6, 'loc', 'http://id.loc.gov/authorities/subjects/sh00002539'],
        ['https://id.loc.gov/authorities/subjects/sh85149367.html', 6, 'loc', 'http://id.loc.gov/authorities/subjects/sh85149367'],
        ['https://d-nb.info/gnd/124120881', 4, 'gnd', 'https://d-nb.info/gnd/124120881'],
        ['https://d-nb.info/gnd/4074195-3', 4, 'gnd', 'https://d-nb.info/gnd/4074195-3'],
    ];
    foreach ($cases as [$uri, $template, $authority, $canonical]) {
        $match = $links->match($uri, $template);
        assertTrue($match !== null, $uri . ' should match');
        assertSameValue($authority, $match['authority'], $uri);
        assertSameValue($canonical, $match['iri'], $uri);
    }
    assertSameValue('http://www.w3.org/2004/02/skos/core#exactMatch', $links->match('https://www.wikidata.org/wiki/Q34770', 6)['predicate']);
    assertSameValue('http://www.w3.org/2002/07/owl#sameAs', $links->match('https://www.wikidata.org/wiki/Q34770', 3)['predicate']);
});

test('identifiers that are not identities stay plain identifiers', function (): void {
    $links = new IdentityLinks(moduleConfig()['projection']['identity_links']);
    foreach ([
        'https://www.youtube.com/playlist?list=PLDlHB4qY36WjCq9RIsxLzeckaPB7nQxz9',
        'https://gepris.dfg.de/gepris/projekt/390713894?language=en',
        'https://www.wikidata.org/wiki/Special:EntityPage/Q42',
        'https://doi.org/10.1017/S0021853721000372',
    ] as $uri) {
        assertSameValue(null, $links->match($uri, 6), $uri);
    }
    assertTrue($links->appliesTo('dcterms:identifier'));
    assertTrue(!$links->appliesTo('dre:collectionUrl'), 'a Digital Collections permalink is a page, not an identity');
});

test('every authority pattern compiles and names its target dataset', function (): void {
    foreach (moduleConfig()['projection']['identity_links']['authorities'] as $name => $authority) {
        assertTrue(@preg_match($authority['pattern'], '') !== false, $name . ' pattern must compile');
        assertTrue(str_starts_with($authority['dataset'], 'http'), $name . ' needs a dataset IRI for its VoID linkset');
        assertTrue(str_starts_with($authority['uri_space'] ?? '', 'http'), $name . ' needs a URI space for its VoID target');
        assertTrue(($authority['label'] ?? '') !== '', $name . ' needs a label');
    }
    $links = new IdentityLinks(moduleConfig()['projection']['identity_links']);
    assertSameValue('DOI', $links->authorities()['doi']['label'] ?? null, 'DOIs are a link target too');
});

test('DOIs are recognised in every stored shape and nothing else', function (): void {
    $links = new IdentityLinks(moduleConfig()['projection']['identity_links']);
    foreach ([
        '10.1017/S0021853721000372',
        'doi:10.1017/S0021853721000372',
        'https://doi.org/10.1017/S0021853721000372',
        'http://dx.doi.org/10.1017/S0021853721000372',
        ' HTTPS://DOI.ORG/10.1017/S0021853721000372 ',
    ] as $stored) {
        $doi = $links->doi($stored);
        assertSameValue('10.1017/S0021853721000372', $doi['doi'] ?? null, $stored);
        assertSameValue('https://doi.org/10.1017/S0021853721000372', $doi['iri'] ?? null, $stored);
        assertSameValue('https://schema.org/sameAs', $doi['predicate'] ?? null, $stored);
    }
    foreach (['', 'eref-67371', '10.1017', 'https://example.org/10.1017/S0021853721000372'] as $notDoi) {
        assertSameValue(null, $links->doi($notDoi), $notDoi);
    }
    assertTrue($links->holdsDoi('bibo:doi'));
    assertTrue(!$links->holdsDoi('dcterms:identifier'));
});

test('private sync and access-control terms, and full texts, are on the exclusion list', function (): void {
    $excluded = moduleConfig()['projection']['excluded_terms'];
    foreach (['dre:mongoId', 'dre:security', 'dre:bitstream', 'bibo:content'] as $term) {
        assertTrue(in_array($term, $excluded, true), $term);
    }
});

test('routes, controllers and services are all wired through the factory', function (): void {
    $config = include dirname(__DIR__) . '/config/module.config.php';
    $factories = $config['controllers']['factories'];
    foreach ($config['router']['routes'] as $name => $route) {
        assertTrue(str_starts_with($name, 'dre-linked-data-'), $name . ' must carry the module prefix');
        $controller = $route['options']['defaults']['controller'];
        assertTrue(isset($factories[$controller]), $name . ' points at an unregistered controller');
    }
    $factorySource = (string) file_get_contents(dirname(__DIR__) . '/src/Service/Factory.php');
    foreach (array_merge(
        array_keys($config['service_manager']['factories']),
        array_keys($config['controllers']['factories']),
        array_keys($config['view_helpers']['factories'])
    ) as $class) {
        $short = substr((string) strrchr('\\' . $class, '\\'), 1);
        assertTrue(str_contains($factorySource, $short . '::class =>') || str_contains($factorySource, $short . '::class => new'), $class . ' has no arm in Service\\Factory');
    }
    assertTrue(str_contains($factorySource, "'dreLinkedData', LinkedDataBlock::class"), 'the block layout name has an arm');
});

test('settings keys share one prefix and versions agree', function (): void {
    foreach (DatasetSettings::KEYS as $key) {
        assertTrue(str_starts_with($key, 'dre_ld_'), $key);
    }
    $root = dirname(__DIR__);
    preg_match('/^\s*version\s*=\s*"([^"]+)"/m', (string) file_get_contents($root . '/config/module.ini'), $ini);
    preg_match('/^version:\s*"?([^"\s]+)"?/m', (string) file_get_contents($root . '/CITATION.cff'), $cff);
    assertSameValue($ini[1] ?? null, $cff[1] ?? null, 'module.ini and CITATION.cff must declare the same version');
});

test('the vendored token table is the theme’s, untouched', function (): void {
    $table = json_decode((string) file_get_contents(dirname(__DIR__) . '/scripts/lib/dre-tokens-fallback.json'), true);
    assertTrue(is_array($table) && isset($table['shared']['--space-4'], $table['light']['--surface']), 'token table must parse');
    $rules = (string) file_get_contents(dirname(__DIR__) . '/scripts/lib/token-rules.mjs');
    assertTrue(str_starts_with($rules, "/**\n * VENDORED from DRE-theme"), 'token-rules.mjs must be the vendored copy');
});

test('a DOI never decodes into a fragment or a query of its IRI', function (): void {
    $links = new IdentityLinks(moduleConfig()['projection']['identity_links']);
    $hash = $links->doi('https://doi.org/10.1000/abc%23def');
    assertSameValue('10.1000/abc#def', $hash['doi'], 'the DOI itself may contain #');
    assertSameValue('https://doi.org/10.1000/abc%23def', $hash['iri']);
    $query = $links->doi('doi:10.1000/a%3Fb%25c');
    assertSameValue('10.1000/a?b%c', $query['doi']);
    assertSameValue('https://doi.org/10.1000/a%3Fb%25c', $query['iri']);
    $url = $links->doi('https://doi.org/10.1000/xyz?utm_source=feed#top');
    assertSameValue('10.1000/xyz', $url['doi'], 'a resolver URL\'s own query and fragment are not the DOI');
    assertSameValue(null, $links->doi('https://doi.org/10.1000/?x'));
});

test('identity links are reported per value exactly as the projection states them', function (): void {
    $links = new IdentityLinks(moduleConfig()['projection']['identity_links']);
    assertSameValue(['dcterms:identifier', 'bibo:doi'], $links->terms());
    assertSameValue('https://doi.org/10.1017/X1', $links->linkFor('bibo:doi', null, '10.1017/X1', null)['iri']);
    assertSameValue('http://www.wikidata.org/entity/Q1', $links->linkFor('dcterms:identifier', 'https://www.wikidata.org/wiki/Q1', 'Q1', 6)['iri']);
    assertSameValue(null, $links->linkFor('dcterms:identifier', null, 'Q1', null), 'a literal identifier is no link');
    assertSameValue(null, $links->linkFor('dre:collectionUrl', 'https://www.wikidata.org/wiki/Q1', null, null));

    $source = amiraSource();
    $sameAs = new SameAs($source, $links);
    assertSameValue(['https://d-nb.info/gnd/124120881'], $sameAs->of(149, 4));
    assertSameValue(['https://doi.org/10.1017/S0021853721000372', 'https://doi.org/10.1086/ahr/110.2.%3C123%3E'], $sameAs->of(7392, 10));
    assertSameValue(['http://id.loc.gov/authorities/subjects/sh85149367'], $sameAs->of(2390, 6), 'skos:exactMatch targets count; the playlist does not');
    assertSameValue([], $sameAs->of(6259, null));
    $sameAs->of(149, 4);
    assertSameValue(4, count($source->termRequests), 'one query per resource, remembered');

    // The helper's list is the projection's list.
    $projected = [];
    foreach (amiraProjector()->describeOne(7392)->triples as $triple) {
        if (in_array($triple->predicate->value, ['http://www.w3.org/2002/07/owl#sameAs', 'http://www.w3.org/2004/02/skos/core#exactMatch', 'https://schema.org/sameAs'], true)) {
            $projected[] = $triple->object->value;
        }
    }
    assertSameValue($projected, $sameAs->of(7392, 10));
});

test('route extensions follow the formats, and the projection config has its new keys', function (): void {
    $config = include dirname(__DIR__) . '/config/module.config.php';
    $routes = $config['router']['routes'];
    assertSameValue(Format::extensionPattern(), $routes['dre-linked-data-resource']['options']['constraints']['format']);
    assertSameValue(Format::extensionPattern(), $routes['dre-linked-data-void']['options']['constraints']['format']);
    assertSameValue('slash', $routes['dre-linked-data-resource-slash']['options']['defaults']['action']);
    assertTrue(in_array('dcterms:identifier', $config['dre_linked_data']['projection']['literal_properties'], true));
    assertTrue(count($config['dre_linked_data']['dataset']['keywords']) >= 3, 'a few dataset keywords');
});
