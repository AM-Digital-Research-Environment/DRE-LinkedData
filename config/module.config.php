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
            Dataset\LandingPage::class => Service\Factory::class,
            Service\DefaultSite::class => Service\Factory::class,
            Dump\ChangeTracker::class => Service\Factory::class,
            Projection\SameAs::class => Service\Factory::class,
            Settings\DatasetSettings::class => Service\Factory::class,
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
                        'action' => 'resource',
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

            // rdf:type added to every item on a resource template, beside the
            // classes above. Research items (template 10) carry no resource
            // class in Omeka, so schema:CreativeWork alone did not tell them
            // from anything else without one; dre:ResearchItem does. The class
            // is declared in the dre: ontology, which omeka-s-docker maintains
            // (deploy/amira/vocabularies/build_dre_vocabulary.py).
            'template_classes' => [
                10 => ['https://data.africamultiple.uni-bayreuth.de/ontology/dre#ResearchItem'],
            ],

            // A finer class from a linked value: on the templates listed, an
            // item whose `property` links one of these records also gets its
            // class. Research items carry exactly one Type of Resource (item
            // set 1, MODS typeOfResource values), each mapped to the DCMI Type
            // its own definition names, following DCMI's examples (maps and
            // notated music are dctype:Image, manuscripts and bibliographies
            // dctype:Text). Digital, Multimedia, Mixed material, Tactile and
            // Unspecified have no single DCMI Type and add nothing.
            'type_classes' => [
                'property' => 'dcterms:type',
                'templates' => [10],
                'classes' => [
                    2 => 'http://purl.org/dc/dcmitype/PhysicalObject', // Artefact
                    3 => 'http://purl.org/dc/dcmitype/Sound',          // Audio
                    4 => 'http://purl.org/dc/dcmitype/Image',          // Notated music
                    5 => 'http://purl.org/dc/dcmitype/Image',          // Cartographic
                    6 => 'http://purl.org/dc/dcmitype/StillImage',     // Image
                    7 => 'http://purl.org/dc/dcmitype/MovingImage',    // Moving image
                    8 => 'http://purl.org/dc/dcmitype/Text',           // Text
                    9 => 'http://purl.org/dc/dcmitype/Text',           // Manuscript
                    10 => 'http://purl.org/dc/dcmitype/Text',          // Bibliography
                    11 => 'http://purl.org/dc/dcmitype/Dataset',       // Dataset
                    10309 => 'http://purl.org/dc/dcmitype/Collection', // Collection
                ],
            ],

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

            // Generic agent links. A role property (any property of these
            // vocabularies: the MARC relators, 305 in Omeka's copy) linking a
            // visible person or organisation is also stated as dcterms:creator
            // or dcterms:contributor, so "everything linked to a person" does
            // not need the forty-odd role codes the data uses. Library of
            // Congress declares every relator rdfs:subPropertyOf
            // dc:contributor, and lists the creator roles in its RDA Creator
            // collection (`collection_RDACreator`); those 23 are the creator
            // roles here, checked against id.loc.gov on 25 November 2026. A link
            // to anything else (a place, through marcrel:pup) gets no generic
            // statement, nor does a literal name.
            'agent_roles' => [
                'property_namespaces' => ['http://id.loc.gov/vocabulary/relators/'],
                'agent_classes' => [
                    'http://xmlns.com/foaf/0.1/Agent',
                    'http://xmlns.com/foaf/0.1/Person',
                    'http://xmlns.com/foaf/0.1/Organization',
                    'http://xmlns.com/foaf/0.1/Group',
                ],
                'creator_predicate' => 'http://purl.org/dc/terms/creator',
                'contributor_predicate' => 'http://purl.org/dc/terms/contributor',
                'creator_roles' => array_map(
                    static fn (string $code): string => 'http://id.loc.gov/vocabulary/relators/' . $code,
                    [
                        'arc', 'art', 'aus', 'aut', 'chr', 'cmp', 'com', 'cre', 'ctg', 'dsr', 'enj', 'fmk',
                        'inv', 'ive', 'ivr', 'lbt', 'lsa', 'ltr', 'pht', 'pra', 'prg', 'rsp', 'scl',
                    ]
                ),
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
                // The allowlist: config/identity-authorities.php, which DRE SEO
                // carries byte-identically, so a record's sameAs is the same
                // whichever module names it. Each authority has a pattern (PCRE
                // matched against the stored URI), canonical (its replacement,
                // the authority's own entity IRI), label, dataset (the target
                // dataset's homepage) and uri_space (its entity IRIs), the last
                // two for the VoID linksets. Edit that file, not this key.
                'authorities' => require __DIR__ . '/identity-authorities.php',
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
