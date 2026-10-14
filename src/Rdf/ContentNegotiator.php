<?php
declare(strict_types=1);

namespace DRELinkedData\Rdf;

/**
 * Server-driven content negotiation over an HTTP Accept header (RFC 9110 §12).
 *
 * For each offered media type the most specific matching range sets its
 * quality; the highest quality wins and ties go to the earlier offer, so the
 * caller's offer order is the server preference. That is what keeps browsers
 * on HTML: they send `text/html` explicitly and everything else only through
 * the q=0.8 full wildcard, while an RDF client names its format and gets it.
 */
final class ContentNegotiator
{
    public const HTML = 'text/html';

    /**
     * @param string[] $offers media types in server preference order
     * @return string|null the chosen offer, or null when none is acceptable
     */
    public static function negotiate(?string $accept, array $offers): ?string
    {
        if ($accept === null || trim($accept) === '') {
            return $offers[0] ?? null;
        }

        $ranges = self::parse($accept);
        $best = null;
        $bestQuality = 0.0;
        foreach ($offers as $offer) {
            [$type, $subtype] = array_pad(explode('/', strtolower($offer), 2), 2, '');
            $quality = null;
            $specificity = -1;
            foreach ($ranges as $range) {
                if (($range['type'] !== '*' && $range['type'] !== $type)
                    || ($range['subtype'] !== '*' && $range['subtype'] !== $subtype)
                ) {
                    continue;
                }
                $rangeSpecificity = ($range['type'] !== '*' ? 1 : 0) + ($range['subtype'] !== '*' ? 1 : 0);
                if ($rangeSpecificity > $specificity) {
                    $specificity = $rangeSpecificity;
                    $quality = $range['q'];
                }
            }
            if ($quality !== null && $quality > $bestQuality) {
                $best = $offer;
                $bestQuality = $quality;
            }
        }
        return $best;
    }

    /**
     * The RDF format an Accept header asks for when offered alongside HTML, or
     * null when HTML (the default representation) wins.
     */
    public static function rdfFormat(?string $accept): ?Format
    {
        $chosen = self::negotiate($accept, self::offers());
        return $chosen === null || $chosen === self::HTML ? null : Format::fromMediaType($chosen);
    }

    /**
     * Whether a resource URI can answer this Accept header at all: with the
     * record page or an RDF format. A header naming only types the module does
     * not serve (`application/rdf+xml`) is not, and earns a 406 rather than a
     * page the client cannot read. An absent or unparseable header accepts
     * anything (RFC 9110 §12.5.1).
     */
    public static function acceptable(?string $accept): bool
    {
        if ($accept === null || self::parse($accept) === []) {
            return true;
        }
        return self::negotiate($accept, self::offers()) !== null;
    }

    /** @return string[] what a resource URI can answer with, in server preference order */
    public static function offers(): array
    {
        return [self::HTML, ...Format::mediaTypes()];
    }

    /** @return array<int,array{type:string,subtype:string,q:float}> */
    private static function parse(string $accept): array
    {
        $ranges = [];
        foreach (explode(',', $accept) as $part) {
            $params = array_map('trim', explode(';', $part));
            $mediaRange = strtolower((string) array_shift($params));
            if (!preg_match('~^([a-z0-9!#$&^_.+*-]+)/([a-z0-9!#$&^_.+*-]+)$~', $mediaRange, $match)) {
                continue;
            }
            $quality = 1.0;
            foreach ($params as $param) {
                if (preg_match('~^q\s*=\s*([01](?:\.\d{0,3})?)$~i', $param, $q)) {
                    $quality = min(1.0, (float) $q[1]);
                }
            }
            $ranges[] = ['type' => $match[1], 'subtype' => $match[2], 'q' => $quality];
        }
        return $ranges;
    }
}
