<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Component\ComponentCatalog;
use Semitexa\Ssr\Application\Service\Component\ComponentReferenceScanner;
use Semitexa\Ssr\Application\Service\Component\ComponentRegistry;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;
use Semitexa\Ssr\Domain\Exception\UnknownComponentException;

/**
 * A template's component('…') names an #[AsComponent]; when the two drift
 * apart the page used to get an HTML comment and a silent hole.
 */
final class UnknownComponentTest extends TestCase
{
    private ?string $appEnv = null;

    private ?ComponentCatalog $previousCatalog = null;

    private mixed $previousRenderer = null;

    protected function setUp(): void
    {
        $this->appEnv = getenv('APP_ENV') === false ? null : (string) getenv('APP_ENV');
        $this->previousCatalog = (new \ReflectionProperty(ComponentRegistry::class, 'catalog'))->getValue();
        $this->previousRenderer = (new \ReflectionProperty(ComponentRenderer::class, 'renderer'))->getValue();
        $catalog = new ComponentCatalog();
        (new \ReflectionClass($catalog))->getProperty('initialized')->setValue($catalog, true);
        $catalog->register(['name' => 'ui-playground.live-ping', 'class' => '', 'template' => null, 'layout' => null, 'cacheable' => false, 'script' => null]);
        ComponentRegistry::setCatalog($catalog);
    }

    protected function tearDown(): void
    {
        $this->appEnv === null ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->appEnv);
        (new \ReflectionProperty(ComponentRegistry::class, 'catalog'))->setValue(null, $this->previousCatalog);
        (new \ReflectionProperty(ComponentRenderer::class, 'renderer'))->setValue(null, $this->previousRenderer);
    }

    #[Test]
    public function in_development_an_unknown_name_is_an_error_that_names_the_nearest_one(): void
    {
        putenv('APP_ENV=dev');

        $this->expectException(UnknownComponentException::class);
        $this->expectExceptionMessage("did you mean 'ui-playground.live-ping'?");

        ComponentRenderer::render('ui-playground.live-pnig');
    }

    #[Test]
    public function in_production_it_stays_a_comment_rather_than_an_error_page(): void
    {
        putenv('APP_ENV=prod');

        self::assertSame("<!-- Component 'nope' not found -->", ComponentRenderer::render('nope'));
    }

    #[Test]
    public function a_suggestion_is_offered_only_for_what_looks_like_a_typo(): void
    {
        $known = ['ui-playground.live-ping', 'platform.dashboard'];

        self::assertSame('ui-playground.live-ping', UnknownComponentException::closest('ui-playground.liveping', $known));
        self::assertNull(UnknownComponentException::closest('shop.cart', $known), 'another name altogether, not a typo');
    }

    #[Test]
    public function the_scanner_reads_calls_and_skips_code_samples_and_comments(): void
    {
        $source = "<div>\n{{ component('a.real') }}\n{# {{ component('a.comment') }} #}\n"
            . "{% verbatim %}\n{{ component('a.sample') }}\n{% endverbatim %}\n{{ component(\"b.real\", {}) }}\n{{ component(name) }}\n";

        self::assertSame([['a.real', 2], ['b.real', 7]], (new ComponentReferenceScanner())->references($source));
    }

    #[Test]
    public function the_scanner_reports_an_unknown_reference_with_its_place_and_a_suggestion(): void
    {
        $dir = sys_get_temp_dir() . '/semitexa-components-' . uniqid();
        mkdir($dir . '/pages', 0755, true);
        file_put_contents($dir . '/pages/a.html.twig', "<p>\n{{ component('ui-playground.live-pnig') }}\n{{ component('ui-playground.live-ping') }}\n");
        try {
            $issues = (new ComponentReferenceScanner())->unknownReferences([$dir], ['ui-playground.live-ping']);
        } finally {
            @unlink($dir . '/pages/a.html.twig');
            @rmdir($dir . '/pages');
            @rmdir($dir);
        }

        self::assertCount(1, $issues);
        self::assertSame(2, $issues[0]['line']);
        self::assertSame('ui-playground.live-pnig', $issues[0]['name']);
        self::assertSame('ui-playground.live-ping', $issues[0]['suggestion']);
    }

    #[Test]
    public function a_template_the_scanner_cannot_read_is_reported_not_skipped(): void
    {
        $dir = sys_get_temp_dir() . '/semitexa-components-' . uniqid();
        mkdir($dir, 0755, true);
        // A broken symlink: listed as a template, but there is nothing to read.
        symlink($dir . '/missing.html.twig', $dir . '/broken.html.twig');
        try {
            $issues = (new ComponentReferenceScanner())->unknownReferences([$dir], ['ui-playground.live-ping']);
        } finally {
            @unlink($dir . '/broken.html.twig');
            @rmdir($dir);
        }

        self::assertCount(1, $issues, 'a file the lint could not open is not a file it cleared');
        self::assertTrue($issues[0]['unreadable']);
        self::assertSame($dir . '/broken.html.twig', $issues[0]['path']);
    }
}
