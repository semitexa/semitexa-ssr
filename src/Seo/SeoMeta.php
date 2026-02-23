<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Seo;

final class SeoMeta
{
    private static array $meta = [];
    private static ?string $title = null;
    private static ?string $titleSuffix = null;
    private static ?string $titlePrefix = null;

    public static function setTitle(string $title, ?string $suffix = null, ?string $prefix = null): void
    {
        self::$title = $title;
        self::$titleSuffix = $suffix ?? self::$titleSuffix ?? ' | My Site';
        self::$titlePrefix = $prefix ?? self::$titlePrefix ?? '';
    }

    public static function getTitle(?string $override = null): string
    {
        $title = $override ?? self::$title ?? '';

        if ($title && self::$titlePrefix) {
            $title = self::$titlePrefix . $title;
        }

        if ($title && self::$titleSuffix) {
            $title = $title . self::$titleSuffix;
        }

        return $title;
    }

    public static function tag(string $name, ?string $content = null): string
    {
        if ($content === null) {
            return self::$meta[$name] ?? '';
        }

        self::$meta[$name] = $content;

        if (in_array($name, ['title', 'description', 'keywords'])) {
            return "<meta name=\"{$name}\" content=\"{$content}\">";
        }

        if (str_starts_with($name, 'og:')) {
            return "<meta property=\"{$name}\" content=\"{$content}\">";
        }

        return "<meta name=\"{$name}\" content=\"{$content}\">";
    }

    public static function all(): array
    {
        return self::$meta;
    }

    public static function reset(): void
    {
        self::$meta = [];
        self::$title = null;
    }
}
