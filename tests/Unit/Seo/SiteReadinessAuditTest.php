<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Seo\Readiness\ProbeResponse;
use Semitexa\Ssr\Application\Service\Seo\Readiness\ReadinessSignal;
use Semitexa\Ssr\Application\Service\Seo\Readiness\SiteReadinessAudit;

/**
 * The audit reads only what the site serves, names each signal with its state
 * and the request that measured it, and never produces a score.
 */
final class SiteReadinessAuditTest extends TestCase
{
    private const ORIGIN = 'https://site.test';

    private const HOME = <<<'HTML'
        <html><head><title>Acme</title><meta name="description" content="Acme makes things">
        <link rel="canonical" href="https://site.test/">
        <script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite"},{"@type":"Organization"}]}</script>
        </head><body><main><h1>Welcome to Acme</h1><p>We make things.</p></main><script>boot()</script></body></html>
        HTML;

    /** @var list<string> */
    private array $requested = [];

    #[Test]
    public function a_well_formed_site_reports_every_signal_present(): void
    {
        $signals = $this->audit([
            '/llms.txt' => new ProbeResponse(200, 'text/plain', "# Acme\n- [Docs](https://site.test/docs)\n"),
            '/robots.txt' => new ProbeResponse(200, 'text/plain', "User-agent: *\nDisallow: /_feed\nSitemap: https://site.test/sitemap.xml\n"),
            '/sitemap.xml' => new ProbeResponse(200, 'application/xml', '<urlset><url><loc>https://site.test/</loc></url><url><loc>https://site.test/docs</loc></url></urlset>'),
            '/' => new ProbeResponse(200, 'text/html; charset=utf-8', self::HOME),
            '/docs' => new ProbeResponse(200, 'text/html', '<html><head><title>Docs — Acme</title><meta name="description" content="How to use Acme"><link rel="canonical" href="https://site.test/docs"><script type="application/ld+json">{"@type":"TechArticle"}</script></head><body><p>Read me</p></body></html>'),
        ]);

        foreach ($signals as $signal) {
            $this->assertSame(ReadinessSignal::STATE_PRESENT, $signal->state, $signal->subject . ' ' . $signal->name . ': ' . $signal->detail);
            $this->assertNotSame('', $signal->measuredBy);
        }
        $this->assertSame('1 block(s): WebSite, Organization', $this->find($signals, '/', 'structured data')->detail);
        $this->assertSame(['site', 'site', 'site', '/', '/', '/', '/', '/', '/docs', '/docs', '/docs', '/docs', '/docs'], array_map(static fn (ReadinessSignal $s): string => $s->subject, $signals));
    }

    #[Test]
    public function a_page_that_inherits_the_home_title_and_description_is_defaulted(): void
    {
        $signals = $this->audit([
            '/' => new ProbeResponse(200, 'text/html', self::HOME),
            '/about' => new ProbeResponse(200, 'text/html', '<html><head><title>Acme</title><meta name="description" content="Acme makes things"></head><body>About us</body></html>'),
        ], ['/about']);

        $this->assertSame(ReadinessSignal::STATE_DEFAULTED, $this->find($signals, '/about', 'title')->state);
        $this->assertSame(ReadinessSignal::STATE_DEFAULTED, $this->find($signals, '/about', 'description')->state);
        $this->assertSame(ReadinessSignal::STATE_ABSENT, $this->find($signals, '/about', 'canonical')->state);
        $this->assertSame(ReadinessSignal::STATE_ABSENT, $this->find($signals, '/about', 'structured data')->state);
    }

    #[Test]
    public function a_page_route_answering_json_does_not_render_without_javascript(): void
    {
        $signals = $this->audit([
            '/' => new ProbeResponse(200, 'text/html', self::HOME),
            '/app' => new ProbeResponse(200, 'application/json', '{"items":[]}'),
            '/shell' => new ProbeResponse(200, 'text/html', '<html><head><title>Shell</title></head><body><div id="root"></div><script>render()</script></body></html>'),
        ], ['/app', '/shell']);

        $json = $this->find($signals, '/app', 'renders without JavaScript');
        $this->assertSame(ReadinessSignal::STATE_ABSENT, $json->state);
        $this->assertStringContainsString('application/json', $json->detail);
        $this->assertSame(ReadinessSignal::STATE_ABSENT, $this->find($signals, '/shell', 'renders without JavaScript')->state);
    }

