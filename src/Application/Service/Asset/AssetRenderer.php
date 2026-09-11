<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Renders collected assets into HTML tags for injection into layout templates.
 *
 * Produces two output blocks:
 *   - head: CSS <link>, <link rel="preload">, <style> (inline-css), head-positioned <script>
 *   - body: body-positioned <script>, inline <script>
 *
 * CSS <link> tags are always forced to <head> regardless of the position field (Rule R1).
 */
final class AssetRenderer
{
    /**
     * Placeholder {@see renderHead()} leaves in the head for CSS registered
     * after the head has already rendered (Twig renders <head> before <body>,
     * so usage-driven CSS compiled from body content always arrives late).
     * {@see finalizeDynamicCss()} resolves it once the page render completes.
     */
    public const DYNAMIC_CSS_MARKER = '<!--__SSR_DYNAMIC_CSS__-->';

    /**
     * Render all head-positioned assets as HTML.
     *
     * When any collected asset declares an import-map `specifier`, a single
     * server-generated <script type="importmap"> is emitted FIRST — the spec
     * requires the map to precede every module script, and mapping bare
     * specifiers to fingerprinted URLs gives ES-module imports the same
     * immutable cache-busting the classic <script src> path gets.
     */
    public static function renderHead(AssetCollector $collector): string
    {
        $entries = $collector->resolve();
        $html = self::renderImportMap($entries);
        $renderedKeys = [];

        foreach ($entries as $entry) {
            $signature = self::buildRenderSignature($entry);
            if (isset($renderedKeys[$signature])) {
                continue;
            }

            // R1: CSS is always in <head> regardless of position override
            $effectivePosition = match ($entry->type) {
                'css', 'inline-css', 'preload' => 'head',
                default                        => $entry->position,
            };

            if ($effectivePosition !== 'head') {
                continue;
            }

            $renderedKeys[$signature] = true;

            $html .= match ($entry->type) {
                'css'        => self::renderCssLink($entry),
                'preload'    => self::renderPreload($entry),
                'inline-css' => self::renderInlineCss($entry),
                'js'         => self::renderScript($entry),
                'inline-js'  => self::renderInlineScript($entry),
                default      => '',
            };
        }

        // What the head printed, remembered for the post-render pass: it is the
        // only way to tell an asset that arrived late from one already on the
        // page. See finalizeDynamicCss().
        $collector->markHeadRendered(array_keys($renderedKeys));

        // Raw inline CSS registered before the head rendered goes out now;
        // the marker stands in for whatever the rest of the render registers.
        $html .= self::renderRawInlineCss($collector->takeRawInlineCss());
        $html .= self::DYNAMIC_CSS_MARKER;

        return $html;
    }

    /**
     * The post-render half of the raw inline CSS pipeline. Call with the fully
     * rendered page HTML: runs the collector's finalize callbacks (the seam
     * where usage-collected CSS gets compiled and registered), then resolves
     * the marker {@see renderHead()} left — replacing it with the pending
     * <style> blocks, or with nothing when none were registered. Idempotent:
     * both the callbacks and the pending entries drain on first use, so a
     * nested render finalizing twice emits nothing twice.
     *
     * When the page never rendered asset_head() (no marker), pending styles
     * fall back to injection before </head>, or prepend on a headless fragment.
     */
    public static function finalizeDynamicCss(string $html, AssetCollector $collector): string
    {
        $collector->runFinalizeCallbacks($html);
        $styles = self::renderLateHeadAssets($collector)
            . self::renderRawInlineCss($collector->takeRawInlineCss());

        if (str_contains($html, self::DYNAMIC_CSS_MARKER)) {
            return str_replace(self::DYNAMIC_CSS_MARKER, $styles, $html);
        }

        if ($styles === '') {
            return $html;
        }

        $headClose = stripos($html, '</head>');
        if ($headClose !== false) {
            return substr_replace($html, $styles, $headClose, 0);
        }

        return $styles . $html;
    }

