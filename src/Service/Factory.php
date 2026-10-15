<?php
declare(strict_types=1);

namespace DRELinkedData\Service;

use DRELinkedData\Controller\DatasetController;
use DRELinkedData\Controller\ResourceController;
use DRELinkedData\Dataset\DatasetDescription;
use DRELinkedData\Dataset\DatasetSummary;
use DRELinkedData\Dataset\LandingPage;
use DRELinkedData\Dump\ChangeTracker;
use DRELinkedData\Dump\DumpGenerator;
use DRELinkedData\Dump\DumpStore;
use DRELinkedData\Projection\IdentityLinks;
use DRELinkedData\Projection\OmekaSource;
use DRELinkedData\Projection\ResourceProjector;
use DRELinkedData\Projection\SameAs;
use DRELinkedData\Projection\UriScheme;
use DRELinkedData\Rdf\PrefixMap;
use DRELinkedData\Rdf\RdfWriter;
use DRELinkedData\Settings\DatasetSettings;
use DRELinkedData\Site\BlockLayout\LinkedDataBlock;
use DRELinkedData\View\Helper\LinkedData;
use Laminas\ServiceManager\Exception\ServiceNotCreatedException;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * One factory for every class the module registers — services, controllers,
 * the block layout and the view helper. Laminas hands plugin-manager factories
 * the application container, so a single match on the requested name covers
 * them all and keeps the wiring readable in one place.
 */
final class Factory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): object
    {
        $config = $container->get('Config')['dre_linked_data'] ?? [];

        return match ($requestedName) {
            OmekaSource::class => new OmekaSource($container->get('Omeka\Connection')),
            UriScheme::class => new UriScheme(
                self::baseUri($container),
                static fn (): ?string => $container->get(DefaultSite::class)->slug()
            ),
            IdentityLinks::class => new IdentityLinks((array) ($config['projection']['identity_links'] ?? [])),
            ResourceProjector::class => new ResourceProjector(
                $container->get(OmekaSource::class),
                $container->get(UriScheme::class),
                $container->get(IdentityLinks::class),
                (array) ($config['projection'] ?? [])
            ),
            RdfWriter::class => new RdfWriter(
                static fn (): PrefixMap => PrefixMap::withVocabularies($container->get(OmekaSource::class)->vocabularies())
            ),
            DumpStore::class => DumpStore::inFilesPath(self::filesPath($container)),
            DumpGenerator::class => new DumpGenerator(
                $container->get(ResourceProjector::class),
                $container->get(OmekaSource::class),
                $container->get(DumpStore::class),
                $container->get(RdfWriter::class)->prefixes(),
                $container->get('Omeka\Logger'),
                max(1, (int) ($config['dump']['batch_size'] ?? 250)),
                $container->get(ChangeTracker::class)
            ),
            ChangeTracker::class => new ChangeTracker(
                $container->get('Omeka\Settings'),
                $container->get(DumpStore::class)
            ),
            SameAs::class => new SameAs(
                $container->get(OmekaSource::class),
                $container->get(IdentityLinks::class)
            ),
            DatasetDescription::class => new DatasetDescription(
                $container->get(UriScheme::class),
                $container->get(IdentityLinks::class),
                array_values(array_filter((array) ($config['dataset']['keywords'] ?? []), 'is_string'))
            ),
            DatasetSummary::class => new DatasetSummary(
                $container->get(DumpStore::class),
                $container->get(UriScheme::class),
                $container->get(IdentityLinks::class),
                static fn (): PrefixMap => $container->get(RdfWriter::class)->prefixes(),
                static fn (int $id): bool => $container->get(OmekaSource::class)->isVisible($id),
                $container->get(ChangeTracker::class)
            ),
            DefaultSite::class => new DefaultSite($container->get('Omeka\ApiManager'), $container->get('Omeka\Settings')),
            ResourceController::class => new ResourceController(
                $container->get(ResourceProjector::class),
                $container->get(UriScheme::class),
                $container->get(RdfWriter::class),
                $container->get('Omeka\Settings')
            ),
            DatasetController::class => new DatasetController(
                $container->get(DatasetDescription::class),
                $container->get(DumpStore::class),
                $container->get(UriScheme::class),
                $container->get(RdfWriter::class),
                $container->get(DefaultSite::class),
                $container->get('Omeka\Settings'),
                $container->get(LandingPage::class)
            ),
            LandingPage::class => new LandingPage(
                $container->get(OmekaSource::class),
                $container->get(DefaultSite::class),
                $container->get(UriScheme::class)
            ),
            // The block layout is registered under its layout name.
            'dreLinkedData', LinkedDataBlock::class => new LinkedDataBlock(
                $container->get(DatasetSummary::class),
                $container->get(DefaultSite::class),
                $container->get('Omeka\Settings')
            ),
            LinkedData::class => new LinkedData(
                $container->get(UriScheme::class),
                $container->get(SameAs::class),
                static fn (): array => $container->get(DatasetSummary::class)->dataset(
                    DatasetSettings::fromSettings($container->get('Omeka\Settings'), $container->get(DefaultSite::class)->title()),
                    $container->get(LandingPage::class)->url()
                )
            ),
            default => throw new ServiceNotCreatedException(sprintf('%s cannot create %s.', self::class, $requestedName)),
        };
    }

    /**
     * The configured base URI, else the address Omeka is being reached at.
     * The fallback works in web requests and in jobs (Omeka passes the
     * dispatching request's server URL to the job process), but identifiers
     * are meant to be permanent, so production should configure it.
     */
    public static function baseUri(ContainerInterface $container): string
    {
        $configured = trim((string) $container->get('Omeka\Settings')->get(DatasetSettings::BASE_URI, ''));
        if ($configured !== '') {
            return $configured;
        }
        try {
            $url = $container->get('ViewHelperManager')->get('url');
            return rtrim((string) $url('top', [], ['force_canonical' => true]), '/');
        } catch (\Throwable $exception) {
            throw new ServiceNotCreatedException(
                'DRELinkedData cannot determine its base URI here; set it under Modules → DRE Linked Data → Configure.',
                0,
                $exception
            );
        }
    }

    public static function filesPath(ContainerInterface $container): string
    {
        $config = $container->get('Config');
        return (string) ($config['file_store']['local']['base_path']
            ?? (defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : sys_get_temp_dir()));
    }
}
