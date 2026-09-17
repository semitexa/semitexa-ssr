<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\Http\CspNonce;

/**
 * Where the asset pipeline asks for a CSP nonce, if the application has one.
 *
 * A consumer enforcing `script-src 'nonce-…'` must stamp that nonce onto the
 * inline scripts IT writes — but the pipeline writes some of its own: the
 * per-page `<script type="importmap">` (import maps are governed by script-src
 * like any other script) and `inline-js` asset entries. Without a nonce on
 * those, enabling CSP kills every `type="module"` runtime on the page, because
 * the map they resolve through never loads.
 *
 * This class is now a window onto {@see CspNonce}, which core owns. The nonce
 * turned out not to be an asset-pipeline concern at all: the SSR manifest, the
 * CMS editor and every standalone page the OS serves emit inline scripts too,
 * and each one that discovered this separately discovered it from a browser
 * console. Register through either name — they read the same value.
 */
final class ScriptNonceSource
{
    /** @param (callable(): string)|null $provider */
    public static function register(?callable $provider): void
    {
        CspNonce::register($provider);
    }

    /** The raw nonce, or '' when the application has none. */
    public static function value(): string
    {
        return CspNonce::value();
    }

    /** ` nonce="…"` ready for a <script tag, or '' when the application has none. */
    public static function attribute(): string
    {
        return CspNonce::attribute();
    }
}