    /**
     * Head assets that were required after the head had already rendered.
     *
     * A component asking for its own stylesheet is doing the right thing — the
     * markup is what knows the asset is needed — but it runs while the body is
     * rendering, which is after `asset_head()`. A `<link>` is forced to the head
     * by R1 and {@see renderBody()} skips CSS deliberately, so before this
     * method the request matched no renderer at all: no tag, no warning, and a
     * component that came back unstyled with nothing to explain it.
     *
     * Emitted at the marker, which sits after everything the head printed, so
     * a late sheet still lands in the head and still loses the cascade to
     * nothing that was there first.
     *
     * Only what the head has NOT already printed, which is why the collector
     * remembers its signatures: resolve() returns the whole set every time, and
     * without that memory this would print every stylesheet on the page twice.
     */
    private static function renderLateHeadAssets(AssetCollector $collector): string
    {
        $html = '';
        $seen = [];

        foreach ($collector->resolve() as $entry) {
            // Scripts are not stranded: asset_body() runs after the markup that
            // required them. This is about what only the head can carry.
            if (!in_array($entry->type, ['css', 'preload', 'inline-css'], true)) {
                continue;
            }

            $signature = self::buildRenderSignature($entry);

            if ($collector->headAlreadyRendered($signature) || isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;

            $html .= match ($entry->type) {
                'css'        => self::renderCssLink($entry),
                'preload'    => self::renderPreload($entry),
                'inline-css' => self::renderInlineCss($entry),
                default      => '',
            };
        }

        // Idempotent like the rest of the finalize pass: a nested render that
        // finalizes twice must not print these again.
        $collector->markHeadRendered(array_keys($seen));

        return $html;
    }

    /**
     * @param list<array{key: string, css: string, priority: int}> $entries
     */
    private static function renderRawInlineCss(array $entries): string
    {
        $html = '';
        foreach ($entries as $entry) {
            // A literal "</style" in the content would close the tag early and
            // spill the rest into markup; neutralize it the way inline scripts
            // neutralize "</script". No such sequence occurs in valid CSS.
            $safeCss = str_ireplace('</style', '<\/style', $entry['css']);
            $html .= '<style data-asset-key="'
                . htmlspecialchars($entry['key'], ENT_QUOTES, 'UTF-8')
                . '">' . $safeCss . '</style>' . "\n";
        }

        return $html;
    }

    /**
     * Render all body-positioned assets as HTML.
     */
    public static function renderBody(AssetCollector $collector): string
    {
        $entries = $collector->resolve();
        $html = '';
        $renderedKeys = [];

        foreach ($entries as $entry) {
            $signature = self::buildRenderSignature($entry);
            if (isset($renderedKeys[$signature])) {
                continue;
            }

            // CSS types are always head — skip them here
            if (in_array($entry->type, ['css', 'inline-css', 'preload'], true)) {
                continue;
            }

            if ($entry->position !== 'body') {
                continue;
            }

            $renderedKeys[$signature] = true;

            $html .= match ($entry->type) {
                'js'        => self::renderScript($entry),
                'inline-js' => self::renderInlineScript($entry),
                default     => '',
            };
        }

        return $html;
    }

    /**
     * One import map per page: every resolved js asset with a `specifier`
     * contributes an imports entry pointing at its fingerprinted URL.
     * Empty string when no asset declares a specifier (zero cost for
     * module-free pages).
     *
     * @param AssetEntry[] $entries
     */
    private static function renderImportMap(array $entries): string
    {
        $imports = [];
        foreach ($entries as $entry) {
            if ($entry->type !== 'js' || $entry->specifier === null || $entry->specifier === '') {
                continue;
            }
            $imports[$entry->specifier] = AssetManager::getUrl($entry->path, $entry->module);
        }

        if ($imports === []) {
            return '';
        }

        $json = json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return '<script type="importmap"' . ScriptNonceSource::attribute() . '>' . str_ireplace('</script', '<\/script', $json) . '</script>' . "\n";
    }

    private static function renderCssLink(AssetEntry $entry): string
    {
        $attrs = self::buildAttributes($entry->attributes);
        $raw = AssetManager::getUrl($entry->path, $entry->module);
        $url = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');

        self::reportDuplicateStylesheet($entry, $raw);

        return '<link rel="stylesheet" href="' . $url . '"' . $attrs . '>' . "\n";
    }

    /**
     * Say something when the same stylesheet is about to be linked twice.
     *
     * `asset()` answers a template with a URL and never touches the collector,
     * so a hand-written `<link rel="stylesheet" href="{{ asset(...) }}">` for a
     * file the manifest also declares scope=global produced two links and no
     * complaint. MEASURED on a consumer: platform-ui/css/full.css fetched twice
     * with the same fingerprint on every page load, one of ten render-blocking
     * sheets Lighthouse priced at 1,480 ms.
     *
     * A warning rather than a skip. `asset()` is also how an <img>, a favicon
     * and a font get their URL, and a template may legitimately mention a
     * stylesheet's URL without linking it — refusing to emit on that evidence
     * would break more pages than the duplicate ever did. The person who wrote
     * the template is the one who can tell which it is; this makes sure they
     * are told.
     */
    private static function reportDuplicateStylesheet(AssetEntry $entry, string $url): void
    {
        $collector = AssetCollectorStore::get();
        $collector->noteEmittedCss($url);

        if (!$collector->wasHandedOutDirectly($url)) {
            return;
        }

        StaticLoggerBridge::warning('ssr', 'Stylesheet linked twice: a template already emitted this URL through asset()', [
            'key' => $entry->key,
            'module' => $entry->module,
            'path' => $entry->path,
            'fix' => 'Remove the hand-written <link> — this asset is declared scope=' . $entry->scope
                . ', so asset_head() emits it already. For a scope=page asset use asset_require() instead of a raw tag.',
        ]);
    }

    private static function renderScript(AssetEntry $entry): string
    {
        $attrs = self::buildAttributes($entry->attributes);
        $url = htmlspecialchars(AssetManager::getUrl($entry->path, $entry->module), ENT_QUOTES, 'UTF-8');

        return '<script src="' . $url . '"' . $attrs . '></script>' . "\n";
    }

    private static function renderPreload(AssetEntry $entry): string
    {
        $ext = strtolower(pathinfo($entry->path, PATHINFO_EXTENSION));
        $as = match ($ext) {
            'js'          => 'script',
            'css'         => 'style',
            'woff2', 'woff' => 'font',
            default       => 'fetch',
        };

        $attrs = self::buildAttributes($entry->attributes);
        $url = htmlspecialchars(AssetManager::getUrl($entry->path, $entry->module), ENT_QUOTES, 'UTF-8');
        $crossOrigin = ($as === 'font') ? ' crossorigin' : '';

        return '<link rel="preload" href="' . $url . '" as="' . $as . '"' . $crossOrigin . $attrs . '>' . "\n";
    }

    private static function renderInlineCss(AssetEntry $entry): string
    {
        $content = self::readInlineContent($entry);
        if ($content === null) {
            return '';
        }

        $attrs = self::buildAttributes($entry->attributes);
        $safeContent = str_ireplace('</style', '<\/style', $content);
        return '<style' . $attrs . '>' . $safeContent . '</style>' . "\n";
    }

    /**
     * Attribute string for an inline <script>, provider nonce included. The
     * provider's nonce must be the ONLY nonce: with a manifest-declared one
     * also present the browser honours whichever comes first, and a stale
     * manifest value would lose to the CSP header every time.
     *
     * @param array<string, string> $attributes
     */
    private static function inlineScriptAttributes(array $attributes): string
    {
        $nonceAttr = ScriptNonceSource::attribute();
        if ($nonceAttr !== '') {
            unset($attributes['nonce']);
        }

        return self::buildAttributes($attributes) . $nonceAttr;
    }

    private static function renderInlineScript(AssetEntry $entry): string
    {
        $content = self::readInlineContent($entry);
        if ($content === null) {
            return '';
        }

        $safeContent = str_ireplace('</script', '<\/script', $content);
        return '<script' . self::inlineScriptAttributes($entry->attributes) . '>' . $safeContent . '</script>' . "\n";
    }

    /**
     * Read file content for inline asset types.
     * Resolves via ModuleAssetRegistry to ensure path traversal protection.
     */
    private static function readInlineContent(AssetEntry $entry): ?string
    {
        ModuleAssetRegistry::initialize();
        $filePath = ModuleAssetRegistry::resolve($entry->module, $entry->path);
        if ($filePath === null) {
            \Semitexa\Core\Log\StaticLoggerBridge::warning('ssr', 'Cannot resolve inline asset', [
                'key' => $entry->key,
                'module' => $entry->module,
                'path' => $entry->path,
            ]);
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false || $content === '') {
            return null;
        }

        return $content;
    }

    /**
     * Build HTML attribute string from an associative array.
     *
     * Boolean true values produce valueless attributes (e.g. "defer").
     * False/null values are omitted.
     */
    private static function buildAttributes(array $attributes): string
    {
        $parts = '';

        foreach ($attributes as $name => $value) {
            if ($value === true) {
                $parts .= ' ' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
            } elseif ($value !== false && $value !== null) {
                $parts .= ' ' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8')
                    . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        return $parts;
    }

    private static function buildRenderSignature(AssetEntry $entry): string
    {
        $identity = match ($entry->type) {
            'inline-css', 'inline-js' => $entry->module . '|' . $entry->path,
            default => AssetManager::getUrl($entry->path, $entry->module),
        };

        return $entry->type . '|' . $entry->position . '|' . $identity;
    }
}
