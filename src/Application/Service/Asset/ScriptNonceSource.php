<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

/**
 * Where the asset pipeline asks for a CSP nonce, if the application has one.
 *
 * A consumer enforcing `script-src 'nonce-…'` must stamp that nonce onto the
 * inline scripts IT writes — but the pipeline writes two of its own: the
 * per-page `<script type="importmap">` (import maps are governed by
 * script-src like any other script) and `inline-js` asset entries. Without a
 * nonce on those, enabling CSP kills every `type="module"` runtime on the
 * page, because the map they resolve through never loads.
 *
 * The application registers a provider once per worker (a Twig-extension
 * registration hook is a fine place); the pipeline calls it per render, so a
 * per-request nonce stays per-request. No provider — the default — renders
 * exactly the markup this class predates: zero cost for CSP-less consumers.
 */
final class ScriptNonceSource
{
    /** @var (callable(): string)|null */
    private static $provider = null;

    /** @param (callable(): string)|null $provider */
    public static function register(?callable $provider): void
    {
        self::$provider = $provider;
    }

    /**
     * The raw nonce, or '' when no provider is registered.
     *
     * Some consumers need the value rather than a script attribute — a
     * `<meta name="csp-nonce">` that a third-party bundle reads before styling
     * itself, for one. Building that meta by string-slicing attribute() would
     * be a second place to get the escaping wrong.
     */
    public static function value(): string
    {
        if (self::$provider === null) {
            return '';
        }

        return (self::$provider)();
    }

    /** ` nonce="…"` ready for a <script tag, or '' when no provider is registered. */
    public static function attribute(): string
    {
        if (self::$provider === null) {
            return '';
        }

        $nonce = (self::$provider)();

        return $nonce === '' ? '' : ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
    }
}