    #[Test]
    public function a_page_behind_auth_is_unreadable_to_an_agent(): void
    {
        $signals = $this->audit([
            '/' => new ProbeResponse(200, 'text/html', self::HOME),
            '/account' => new ProbeResponse(401, 'application/json'),
            '/dashboard' => new ProbeResponse(302, 'text/html', '', '/login'),
        ], ['/account', '/dashboard']);

        $account = $this->find($signals, '/account', 'response');
        $this->assertSame(ReadinessSignal::STATE_UNREADABLE, $account->state);
        $this->assertStringContainsString('without a session', $account->detail);
        $this->assertSame('answered 302 → /login', $this->find($signals, '/dashboard', 'response')->detail);
    }

    #[Test]
    public function broken_links_and_a_closed_robots_file_are_partial(): void
    {
        $signals = $this->audit([
            '/llms.txt' => new ProbeResponse(200, 'text/plain', 'See https://site.test/gone and https://site.test/'),
            '/robots.txt' => new ProbeResponse(200, 'text/plain', "User-agent: Googlebot\nDisallow:\n\nUser-agent: *\nDisallow: /\n"),
            '/sitemap.xml' => new ProbeResponse(200, 'application/xml', '<urlset><url><loc>https://site.test/gone</loc></url></urlset>'),
            '/' => new ProbeResponse(200, 'text/html', self::HOME),
            '/gone' => new ProbeResponse(404, 'text/html'),
        ], ['/']);

        $llms = $this->find($signals, 'site', 'llms.txt');
        $this->assertSame(ReadinessSignal::STATE_PARTIAL, $llms->state);
        $this->assertStringContainsString('https://site.test/gone (answered 404)', $llms->detail);
        $this->assertSame(ReadinessSignal::STATE_PARTIAL, $this->find($signals, 'site', 'robots.txt')->state);
        $this->assertSame(ReadinessSignal::STATE_PARTIAL, $this->find($signals, 'site', 'sitemap')->state);
    }

    #[Test]
    public function missing_site_files_are_absent_and_each_url_is_fetched_once(): void
    {
        $signals = $this->audit([
            '/' => new ProbeResponse(200, 'text/html', self::HOME),
        ]);

        $this->assertSame(ReadinessSignal::STATE_ABSENT, $this->find($signals, 'site', 'llms.txt')->state);
        $this->assertSame('answered 404', $this->find($signals, 'site', 'robots.txt')->detail);
        $this->assertSame(ReadinessSignal::STATE_ABSENT, $this->find($signals, 'site', 'sitemap')->state);
        $this->assertSame(array_values(array_unique($this->requested)), $this->requested);
    }

    #[Test]
    public function the_sample_bounds_how_many_sitemap_entries_are_requested(): void
    {
        $locs = '';
        for ($i = 1; $i <= 5; $i++) {
            $locs .= '<url><loc>https://site.test/p' . $i . '</loc></url>';
        }
        $responses = [
            '/sitemap.xml' => new ProbeResponse(200, 'application/xml', '<urlset>' . $locs . '</urlset>'),
            '/' => new ProbeResponse(200, 'text/html', self::HOME),
        ];
        for ($i = 1; $i <= 5; $i++) {
            $responses['/p' . $i] = new ProbeResponse(200, 'text/html', '<html><body>Page ' . $i . '</body></html>');
        }

        $signals = $this->audit($responses, [], 2);

        $this->assertSame('5 entries, 2 checked, 2 answer 200', $this->find($signals, 'site', 'sitemap')->detail);
        $this->assertNotContains(self::ORIGIN . '/p3', $this->requested);
    }

    /**
     * @param array<string, ProbeResponse> $responses keyed by path; anything else answers 404
     * @param list<string> $pages
     * @return list<ReadinessSignal>
     */
    private function audit(array $responses, array $pages = [], int $sample = 10): array
    {
        $this->requested = [];
        $fetch = function (string $url) use ($responses): ProbeResponse {
            $this->requested[] = $url;
            $path = substr($url, strlen(self::ORIGIN));

            return $responses[$path === '' ? '/' : $path] ?? new ProbeResponse(404, 'text/html');
        };

        return (new SiteReadinessAudit($fetch))->run(self::ORIGIN, $pages, $sample);
    }

    /**
     * @param list<ReadinessSignal> $signals
     */
    private function find(array $signals, string $subject, string $name): ReadinessSignal
    {
        foreach ($signals as $signal) {
            if ($signal->subject === $subject && $signal->name === $name) {
                return $signal;
            }
        }
        $this->fail(sprintf('No "%s" signal for %s', $name, $subject));
    }
}
