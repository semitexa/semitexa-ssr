<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\SiteHead;

use Semitexa\Core\Http\CspNonce;
use Semitexa\Ssr\Domain\Model\SiteHead;

/**
 * The tags a site's head values stand for. The framework writes them, so every
 * script carries the request's CSP nonce and no stored value is ever printed as
 * markup: each one has already matched its vendor's token shape, and is escaped
 * here regardless.
 */
final class SiteHeadRenderer
{
    public static function render(SiteHead $head): string
    {
        if ($head->isEmpty()) {
            return '';
        }

        $html = '';

        foreach ([
            SiteHead::GOOGLE_SITE_VERIFICATION => 'google-site-verification',
            SiteHead::BING_SITE_VERIFICATION => 'msvalidate.01',
            SiteHead::YANDEX_VERIFICATION => 'yandex-verification',
        ] as $key => $metaName) {
            $token = $head->get($key);
            if ($token !== null) {
                $html .= '<meta name="' . $metaName . '" content="' . self::e($token) . '">' . "\n";
            }
        }

        $ga4 = $head->get(SiteHead::GA4_MEASUREMENT_ID);
        if ($ga4 !== null) {
            $html .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . rawurlencode($ga4) . '"'
                . CspNonce::attribute() . '></script>' . "\n";
            $html .= '<script' . CspNonce::attribute() . '>window.dataLayer=window.dataLayer||[];'
                . 'function gtag(){dataLayer.push(arguments);}gtag("js",new Date());'
                . 'gtag("config",' . json_encode($ga4, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>' . "\n";
        }

        $plausible = $head->get(SiteHead::PLAUSIBLE_DOMAIN);
        if ($plausible !== null) {
            $html .= '<script defer data-domain="' . self::e($plausible) . '" src="https://plausible.io/js/script.js"'
                . CspNonce::attribute() . '></script>' . "\n";
        }

        return $html;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
