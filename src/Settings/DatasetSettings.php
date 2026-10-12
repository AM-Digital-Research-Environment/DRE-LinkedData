<?php
declare(strict_types=1);

namespace DRELinkedData\Settings;

use Omeka\Settings\Settings;

/**
 * The editorial settings (Modules → DRE Linked Data → Configure).
 *
 * The keys here are the module's whole persistent settings surface; uninstall
 * deletes exactly these. Everything structural (excluded terms, authority
 * patterns, classes) is module config, not a setting.
 */
final class DatasetSettings
{
    public const BASE_URI = 'dre_ld_base_uri';
    public const TITLE = 'dre_ld_title';
    public const DESCRIPTION = 'dre_ld_description';
    public const PUBLISHER_NAME = 'dre_ld_publisher_name';
    public const PUBLISHER_URI = 'dre_ld_publisher_uri';
    public const LICENCE = 'dre_ld_licence';

    public const KEYS = [
        self::BASE_URI,
        self::TITLE,
        self::DESCRIPTION,
        self::PUBLISHER_NAME,
        self::PUBLISHER_URI,
        self::LICENCE,
    ];

    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $publisherName,
        public readonly ?string $publisherUri,
        public readonly ?string $licence,
    ) {
    }

    /** @param string $fallbackTitle used when no dataset title is set (the site title) */
    public static function fromSettings(Settings $settings, string $fallbackTitle): self
    {
        $text = static fn (string $key): string => trim((string) $settings->get($key, ''));
        $uri = static function (string $key) use ($text): ?string {
            $value = $text($key);
            return preg_match('~^https?://\S+$~i', $value) ? $value : null;
        };

        return new self(
            $text(self::TITLE) !== '' ? $text(self::TITLE) : $fallbackTitle,
            $text(self::DESCRIPTION),
            $text(self::PUBLISHER_NAME),
            $uri(self::PUBLISHER_URI),
            $uri(self::LICENCE),
        );
    }
}
