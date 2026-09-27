<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Seo\LlmsTxtRenderer;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;
use Semitexa\Tenancy\Context\TenantContext;

/**
 * llms.txt names the site it is served for and lists its pages.
 *
 * MEASURED on production: llms.txt listed no pages at all, and every tenant of
 * the multi-site install was introduced under the install's APP_NAME.
 */
final class LlmsTxtPagesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['APP_NAME', 'TENANT_MUSEUM_NAME', 'TENANT_SCHOOL_NAME'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv('APP_NAME=Semitexa App');
        putenv('TENANT_MUSEUM_NAME=City Museum');
        putenv('TENANT_SCHOOL_NAME=Hair School');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
    }

    #[Test]
    public function pages_are_markdown_links_with_title_and_description_when_given(): void
    {
        $out = LlmsTxtRenderer::render(null, null, [
            new SitemapUrl('https://museum.test/blog/first-light', title: 'First light', description: 'How the gallery opens at dawn.'),
            new SitemapUrl('https://museum.test/about'),
            new SitemapUrl('https://museum.test/'),
        ]);

        self::assertStringContainsString("## Pages\n", $out);
        self::assertStringContainsString('- [First light](https://museum.test/blog/first-light): How the gallery opens at dawn.', $out);
        self::assertStringContainsString('- [/about](https://museum.test/about)' . "\n", $out);
        self::assertStringContainsString('- [/](https://museum.test/)' . "\n", $out);
    }

    #[Test]
    public function the_list_is_capped_and_says_so(): void
    {
        $pages = [];
        for ($i = 1; $i <= 250; $i++) {
            $pages[] = new SitemapUrl('https://museum.test/p/' . $i);
        }

        $out = LlmsTxtRenderer::render(null, null, $pages);

        self::assertSame(LlmsTxtRenderer::MAX_PAGES, preg_match_all('#^- \[/p/\d+\]#m', $out));
        self::assertStringContainsString('50 more pages are not listed here', $out);
    }

    #[Test]
    public function no_pages_means_no_empty_section(): void
    {
        $out = LlmsTxtRenderer::render();

        self::assertStringStartsWith('# ', $out);
        self::assertStringNotContainsString('## Pages', $out);
    }

    #[Test]
    public function the_heading_names_the_tenant_being_served(): void
    {
        $museum = LlmsTxtRenderer::render(null, TenantContext::fromResolution('museum', 'test'));
        $school = LlmsTxtRenderer::render(null, TenantContext::fromResolution('school', 'test'));

        self::assertStringStartsWith("# City Museum\n", $museum);
        self::assertStringStartsWith("# Hair School\n", $school);
    }

    #[Test]
    public function without_a_tenant_name_it_falls_back_to_app_name(): void
    {
        self::assertStringStartsWith("# Semitexa App\n", LlmsTxtRenderer::render(null, TenantContext::fromResolution('unnamed', 'test')));
        self::assertStringStartsWith("# Semitexa App\n", LlmsTxtRenderer::render());
    }
}
