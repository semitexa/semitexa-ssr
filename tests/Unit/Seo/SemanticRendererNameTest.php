<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Seo\SemanticRenderer;
use Semitexa\Ssr\Application\Service\Seo\SeoMeta;

/**
 * The page's JSON-LD names it, or carries no name (bench-page-jsonld-empty-name:
 * a page titled in Twig had "name": "", since only SeoMeta was read).
 */
final class SemanticRendererNameTest extends TestCase
{
    protected function setUp(): void
    {
        SeoMeta::reset();
    }

    protected function tearDown(): void
    {
        SeoMeta::reset();
    }

    #[Test]
    public function the_seo_meta_title_names_the_page(): void
    {
        SeoMeta::setTitle('Orders');

        self::assertSame('Orders', SemanticRenderer::generateForResource(new \stdClass(), 'shop.orders', 'Ignored')['name'] ?? null);
    }

    #[Test]
    public function a_title_rendered_in_twig_names_the_page_as_text(): void
    {
        $page = SemanticRenderer::generateForResource(new \stdClass(), 'shop.contact', "  <b>Contact</b> &amp; support\n");

        self::assertSame('Contact & support', $page['name'] ?? null);
    }

    #[Test]
    public function a_page_with_no_title_carries_no_name_rather_than_an_empty_one(): void
    {
        foreach ([null, '', '   ', '&nbsp;', "<span>\u{00A0}\u{2003}</span>"] as $rendered) {
            self::assertArrayNotHasKey('name', SemanticRenderer::generateForResource(new \stdClass(), 'shop.blank', $rendered), var_export($rendered, true));
        }
    }

    /** A whitespace-only SeoMeta title is no title: the rendered one names the page. */
    #[Test]
    public function a_blank_seo_meta_title_gives_way_to_the_rendered_one(): void
    {
        SeoMeta::setTitle('   ');

        self::assertSame('Contact', SemanticRenderer::generateForResource(new \stdClass(), 'shop.contact', '&nbsp;Contact&nbsp;')['name'] ?? null);
    }
}
