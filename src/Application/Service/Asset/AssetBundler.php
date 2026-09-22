<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

use MatthiasMullie\Minify\CSS as CssMinifier;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Concatenates the stylesheets a page collected into one or two files.
 *
 * A manifest glob emits one render-blocking <link> per CSS file, and a page
 * that pulls a kit, a UI library and its own module reaches fifteen of them —
 * fifteen serial round trips before first paint on a phone, each one small.
 *
 * Two bundles, not one. Assets declared `scope=global` are the same on every
 * page of a site, so they are worth their own file: a visitor who moves to a
 * second page re-uses it from cache and downloads only the small remainder.
 * The split only happens when every global already sorts ahead of everything
 * else — otherwise splitting would reorder the cascade, and one bundle is
 * emitted instead. Order inside a bundle is exactly the order the renderer
 * would have linked in.
 *
 * Relative `url()` is rewritten to an absolute module path before minifying.
 * A bundle is served from somewhere else entirely, so `../fonts/x.woff2` in a
 * module's own stylesheet would resolve against the bundle's directory and
 * 404 — which on this estate is every self-hosted font face.
 *
 * Nothing here is allowed to break a page. An unreadable source, an
 * unwritable output directory, a minifier that throws: each falls back to the
 * unbundled links the renderer would have produced anyway, with a warning.
 */
final class AssetBundler
{
    /** Relative to the project root; served at /assets/ssr/bundle/. */
    private const OUTPUT_DIR = 'public/assets/ssr/bundle';

    private const URL_PREFIX = '/assets/ssr/bundle';

    /** Schemes and roots a rewrite must leave alone. */
    private const ABSOLUTE_URL = '#^(?:[a-z][a-z0-9+.-]*:|//|/|\#)#i';

    /** @var array<string, string> hash => published url, per worker */
    private static array $published = [];

    /**
     * @param  list<AssetEntry> $entries CSS entries in cascade order
     * @return list<string>|null Bundle URLs to link in order, or null to fall
     *                           back to linking every entry individually.
     */
    public static function bundle(array $entries): ?array
    {
        if (count($entries) < 2) {
            return null;
        }

        $groups = self::split($entries);
        $urls = [];

        foreach ($groups as $name => $group) {
            $css = self::concatenate($group);
            if ($css === null) {
                return null;
            }

            $url = self::publish($name, $css);
            if ($url === null) {
                return null;
            }

            $urls[] = $url;
        }

        return $urls;
    }

    /** Drop the per-worker memo. For tests that write a fresh project root. */
    public static function reset(): void
    {
        self::$published = [];
    }

    /**
     * @param  list<AssetEntry> $entries
     * @return array<string, list<AssetEntry>>
     */
    private static function split(array $entries): array
    {
        $globals = [];
        $rest = [];

        foreach ($entries as $entry) {
            if ($entry->scope === 'global' && $rest === []) {
                $globals[] = $entry;
                continue;
            }
            $rest[] = $entry;
        }

        // A global that turned up after a page asset stays in `rest`, where the
        // loop above put it: it keeps its place in the cascade, and the only
        // cost is that it is not shared between pages.
        if ($globals === [] || $rest === []) {
            return ['page' => $entries];
        }

        return ['global' => $globals, 'page' => $rest];
    }

    /**
     * @param list<AssetEntry> $entries
     */
    private static function concatenate(array $entries): ?string
    {
        $parts = [];

        foreach ($entries as $entry) {
            $path = self::resolvePath($entry);
            if ($path === null) {
                return null;
            }

            $css = @file_get_contents($path);
            if ($css === false) {
                self::warn('stylesheet could not be read', ['path' => $path, 'key' => $entry->key]);
                return null;
            }

            $parts[] = self::absolutiseUrls($css, $entry);
        }

        return implode("\n", $parts);
    }

    private static function resolvePath(AssetEntry $entry): ?string
    {
        try {
            $resolved = ModuleAssetRegistry::resolve($entry->module, $entry->path);
        } catch (\Throwable $e) {
            self::warn('stylesheet could not be resolved', ['key' => $entry->key, 'error' => $e->getMessage()]);
            return null;
        }

        if ($resolved === null || !is_readable($resolved)) {
            self::warn('stylesheet is missing or unreadable', ['key' => $entry->key]);
            return null;
        }

        return $resolved;
    }

    /**
     * Rewrite `url(../fonts/x.woff2)` to `/assets/<module>/fonts/x.woff2`,
     * resolved against the stylesheet's own directory inside its module.
     * Absolute paths, full URLs, data: and fragment-only refs are untouched.
     */
    private static function absolutiseUrls(string $css, AssetEntry $entry): string
    {
        $base = trim(dirname($entry->path), '/.');

        return (string) preg_replace_callback(
            '#url\(\s*(["\']?)([^"\')]+)\1\s*\)#i',
            static function (array $m) use ($base, $entry): string {
                $target = trim($m[2]);
                if ($target === '' || preg_match(self::ABSOLUTE_URL, $target) === 1) {
                    return $m[0];
                }

                $resolved = self::normalise($base === '' ? $target : $base . '/' . $target);

                return 'url("/assets/' . $entry->module . '/' . $resolved . '")';
            },
            $css,
        );
    }

    /** Collapse `a/b/../c` to `a/c` without touching the filesystem. */
    private static function normalise(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return implode('/', $out);
    }

    private static function publish(string $name, string $css): ?string
    {
        $minified = self::minify($css);
        $hash = substr(hash('sha256', $minified), 0, 12);
        $memo = $name . ':' . $hash;

        if (isset(self::$published[$memo])) {
            return self::$published[$memo];
        }

        $dir = ProjectRoot::get() . '/' . self::OUTPUT_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            self::warn('bundle directory could not be created', ['dir' => $dir]);
            return null;
        }

        $file = $dir . '/' . $name . '.' . $hash . '.css';
        if (!is_file($file) && @file_put_contents($file, $minified, LOCK_EX) === false) {
            self::warn('bundle could not be written', ['file' => $file]);
            return null;
        }

        $url = self::URL_PREFIX . '/' . $name . '.' . $hash . '.css';
        self::$published[$memo] = $url;

        return $url;
    }

    /**
     * Minified from a string, never a file: given a path the minifier resolves
     * relative urls itself and inlines small assets as data URIs, and the
     * rewriting is already done here, deliberately and visibly.
     */
    private static function minify(string $css): string
    {
        if (!class_exists(CssMinifier::class)) {
            return $css;
        }

        try {
            $minified = (new CssMinifier($css))->minify();
        } catch (\Throwable $e) {
            self::warn('stylesheet could not be minified, bundling it as written', ['error' => $e->getMessage()]);
            return $css;
        }

        return $minified === '' && $css !== '' ? $css : $minified;
    }

    /** @param array<string, mixed> $context */
    private static function warn(string $message, array $context): void
    {
        StaticLoggerBridge::warning('ssr', 'Asset bundling fell back to individual links: ' . $message, $context);
    }
}
