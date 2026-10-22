<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Ssr\Application\Service\Extension\LocaleTwigExtension;

final class LocaleUrlTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleContextStore::clearFallback();
        LocaleContextStore::setUrlPrefixEnabled(true);
        LocaleContextStore::setDefaultLocale('en');
        LocaleContextStore::setSupportedLocales(['en', 'uk', 'de']);
    }

    protected function tearDown(): void
    {
        LocaleContextStore::clearFallback();
    }

    #[Test]
    public function a_path_that_already_has_the_locale_is_not_prefixed_twice(): void
    {
        // locale_url('/uk/a', 'uk') returned /uk/uk/a.
        $this->assertSame('/uk/a', (new LocaleTwigExtension())->localeUrl('/uk/a', 'uk'));
    }

    #[Test]
    public function a_path_in_another_locale_gets_its_prefix_replaced(): void
    {
        $extension = new LocaleTwigExtension();

        $this->assertSame('/de/a', $extension->localeUrl('/uk/a', 'de'));
        $this->assertSame('/a', $extension->localeUrl('/uk/a', 'en'));
    }

    #[Test]
    public function a_segment_that_is_not_a_locale_is_kept(): void
    {
        $this->assertSame('/uk/ukraine/a', (new LocaleTwigExtension())->localeUrl('/ukraine/a', 'uk'));
    }

    #[Test]
    public function a_bare_prefix_followed_by_a_query_is_replaced_too(): void
    {
        $extension = new LocaleTwigExtension();

        $this->assertSame('/uk/?x=1', $extension->localeUrl('/uk?x=1', 'uk'));
        $this->assertSame('/?x=1', $extension->localeUrl('/uk?x=1', 'en'));
    }

    /** `/uk/\evil.com` became `/\evil.com`, which a browser reads as `//evil.com`. */
    #[Test]
    public function stripping_a_prefix_never_leaves_a_path_to_another_host(): void
    {
        $extension = new LocaleTwigExtension();

        $this->assertSame('/evil.com', $extension->localeUrl('/uk/\\evil.com', 'en'));
        $this->assertSame('/evil.com', $extension->localeUrl("/uk/\t/evil.com", 'en'));
        $this->assertSame('/de/evil.com', $extension->localeUrl('/uk/\\evil.com', 'de'));
        // The switcher strips the prefix of the request's own path: on
        // `/uk//evil.com` it linked to `//evil.com`.
        $this->assertSame('/evil.com', $extension->localeUrl('/uk//evil.com', 'en'));
        $this->assertSame('/evil.com', $extension->localeUrl('//evil.com', 'en'));
        $this->assertSame('https://example.com/a', $extension->localeUrl('https://example.com/a', 'en'), 'a full URL is left alone');
        $this->assertSame('/evil.com', $extension->localeUrl("\t//evil.com", 'en'), 'a browser drops leading whitespace');
        $this->assertSame('/uk/x', $extension->localeUrl('//uk/x', 'uk'), 'cleaned before the prefix is read: not stacked');
        $this->assertSame('/x', $extension->localeUrl('//uk/x', 'en'));
    }
}
