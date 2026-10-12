<?php
declare(strict_types=1);

namespace DRELinkedData\Form;

use DRELinkedData\Settings\DatasetSettings;
use Laminas\Filter\StringTrim;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\InputFilter\InputFilterProviderInterface;
use Laminas\Validator\Regex;

/**
 * Module configuration form (Modules → DRE Linked Data → Configure).
 *
 * Rendered inside Omeka's own module-config <form>, which supplies the CSRF
 * token, so this only contributes fields. Every field is optional: unset, the
 * base URI follows the address Omeka is reached at and the dataset is named
 * after the site.
 */
class ConfigForm extends Form implements InputFilterProviderInterface
{
    public const REGENERATE = 'dre_ld_regenerate';

    private const HTTP_URI = '~^https?://[^\s?#]+(?:\?[^\s#]*)?$~i';

    public function init(): void
    {
        $this->add([
            'name' => DatasetSettings::BASE_URI,
            'type' => Element\Url::class,
            'options' => [
                'label' => 'Base URI', // @translate
                'info' => 'Every resource is identified as {base}/resource/{id}. Identifiers are meant to be permanent: set this once to the public address of the site (no trailing slash) and do not change it afterwards. Leave empty to use the address Omeka is currently reached at.', // @translate
            ],
            'attributes' => ['id' => DatasetSettings::BASE_URI],
        ]);

        $this->add([
            'name' => DatasetSettings::TITLE,
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Dataset title', // @translate
                'info' => 'Used in the VoID/DCAT description and on the Linked open data page block. Defaults to the site title.', // @translate
            ],
            'attributes' => ['id' => DatasetSettings::TITLE],
        ]);

        $this->add([
            'name' => DatasetSettings::DESCRIPTION,
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'Dataset description', // @translate
                'info' => 'One or two sentences on what the dataset covers, for data catalogues and the page block.', // @translate
            ],
            'attributes' => ['id' => DatasetSettings::DESCRIPTION, 'rows' => 3],
        ]);

        $this->add([
            'name' => DatasetSettings::PUBLISHER_NAME,
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Publisher name', // @translate
                'info' => 'The organisation responsible for the dataset.', // @translate
            ],
            'attributes' => ['id' => DatasetSettings::PUBLISHER_NAME],
        ]);

        $this->add([
            'name' => DatasetSettings::PUBLISHER_URI,
            'type' => Element\Url::class,
            'options' => [
                'label' => 'Publisher URI', // @translate
                'info' => 'Optional. An identifier for the publisher, such as its Wikidata entity or ROR record.', // @translate
            ],
            'attributes' => ['id' => DatasetSettings::PUBLISHER_URI],
        ]);

        $this->add([
            'name' => DatasetSettings::LICENCE,
            'type' => Element\Url::class,
            'options' => [
                'label' => 'Metadata licence', // @translate
                'info' => 'The licence URI for the published metadata, e.g. https://creativecommons.org/publicdomain/zero/1.0/ or https://creativecommons.org/licenses/by/4.0/. It is stated on every RDF document and on the dump. Nothing is claimed while this is empty.', // @translate
            ],
            'attributes' => ['id' => DatasetSettings::LICENCE],
        ]);

        $this->add([
            'name' => self::REGENERATE,
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Generate the dump now', // @translate
                'info' => 'Starts a background job that rewrites the N-Triples dump and the figures in the dataset description. Run it after a sync: individual resource documents are always live, the dump is not.', // @translate
            ],
            'attributes' => ['id' => self::REGENERATE],
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    public function getInputFilterSpecification(): array
    {
        $uri = static fn (string $name): array => [
            'name' => $name,
            'required' => false,
            'filters' => [['name' => StringTrim::class]],
            'validators' => [[
                'name' => Regex::class,
                'options' => [
                    'pattern' => self::HTTP_URI,
                    'messages' => [Regex::NOT_MATCH => 'Enter an absolute http(s) URI.'], // @translate
                ],
            ]],
        ];
        $text = static fn (string $name): array => [
            'name' => $name,
            'required' => false,
            'filters' => [['name' => StringTrim::class]],
        ];

        $baseUri = $uri(DatasetSettings::BASE_URI);
        $baseUri['validators'][0]['options']['pattern'] = '~^https?://[^\s?#]+$~i';
        $baseUri['validators'][0]['options']['messages'][Regex::NOT_MATCH]
            = 'Enter an absolute http(s) URI without a query or fragment.'; // @translate

        return [
            DatasetSettings::BASE_URI => $baseUri,
            DatasetSettings::TITLE => $text(DatasetSettings::TITLE),
            DatasetSettings::DESCRIPTION => $text(DatasetSettings::DESCRIPTION),
            DatasetSettings::PUBLISHER_NAME => $text(DatasetSettings::PUBLISHER_NAME),
            DatasetSettings::PUBLISHER_URI => $uri(DatasetSettings::PUBLISHER_URI),
            DatasetSettings::LICENCE => $uri(DatasetSettings::LICENCE),
            self::REGENERATE => ['name' => self::REGENERATE, 'required' => false],
        ];
    }
}
