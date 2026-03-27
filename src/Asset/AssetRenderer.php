<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Asset;

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
     * Render all head-positioned assets as HTML.
     */
    public static function renderHead(AssetCollector $collector): string
    {
        $entries = $collector->resolve();
        $html = '';
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

    private static function renderCssLink(AssetEntry $entry): string
    {
        $attrs = self::buildAttributes($entry->attributes);
        $url = htmlspecialchars($entry->toUrl(), ENT_QUOTES, 'UTF-8');

        return '<link rel="stylesheet" href="' . $url . '"' . $attrs . '>' . "\n";
    }

    private static function renderScript(AssetEntry $entry): string
    {
        $attrs = self::buildAttributes($entry->attributes);
        $url = htmlspecialchars($entry->toUrl(), ENT_QUOTES, 'UTF-8');

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
        $url = htmlspecialchars($entry->toUrl(), ENT_QUOTES, 'UTF-8');
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

    private static function renderInlineScript(AssetEntry $entry): string
    {
        $content = self::readInlineContent($entry);
        if ($content === null) {
            return '';
        }

        $attrs = self::buildAttributes($entry->attributes);
        $safeContent = str_ireplace('</script', '<\/script', $content);
        return '<script' . $attrs . '>' . $safeContent . '</script>' . "\n";
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
            error_log("[AssetRenderer] Cannot resolve inline asset: {$entry->key} ({$entry->module}/{$entry->path})");
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
            default => $entry->toUrl(),
        };

        return $entry->type . '|' . $entry->position . '|' . $identity;
    }
}
