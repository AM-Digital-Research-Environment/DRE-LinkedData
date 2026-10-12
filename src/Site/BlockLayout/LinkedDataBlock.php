<?php
declare(strict_types=1);

namespace DRELinkedData\Site\BlockLayout;

use DRELinkedData\Dataset\DatasetSummary;
use DRELinkedData\Service\DefaultSite;
use DRELinkedData\Settings\DatasetSettings;
use Laminas\Form\Element;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Site\BlockLayout\TemplateableBlockLayoutInterface;

/**
 * "Linked open data" page block: the human entry point to the dataset — what
 * it contains, how its URIs work, and where the dump and the VoID description
 * are. Meant for a "Data access" or "About the data" site page.
 */
class LinkedDataBlock extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
{
    public function __construct(
        private readonly DatasetSummary $summary,
        private readonly DefaultSite $site,
        private readonly Settings $settings,
    ) {
    }

    public function getLabel()
    {
        return 'Linked open data'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        $data = $block ? $block->data() : [];

        $heading = new Element\Text('o:block[__blockIndex__][o:data][heading]');
        $heading->setOptions([
            'label' => 'Heading', // @translate
            'info' => 'Optional. Leave empty when the page title already introduces the block.', // @translate
        ]);
        $heading->setValue((string) ($data['heading'] ?? ''));

        $example = new Element\Number('o:block[__blockIndex__][o:data][example_id]');
        $example->setOptions([
            'label' => 'Example resource ID', // @translate
            'info' => 'Optional. The public item used to demonstrate the URI pattern. By default, the most richly described item in the latest dump.', // @translate
        ]);
        $example->setAttributes(['min' => 1, 'step' => 1]);
        $example->setValue(isset($data['example_id']) && (int) $data['example_id'] > 0 ? (string) (int) $data['example_id'] : '');

        return $view->formRow($heading) . $view->formRow($example);
    }

    public function prepareRender(PhpRenderer $view)
    {
        $view->headLink()->appendStylesheet($view->assetUrl('css/dre-linked-data.css', 'DRELinkedData'));
    }

    public function render(
        PhpRenderer $view,
        SitePageBlockRepresentation $block,
        $templateViewScript = 'common/block-layout/dre-linked-data'
    ) {
        $exampleId = (int) $block->dataValue('example_id', 0);
        return $view->partial($templateViewScript, [
            'block' => $block,
            'heading' => trim((string) $block->dataValue('heading', '')),
            'summary' => $this->summary->build(
                DatasetSettings::fromSettings($this->settings, $this->site->title()),
                $exampleId > 0 ? $exampleId : null
            ),
        ]);
    }
}
