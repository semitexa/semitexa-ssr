<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

/**
 * A generated sitemap file is a cache, and a cache has a maximum age.
 *
 * The files under {@see SitemapStoragePath::generatedDirectory()} used to be
 * served for as long as they existed. Only the scheduled job ever rewrote them,
 * so an install without a scheduler served whatever was generated first,
 * forever — MEASURED on production: a sitemap written months earlier, listing
 * ~95 URLs of another tenant that 404 on the domain serving it. A persisted
 * sitemap must not depend on a scheduler to ever change: past its age it is
 * ignored and regenerated on the next request.
 *
 * Age alone does not cover a deploy: a release that adds routes or articles
 * kept serving the previous code's sitemap until the TTL ran out — MEASURED on
 * production twice, and cleared by hand both times. A file written before the
 * installed code is stale too. The code's age is the mtime of
 * vendor/composer/installed.php, which composer rewrites on every install and
 * update, so auto-deploy, a manual deploy and local work are covered alike.
 *
 * Manual overrides (sitemap.xml at the project root or in public/) are not
 * caches and are never aged.
 */
final class GeneratedSitemapCache
{
    public const int DEFAULT_TTL_SECONDS = 86_400;
    public const string TTL_ENV = 'SITEMAP_CACHE_TTL';

    /** Maximum age of a generated file, from SITEMAP_CACHE_TTL (seconds). */
    public static function ttlSeconds(): int
    {
        $raw = trim((string) (Environment::getEnvValue(self::TTL_ENV) ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return self::DEFAULT_TTL_SECONDS;
        }

        return (int) $raw;
    }

    /**
     * The file's content when it exists, is younger than $ttlSeconds and was
     * written after the installed code; null when it is missing, unreadable,
     * or stale.
     */
    public static function readFresh(string $path, ?int $ttlSeconds = null, ?int $now = null): ?string
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);
        if ($mtime === false) {
            return null;
        }

        $age = ($now ?? time()) - $mtime;
        if ($age > ($ttlSeconds ?? self::ttlSeconds())) {
            return null;
        }

        $codeInstalledAt = self::codeInstalledAt();
        if ($codeInstalledAt !== null && $mtime < $codeInstalledAt) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    /** When composer last wrote vendor/; null when there is no composer-managed vendor to ask. */
    private static function codeInstalledAt(): ?int
    {
        $marker = ProjectRoot::get() . '/vendor/composer/installed.php';
        clearstatcache(true, $marker);
        $mtime = @filemtime($marker);

        return $mtime === false ? null : $mtime;
    }
}
