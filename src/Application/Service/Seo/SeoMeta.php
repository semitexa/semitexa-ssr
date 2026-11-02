<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo;

use Semitexa\Core\Support\CoroutineLocal;

/**
 * The page's title and meta tags while one request renders it: per coroutine
 * under Swoole, process-wide in the CLI, through CoroutineLocal (which owns
 * that choice). Every value is set by this class as a string, and read back
 * as one; anything else under its keys reads as unset.
 */
final class SeoMeta
{
    private const KEY_META = '__seo_meta';
    private const KEY_TITLE = '__seo_title';
    private const KEY_TITLE_SUFFIX = '__seo_title_suffix';
    private const KEY_TITLE_PREFIX = '__seo_title_prefix';

    public static function setTitle(string $title, ?string $suffix = null, ?string $prefix = null): void
    {
        CoroutineLocal::set(self::KEY_TITLE, $title);
        CoroutineLocal::set(self::KEY_TITLE_SUFFIX, $suffix ?? self::text(self::KEY_TITLE_SUFFIX) ?? '');
        CoroutineLocal::set(self::KEY_TITLE_PREFIX, $prefix ?? self::text(self::KEY_TITLE_PREFIX) ?? '');
    }

    public static function getTitle(?string $override = null): string
    {
        $title = $override ?? self::text(self::KEY_TITLE) ?? '';
        $prefix = self::text(self::KEY_TITLE_PREFIX) ?? '';
        $suffix = self::text(self::KEY_TITLE_SUFFIX) ?? '';

        if ($title !== '' && $prefix !== '') {
            $title = $prefix . $title;
        }
        if ($title !== '' && $suffix !== '') {
            $title = $title . $suffix;
        }

        return $title;
    }

    public static function tag(string $name, ?string $content = null): string
    {
        $meta = self::getMeta();

        if ($content !== null) {
            $meta[$name] = $content;
            self::setMeta($meta);
        } else {
            $content = $meta[$name] ?? null;
        }

        if ($content === null || $content === '') {
            return '';
        }

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

        if (str_starts_with($name, 'og:')) {
            return "<meta property=\"{$safeName}\" content=\"{$safeContent}\">";
        }

        return "<meta name=\"{$safeName}\" content=\"{$safeContent}\">";
    }

    public static function get(string $name): ?string
    {
        $value = self::getMeta()[$name] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }

    public static function has(string $name): bool
    {
        return self::get($name) !== null;
    }

    public static function setDefault(string $name, string $content): void
    {
        if ($content === '' || self::has($name)) {
            return;
        }

        $meta = self::getMeta();
        $meta[$name] = $content;
        self::setMeta($meta);
    }

    /** @return array<string, string> name => content */
    public static function all(): array
    {
        return self::getMeta();
    }

    public static function reset(): void
    {
        CoroutineLocal::set(self::KEY_META, []);
        CoroutineLocal::remove(self::KEY_TITLE);
        CoroutineLocal::remove(self::KEY_TITLE_SUFFIX);
        CoroutineLocal::remove(self::KEY_TITLE_PREFIX);
    }

    /** @return array<string, string> */
    private static function getMeta(): array
    {
        $meta = CoroutineLocal::get(self::KEY_META);
        $typed = [];
        foreach (is_array($meta) ? $meta : [] as $name => $content) {
            if (is_string($content)) {
                $typed[(string) $name] = $content;
            }
        }

        return $typed;
    }

    /** @param array<string, string> $meta */
    private static function setMeta(array $meta): void
    {
        CoroutineLocal::set(self::KEY_META, $meta);
    }

    private static function text(string $key): ?string
    {
        $value = CoroutineLocal::get($key);

        return is_string($value) ? $value : null;
    }
}
