<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Seo\Sitemap;

use Composer\InstalledVersions;
use Semitexa\Core\Environment;

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
 * production twice, and cleared by hand both times. So the index also records
 * which code wrote it ({@see self::CODE_STAMP}, written last by
 * SitemapGenerator::write()), and an index written by other code is stale.
 *
 * The code is identified by the package set this process loaded
 * (Composer\InstalledVersions), not by file times: mtimes tie within a second,
 * and a worker still running the old code after `composer install` must not
 * stamp its old sitemap with the new release. Parts carry no stamp of their
 * own — their index decides for them (SitemapPartHandler).
 *
 * Manual overrides (sitemap.xml at the project root or in public/) are not
 * caches and are never aged.
 */
final class GeneratedSitemapCache
{
    public const int DEFAULT_TTL_SECONDS = 86_400;
    public const string CODE_STAMP = 'sitemap.code';
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
     * The index in $dir when it is younger than the TTL and was written by the
     * code serving this request; null otherwise. Without Composer metadata to
     * identify the code, age alone decides. A missing or unreadable stamp —
     * an index from before stamps existed, say — is stale.
     */
    public static function readFreshIndex(
        string $dir,
        ?int $ttlSeconds = null,
        ?int $now = null,
        ?string $codeIdentity = null,
    ): ?string {
        $content = self::readFresh($dir . '/sitemap.xml', $ttlSeconds, $now);
        if ($content === null) {
            return null;
        }

        $current = $codeIdentity ?? self::codeIdentity();
        if ($current === null) {
            return $content;
        }

        $stampPath = $dir . '/' . self::CODE_STAMP;
        clearstatcache(true, $stampPath);
        $stamp = is_file($stampPath) ? @file_get_contents($stampPath) : false;

        return is_string($stamp) && hash_equals($current, trim($stamp)) ? $content : null;
    }

    /**
     * Identity of the code this process runs: a hash of the package set it
     * loaded. Null when Composer's runtime API is unavailable.
     */
    public static function codeIdentity(): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        return hash('sha256', serialize(InstalledVersions::getAllRawData()));
    }

    /**
     * The file's content when it exists and is younger than $ttlSeconds; null
     * when it is missing, unreadable, or stale.
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

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }
}
