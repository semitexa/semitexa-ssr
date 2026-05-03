<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo;

use Swoole\Coroutine;

final class SeoMeta
{
    private const KEY_META = '__seo_meta';
    private const KEY_TITLE = '__seo_title';
    private const KEY_TITLE_SUFFIX = '__seo_title_suffix';
    private const KEY_TITLE_PREFIX = '__seo_title_prefix';

    private static array $staticMeta = [];
    private static ?string $staticTitle = null;
    private static ?string $staticTitleSuffix = null;
    private static ?string $staticTitlePrefix = null;

    public static function setTitle(string $title, ?string $suffix = null, ?string $prefix = null): void
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            $ctx[self::KEY_TITLE] = $title;
            $ctx[self::KEY_TITLE_SUFFIX] = $suffix ?? ($ctx[self::KEY_TITLE_SUFFIX] ?? '');
            $ctx[self::KEY_TITLE_PREFIX] = $prefix ?? ($ctx[self::KEY_TITLE_PREFIX] ?? '');
            return;
        }
        self::$staticTitle = $title;
        self::$staticTitleSuffix = $suffix ?? self::$staticTitleSuffix ?? '';
        self::$staticTitlePrefix = $prefix ?? self::$staticTitlePrefix ?? '';
    }

    public static function getTitle(?string $override = null): string
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            $title = $override ?? ($ctx[self::KEY_TITLE] ?? '');
            $prefix = $ctx[self::KEY_TITLE_PREFIX] ?? '';
            $suffix = $ctx[self::KEY_TITLE_SUFFIX] ?? '';
        } else {
            $title = $override ?? self::$staticTitle ?? '';
            $prefix = self::$staticTitlePrefix ?? '';
            $suffix = self::$staticTitleSuffix ?? '';
        }

        if ($title && $prefix) {
            $title = $prefix . $title;
        }
        if ($title && $suffix) {
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

        if (in_array($name, ['title', 'description', 'keywords'], true)) {
            return "<meta name=\"{$safeName}\" content=\"{$safeContent}\">";
        }

        if (str_starts_with($name, 'og:')) {
            return "<meta property=\"{$safeName}\" content=\"{$safeContent}\">";
        }

        return "<meta name=\"{$safeName}\" content=\"{$safeContent}\">";
    }

    public static function get(string $name): ?string
    {
        $meta = self::getMeta();
        $value = $meta[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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

    public static function all(): array
    {
        return self::getMeta();
    }

    public static function reset(): void
    {
        if (self::inCoroutine()) {
            $ctx = Coroutine::getContext();
            $ctx[self::KEY_META] = [];
            $ctx[self::KEY_TITLE] = null;
            $ctx[self::KEY_TITLE_SUFFIX] = null;
            $ctx[self::KEY_TITLE_PREFIX] = null;
            return;
        }
        self::$staticMeta = [];
        self::$staticTitle = null;
        self::$staticTitleSuffix = null;
        self::$staticTitlePrefix = null;
    }

    private static function getMeta(): array
    {
        if (self::inCoroutine()) {
            return Coroutine::getContext()[self::KEY_META] ?? [];
        }
        return self::$staticMeta;
    }

    private static function setMeta(array $meta): void
    {
        if (self::inCoroutine()) {
            Coroutine::getContext()[self::KEY_META] = $meta;
            return;
        }
        self::$staticMeta = $meta;
    }

    private static function inCoroutine(): bool
    {
        return class_exists(Coroutine::class, false) && Coroutine::getCid() > 0;
    }
}
