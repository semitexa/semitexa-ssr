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
}
