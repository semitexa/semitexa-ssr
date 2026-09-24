<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\CspNonce;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Ssr\Application\Service\Seo\SiteHead\SiteHeadRenderer;
use Semitexa\Ssr\Application\Service\Seo\SiteHead\SiteHeadStore;
use Semitexa\Ssr\Domain\Model\SiteHead;

/**
 * A site's head values are typed tokens the framework turns into tags: never
 * markup, always nonced, and placed in the document without a layout having to
 * ask for them.
 */
final class SiteHeadTest extends TestCase
{
    private const DOCUMENT = "<!doctype html><html><head><title>t</title></head><body><p>b</p></body></html>";

    protected function setUp(): void
    {
        CspNonce::reset();
        SiteHeadStore::reset();
    }

    protected function tearDown(): void
    {
        CspNonce::reset();
        SiteHeadStore::reset();
        CoroutineLocal::resetCliStore();
    }

    private static function head(): SiteHead
    {
        return SiteHead::fromSettings([
            SiteHead::GA4_MEASUREMENT_ID => 'G-ABC123XYZ',
            SiteHead::GOOGLE_SITE_VERIFICATION => 'abcDEF123456789_-xyz',
            SiteHead::BING_SITE_VERIFICATION => str_repeat('A1', 16),
            SiteHead::PLAUSIBLE_DOMAIN => 'example.com',
        ]);
    }

    #[Test]
    public function markup_is_refused_as_a_value(): void
    {
        $head = SiteHead::fromSettings([
            SiteHead::GOOGLE_SITE_VERIFICATION => 'x"><script>alert(1)</script>',
            SiteHead::GA4_MEASUREMENT_ID => "G-ABC');alert(1)//",
            SiteHead::PLAUSIBLE_DOMAIN => 'example.com" onload="x',
        ]);

        self::assertTrue($head->isEmpty());
        self::assertSame(
            [SiteHead::GOOGLE_SITE_VERIFICATION, SiteHead::GA4_MEASUREMENT_ID, SiteHead::PLAUSIBLE_DOMAIN],
            array_keys($head->rejected),
        );
        self::assertSame('', SiteHeadRenderer::render($head));
    }

    #[Test]
    public function an_unknown_key_is_named_not_ignored(): void
    {
        $head = SiteHead::fromSettings(['facebook_pixel' => '123']);

        self::assertArrayHasKey('facebook_pixel', $head->rejected);
        self::assertStringContainsString('unknown key', $head->rejected['facebook_pixel']);
    }

    #[Test]
    public function an_empty_value_means_unset(): void
    {
        self::assertNull(SiteHead::whyRejected(SiteHead::GA4_MEASUREMENT_ID, ''));
        self::assertTrue(SiteHead::fromSettings([SiteHead::GA4_MEASUREMENT_ID => '  '])->isEmpty());
    }

    #[Test]
    public function every_script_carries_the_request_nonce(): void
    {
        CspNonce::set('n0nce');

        $html = SiteHeadRenderer::render(self::head());

        self::assertSame(3, substr_count($html, '<script'));
        self::assertSame(3, substr_count($html, 'nonce="n0nce"'));
        self::assertStringContainsString('gtag("config","G-ABC123XYZ")', $html);
        self::assertStringContainsString('<meta name="google-site-verification" content="abcDEF123456789_-xyz">', $html);
        self::assertStringContainsString('<meta name="msvalidate.01" content="' . str_repeat('A1', 16) . '">', $html);
        self::assertStringContainsString('data-domain="example.com"', $html);
    }

    #[Test]
    public function the_tags_land_just_before_the_head_closes(): void
    {
        SiteHeadStore::bind(static fn (): SiteHead => self::head());

        $html = SiteHeadStore::inject(self::DOCUMENT);

        $close = strpos($html, '</head>');
        self::assertIsInt($close);
        self::assertLessThan($close, strpos($html, 'google-site-verification'));
        self::assertStringEndsWith("</head><body><p>b</p></body></html>", $html);
    }

    /** Nested renders finalize more than once; the tags must not repeat. */
    #[Test]
    public function injecting_twice_adds_the_tags_once(): void
    {
        SiteHeadStore::bind(static fn (): SiteHead => self::head());

        $html = SiteHeadStore::inject(SiteHeadStore::inject(self::DOCUMENT));

        self::assertSame(1, substr_count($html, 'google-site-verification'));
    }

    /** A fragment rendered before the page must not use the reader up. */
    #[Test]
    public function a_fragment_does_not_consume_the_reader(): void
    {
        SiteHeadStore::bind(static fn (): SiteHead => self::head());

        self::assertSame('<div>slot</div>', SiteHeadStore::inject('<div>slot</div>'));
        self::assertStringContainsString('google-site-verification', SiteHeadStore::inject(self::DOCUMENT));
    }

    #[Test]
    public function a_request_that_reads_nothing_is_left_alone(): void
    {
        self::assertSame(self::DOCUMENT, SiteHeadStore::inject(self::DOCUMENT));
    }

    /** A settings outage serves the page without the tags instead of failing it. */
    #[Test]
    public function a_failed_read_serves_the_page_unchanged(): void
    {
        SiteHeadStore::bind(static function (): SiteHead {
            throw new \RuntimeException('settings table unavailable');
        });

        self::assertSame(self::DOCUMENT, SiteHeadStore::inject(self::DOCUMENT));
    }

    /**
     * A handler that renders another full document first (an iframe body, an
     * error re-render) must not leave the page without the tags — and the
     * settings are still read once.
     */
    #[Test]
    public function every_document_of_the_request_gets_the_tags_from_one_read(): void
    {
        $reads = 0;
        SiteHeadStore::bind(static function () use (&$reads): SiteHead {
            $reads++;

            return self::head();
        });

        $first = SiteHeadStore::inject(self::DOCUMENT);
        $second = SiteHeadStore::inject(str_replace('<title>t</title>', '<title>page</title>', self::DOCUMENT));

        self::assertStringContainsString('google-site-verification', $first);
        self::assertStringContainsString('google-site-verification', $second);
        self::assertSame(1, $reads);
    }

    /** A failed read is logged once per request, not once per document. */
    #[Test]
    public function a_failed_read_is_attempted_once_per_request(): void
    {
        $reads = 0;
        SiteHeadStore::bind(static function () use (&$reads): SiteHead {
            $reads++;
            throw new \RuntimeException('settings table unavailable');
        });

        SiteHeadStore::inject(self::DOCUMENT);
        SiteHeadStore::inject(self::DOCUMENT);

        self::assertSame(1, $reads);
    }

    /** Plausible: uppercase input, the comma-separated rollup and punycode TLDs are real domains. */
    #[Test]
    public function plausible_accepts_the_domains_it_is_given(): void
    {
        foreach (['Example.COM', 'a.com, b.org', 'museum.xn--j1amh', 'sub.example.co.uk'] as $domain) {
            self::assertNull(SiteHead::whyRejected(SiteHead::PLAUSIBLE_DOMAIN, $domain), $domain);
        }
        foreach (['a.com,', ',a.com', 'a.com,,b.com', 'no spaces.com'] as $domain) {
            self::assertNotNull(SiteHead::whyRejected(SiteHead::PLAUSIBLE_DOMAIN, $domain), $domain);
        }
        self::assertSame(
            'a.com,b.org',
            SiteHead::fromSettings([SiteHead::PLAUSIBLE_DOMAIN => ' A.com , b.ORG '])->get(SiteHead::PLAUSIBLE_DOMAIN),
        );
    }
}
