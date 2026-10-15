<?php
declare(strict_types=1);

/**
 * DRE Linked Data module configuration.
 *
 * Wires the public Linked Data endpoints (top-level routes that fall through
 * nginx's `try_files … /index.php` to Omeka, like DRE SEO's sitemap), the
 * page block, the view helper the theme can call, and the instance-specific
 * `dre_linked_data` block (overridable, key by key, via config/local.config.php).
 */

namespace DRELinkedData;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;

return [
    'service_manager' => [
        'factories' => [
            Projection\OmekaSource::class => Service\Factory::class,
            Projection\UriScheme::class => Service\Factory::class,
            Projection\IdentityLinks::class => Service\Factory::class,
            Projection\ResourceProjector::class => Service\Factory::class,
            Rdf\RdfWriter::class => Service\Factory::class,
            Dump\DumpStore::class => Service\Factory::class,
            Dump\DumpGenerator::class => Service\Factory::class,
            Dataset\DatasetDescription::class => Service\Factory::class,
            Dataset\DatasetSummary::class => Service\Factory::class,
            Service\DefaultSite::class => Service\Factory::class,
            Dump\ChangeTracker::class => Service\Factory::class,
            Projection\SameAs::class => Service\Factory::class,
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\ResourceController::class => Service\Factory::class,
            Controller\DatasetController::class => Service\Factory::class,
        ],
    ],

    'block_layouts' => [
        'factories' => [
            'dreLinkedData' => Service\Factory::class,
        ],
    ],

    // dreLinkedData($resource) — the resource's URI, record page, identity
    // links and RDF documents, for the theme's record apparatus and DRE SEO;
    // dreLinkedData()->dataset() — the dataset as a whole. Consumers guard on
    // the helper's existence.
    'view_helpers' => [
        'factories' => [
            View\Helper\LinkedData::class => Service\Factory::class,
        ],
        'aliases' => [
            'dreLinkedData' => View\Helper\LinkedData::class,
        ],
    ],

    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],

    'router' => [
        'routes' => [
            // /resource/{id} (303) and /resource/{id}.{ttl|nt|jsonld|rdf}. The
            // extensions are Rdf\Format's (a unit test keeps them in step).
            'dre-linked-data-resource' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/resource/:id[.:format]',
                    'constraints' => ['id' => '\d+', 'format' => 'ttl|nt|jsonld|rdf'],
                    'defaults' => [
                        'controller' => Controller\ResourceController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            // /resource/{id}/ — a trailing slash 301s to the identifier proper.
            'dre-linked-data-resource-slash' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/resource/:id/',
                    'constraints' => ['id' => '\d+'],
                    'defaults' => [
                        'controller' => Controller\ResourceController::class,
                        'action' => 'slash',
                    ],
                ],
            ],
            'dre-linked-data-void' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/linked-data/void[.:format]',
                    'constraints' => ['format' => 'ttl|nt|jsonld|rdf'],
                    'defaults' => [
                        'controller' => Controller\DatasetController::class,
                        'action' => 'void',
                    ],
                ],
            ],
            'dre-linked-data-dump' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/linked-data/dump.nt.gz',
                    'defaults' => [
                        'controller' => Controller\DatasetController::class,
                        'action' => 'dump',
                    ],
                ],
            ],
            // The VoID well-known location. Note the AMIRA nginx config denies
            // every dot-path; it needs an exception for /.well-known/ to reach this.
            'dre-linked-data-well-known' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/.well-known/void',
                    'defaults' => [
                        'controller' => Controller\DatasetController::class,
                        'action' => 'wellKnown',
                    ],
                ],
            ],
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],

    // ── Instance configuration (override via config/local.config.php) ───────
    'dre_linked_data' => [
        'projection' => [
            // Never published, even when a value is marked public: the sync's
            // reconciliation key, the data-sensitivity level and internal
            // filenames. Omeka already keeps them private; this is the second lock.
            // bibo:content (full texts and transcripts) is excluded too: the
            // dataset's licence covers the metadata, not the works themselves,
            // and one thesis would otherwise weigh close to a megabyte of Turtle.
            'excluded_terms' => ['dre:mongoId', 'dre:security', 'dre:bitstream', 'bibo:content'],

            // rdf:type added to every item set / media, beside any resource class.
            'item_set_classes' => ['http://purl.org/dc/dcmitype/Collection'],
            'media_classes' => ['https://schema.org/MediaObject'],
            // rdf:type for an item that would otherwise have none: research
            // items carry no resource class in Omeka.
            'item_fallback_classes' => ['https://schema.org/CreativeWork'],

            // Resource classes published as another class. Authority Resources
            // (subjects, languages, genres, …) are classed fabio:AuthorityFile in
            // Omeka, a FaBiO *document*; they are concepts. A skos:Concept also
            // gets skos:prefLabel and skos:inScheme for each of its item sets.
            'class_replacements' => [
                'http://purl.org/spar/fabio/AuthorityFile' => 'http://www.w3.org/2004/02/skos/core#Concept',
            ],

            // Properties whose range is an ordered list (bibo:authorList, …). Omeka
            // stores the members as plain values, in order; they are published as
            // a real rdf:List with hash-IRI nodes, and each linked member also
            // under the flat predicate named here.
            'list_properties' => [
                'bibo:authorList' => 'http://purl.org/dc/terms/creator',
                'bibo:editorList' => 'http://purl.org/dc/terms/contributor',
            ],

            // Literals published as xsd:decimal when they are plain decimals.
            'decimal_properties' => ['geo:lat', 'geo:long'],

            // Properties whose range is a literal. A URI value on one of them is
            // published as a string (its label too, when it has one), and the
            // link itself as an identity link or, failing that, rdfs:seeAlso.
            'literal_properties' => ['dcterms:identifier'],

            'identity_links' => [
                // Properties whose URI values are checked against the authorities.
                'properties' => ['dcterms:identifier'],
                'default_predicate' => 'http://www.w3.org/2002/07/owl#sameAs',
                // Authority Resources (subjects, languages, genres, …) are
                // concepts, matched rather than identical — see
                // MongoDB2OmekaS config/profiles/amira.json for the template id.
                'template_predicates' => [
                    6 => 'http://www.w3.org/2004/02/skos/core#exactMatch',
                ],
                // pattern: PCRE matched against the stored URI; canonical: its
                // replacement (the authority's own entity IRI); dataset: the
                // target dataset's homepage and uri_space its entity IRIs, both
                // for the VoID linksets.
                'authorities' => [
                    'wikidata' => [
                        'label' => 'Wikidata',
                        'pattern' => '~^https?://www\.wikidata\.org/(?:entity|wiki)/(Q[1-9][0-9]*)$~',
                        'canonical' => 'http://www.wikidata.org/entity/$1',
                        'dataset' => 'https://www.wikidata.org/',
                        'uri_space' => 'http://www.wikidata.org/entity/',
                    ],
                    'loc' => [
                        'label' => 'Library of Congress',
                        'pattern' => '~^https?://id\.loc\.gov/authorities/(subjects|names|genreForms)/([a-z]+[0-9]+)(?:\.html)?$~',
                        'canonical' => 'http://id.loc.gov/authorities/$1/$2',
                        'dataset' => 'http://id.loc.gov/',
                        'uri_space' => 'http://id.loc.gov/authorities/',
                    ],
                    'gnd' => [
                        'label' => 'GND',
                        'pattern' => '~^https?://d-nb\.info/gnd/([0-9X-]+)$~',
                        'canonical' => 'https://d-nb.info/gnd/$1',
                        'dataset' => 'https://d-nb.info/gnd/',
                        'uri_space' => 'https://d-nb.info/gnd/',
                    ],
                    'viaf' => [
                        'label' => 'VIAF',
                        'pattern' => '~^https?://(?:www\.)?viaf\.org/viaf/([0-9]+)/?$~',
                        'canonical' => 'http://viaf.org/viaf/$1',
                        'dataset' => 'http://viaf.org/',
                        'uri_space' => 'http://viaf.org/viaf/',
                    ],
                    'orcid' => [
                        'label' => 'ORCID',
                        'pattern' => '~^https?://orcid\.org/([0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X])$~',
                        'canonical' => 'https://orcid.org/$1',
                        'dataset' => 'https://orcid.org/',
                        'uri_space' => 'https://orcid.org/',
                    ],
                    'ror' => [
                        'label' => 'ROR',
                        'pattern' => '~^https?://ror\.org/(0[a-z0-9]{8})$~',
                        'canonical' => 'https://ror.org/$1',
                        'dataset' => 'https://ror.org/',
                        'uri_space' => 'https://ror.org/',
                    ],
                    'geonames' => [
                        'label' => 'GeoNames',
                        'pattern' => '~^https?://(?:www\.|sws\.)?geonames\.org/([0-9]+)(?:/.*)?$~',
                        'canonical' => 'https://sws.geonames.org/$1/',
                        'dataset' => 'https://www.geonames.org/',
                        'uri_space' => 'https://sws.geonames.org/',
                    ],
                ],
                // DOIs: the properties that hold one, stored as a bare DOI or a
                // resolver URL. Each is published as the bare DOI literal (what
                // bibo:doi expects) plus a link to its https://doi.org/ IRI.
                'doi' => [
                    'properties' => ['bibo:doi'],
                    'label' => 'DOI',
                    'predicate' => 'https://schema.org/sameAs',
                    'dataset' => 'https://doi.org/',
                    'uri_space' => 'https://doi.org/',
                ],
            ],
        ],
        'dump' => [
            // Resources projected per batch; each batch is a handful of queries.
            'batch_size' => 250,
        ],
        'dataset' => [
            // dcat:keyword on the dataset, tagged @en.
            'keywords' => [
                'Africa',
                'African studies',
                'research data',
                'cultural heritage',
                'Linked Open Data',
            ],
        ],
    ],
];
