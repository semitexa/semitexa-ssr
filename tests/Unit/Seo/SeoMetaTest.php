<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Http\Response\HtmlResponse;
use Semitexa\Ssr\Seo\SeoMeta;

final class SeoMetaTest extends TestCase
{
    protected function tearDown(): void
    {
        SeoMeta::reset();
    }

    #[Test]
    public function page_title_stays_clean_and_sets_default_og_title(): void
    {
        $response = new class extends HtmlResponse {
        };

        $response->pageTitle('Semitexa Demo');

        self::assertSame('Semitexa Demo', SeoMeta::getTitle());
        self::assertSame('Semitexa Demo', SeoMeta::get('og:title'));
    }

    #[Test]
    public function keywords_are_merged_without_duplicates(): void
    {
        $response = new class extends HtmlResponse {
        };

        $response->seoKeywords(['routing', 'SSR', 'routing']);
        $response->seoKeywords(['SSR', 'tenancy']);

        self::assertSame('routing, SSR, tenancy', SeoMeta::get('keywords'));
    }

    #[Test]
    public function default_meta_values_do_not_override_explicit_values(): void
    {
        $response = new class extends HtmlResponse {
        };

        $response->seoTagDefault('description', 'Initial description');
        $response->seoTag('description', 'Explicit description');

        self::assertSame('Explicit description', SeoMeta::get('description'));
    }
}
