<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

require_once __DIR__ . '/Fixture/SitemapTestKit.php';
require_once __DIR__ . '/Fixture/ArticleSitemapProvider.php';

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Service\Seo\AiSitemapJsonRenderer;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\NotInSitemap;
use Semitexa\Ssr\Tests\Unit\Seo\Fixture\ArticleSitemapProvider;
use Semitexa\Ssr\Tests\Unit\Seo\Fixture\SitemapTestKit;
use Semitexa\Tenancy\Context\TenantContext;

/**
 * /sitemap.json applies sitemap.xml's eligibility.
 *
 * MEASURED on production (a multi-tenant install): the AI sitemap listed 117
 * "pages" including /__observatory, /__trace, /__semitexa/error/*, five copies
 * of `/`, and other tenants' /demo/* routes — the renderer had none of the
 * route-based sitemap provider's filters.
 */
final class AiSitemapEligibilityTest extends TestCase
{
    #[Test]
    public function pages_are_this_tenants_public_listable_routes_once_each(): void
    {
        $paths = array_column($this->document()['pages'], 'path');

        // Five modules declaring / yield one page; /museum-only is scoped to the tenant
        // being served; internal, machine, opted-out, protected and other-tenant routes
        // are absent; the provider-only article is appended last.
        self::assertSame(['/', '/about', '/museum-only', '/blog/first-light'], $paths);
    }

    #[Test]
    public function endpoints_and_templates_get_the_same_tenant_and_internal_filtering(): void
    {
        $document = $this->document();
        $endpointPaths = array_column($document['endpoints'], 'path');
        $templatePaths = array_column($document['templates'], 'path');

        self::assertSame(['/api/things'], $endpointPaths);
        self::assertSame(['/articles/{slug}'], $templatePaths);
    }

    #[Test]
    public function a_listed_page_takes_the_title_its_provider_gives(): void
    {
        $home = $this->document()['pages'][0];

        self::assertSame('/', $home['path']);
        self::assertSame('Home of the museum', $home['title']);
        self::assertArrayNotHasKey('source', $home, 'a listed route is not re-labelled as provider-only');
    }

    #[Test]
    public function pages_only_a_provider_knows_are_appended_with_their_title(): void
    {
        $pages = $this->document()['pages'];
        $byPath = [];
        foreach ($pages as $page) {
            $byPath[$page['path']][] = $page;
        }

        self::assertArrayHasKey('/blog/first-light', $byPath, 'a blog article from an app provider must appear');
        $article = $byPath['/blog/first-light'][0];
        self::assertSame('First light', $article['title']);
        self::assertSame('How the gallery opens at dawn.', $article['description']);
        self::assertSame('2026-09-01T00:00:00+00:00', $article['lastmod']);
        self::assertSame('https://museum.test/blog/first-light', $article['url']);

        self::assertCount(1, $byPath['/'], 'the provider\'s `/` is already a listed page and is not appended again');
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $discovery = $this->createMock(AttributeDiscovery::class);
        $discovery->method('getRoutes')->willReturn($this->routes());

        $renderer = new AiSitemapJsonRenderer();
        SitemapTestKit::set($renderer, 'attributeDiscovery', $discovery);
        SitemapTestKit::set($renderer, 'sitemapGenerator', SitemapTestKit::generator([ArticleSitemapProvider::class]));

        $request = new Request('GET', '/sitemap.json', ['Host' => 'museum.test'], [], [], ['HTTP_HOST' => 'museum.test', 'HTTPS' => 'on'], []);

        return $renderer->buildDocument($request, TenantContext::fromResolution('museum', 'test'));
    }

    /** @return list<array<string, mixed>> */
    private function routes(): array
    {
        $page = static fn (string $path, array $extra = []): array => $extra + [
            'path' => $path,
            'methods' => ['GET'],
            'accessType' => PayloadAccessType::Public,
        ];
        $json = ['produces' => ['application/json']];

        return [
            $page('/'), $page('/'), $page('/'), $page('/'), $page('/'),
            $page('/about'),
            $page('/museum-only', ['tenantScopes' => ['museum']]),
            $page('/demo/school', ['tenantScopes' => ['school']]),
            $page('/__observatory'),
            $page('/__trace/node'),
            $page('/__semitexa/error/404'),
            $page('/sitemap.xml', ['produces' => ['application/xml']]),
            $page('/llms.txt', ['produces' => ['text/plain']]),
            $page('/opted-out', ['class' => AiOptedOutPayload::class]),
            $page('/account', ['accessType' => PayloadAccessType::Protected]),
            $page('/api/things', $json),
            $page('/api/school-things', $json + ['tenantScopes' => ['school']]),
            $page('/__observatory/feed', $json),
            $page('/articles/{slug}'),
            $page('/demo/{id}', ['tenantScopes' => ['school']]),
        ];
    }
}

#[NotInSitemap]
final class AiOptedOutPayload
{
}
