<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\Provider\RouteBasedSitemapProvider;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\NotInSitemap;

/**
 * What an application is allowed to keep out of sitemap.xml — semitexa/semitexa-core#110.
 *
 * The provider contributes every public GET route that renders HTML, and the generator only
 * ever collects from providers: it has no filter, so a URL the framework contributed could
 * not be subtracted by the application at all. Two things escaped through that.
 *
 * 'Public' here means 'no session required', which is as true of /password/reset as of the
 * front page — so sign-in pages and one-time-token landings were submitted to search engines
 * with no opt-out. And the internal-path guard matched only '/__semitexa', so semitexa/dev's
 * /__observatory and /__trace went into the public sitemap of any project with the dev module
 * installed, where production answers 404: the sitemap advertised broken URLs.
 */
final class SitemapExclusionTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function paths(): array
    {
        return [
            'ordinary page stays'          => ['/pricing', true],
            'legacy internal prefix'       => ['/__semitexa/health', false],
            'observatory panel'            => ['/__observatory', false],
            'observatory feed'             => ['/__observatory/feed', false],
            'request tracer'               => ['/__trace', false],
            'tracer sub-path'              => ['/__trace/node', false],
            'root stays'                   => ['/', true],
            'a page merely starting with _' => ['/_partials-demo', true],
        ];
    }

    #[Test]
    #[DataProvider('paths')]
    public function framework_internal_paths_never_reach_the_sitemap(string $path, bool $expected): void
    {
        self::assertSame($expected, self::isEligible([
            'path' => $path,
            'accessType' => 'public',
            'methods' => ['GET'],
        ]), $path);
    }

    #[Test]
    public function a_payload_can_opt_its_route_out(): void
    {
        self::assertFalse(self::isEligible([
            'path' => '/password/reset',
            'accessType' => 'public',
            'methods' => ['GET'],
            'class' => OptedOutPayload::class,
        ]), 'NotInSitemap was ignored — the application still cannot subtract a URL');
    }

    #[Test]
    public function a_payload_without_the_attribute_stays_listed(): void
    {
        self::assertTrue(self::isEligible([
            'path' => '/pricing',
            'accessType' => 'public',
            'methods' => ['GET'],
            'class' => ListedPayload::class,
        ]), 'the opt-out must be opt-IN — silence means list it');
    }

    #[Test]
    public function an_unknown_or_missing_class_is_not_treated_as_opted_out(): void
    {
        // Route arrays are assembled by discovery and not every source fills 'class';
        // failing closed here would silently empty a project's sitemap.
        self::assertTrue(self::isEligible([
            'path' => '/pricing',
            'accessType' => 'public',
            'methods' => ['GET'],
        ]));
        self::assertTrue(self::isEligible([
            'path' => '/pricing',
            'accessType' => 'public',
            'methods' => ['GET'],
            'class' => 'No\\Such\\Class',
        ]));
    }

    /** @param array<string, mixed> $route */
    private static function isEligible(array $route): bool
    {
        $method = new ReflectionMethod(RouteBasedSitemapProvider::class, 'isEligible');
        $method->setAccessible(true);

        return (bool) $method->invoke(new RouteBasedSitemapProvider(), $route);
    }
}

/** A public HTML page that must never be offered to a search engine. */
#[NotInSitemap]
final class OptedOutPayload
{
}

/** The control: an ordinary public page, which must stay in the sitemap. */
final class ListedPayload
{
}
