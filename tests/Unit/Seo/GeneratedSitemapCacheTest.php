<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Seo;

require_once __DIR__ . '/Fixture/SitemapTestKit.php';

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Ssr\Application\Handler\PayloadHandler\SitemapXmlHandler;
use Semitexa\Ssr\Application\Payload\Request\SitemapXmlPayload;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapStoragePath;
use Semitexa\Ssr\Tests\Unit\Seo\Fixture\SitemapTestKit;
use Semitexa\Tenancy\Context\TenantContext;

/**
 * A generated sitemap is a cache with a maximum age.
 *
 * MEASURED on production: sitemap.xml was served from a file generated in April
 * and never refreshed — the install has no scheduler, so the regeneration job
 * never ran — advertising ~95 URLs of another tenant that 404 on the domain.
 */
final class GeneratedSitemapCacheTest extends TestCase
{
    private const string STALE_MARKER = 'https://other-tenant.test/from-april';

    private string $originalCwd;
    private string $root;
    private string|false $originalTtl;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: sys_get_temp_dir();
        $this->originalTtl = getenv('SITEMAP_CACHE_TTL');
        putenv('SITEMAP_CACHE_TTL');

        $this->root = sys_get_temp_dir() . '/semitexa-sitemap-cache-' . uniqid('', true);
        mkdir($this->root . '/src/modules', 0777, true);
        mkdir($this->root . '/public', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');

        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        ProjectRoot::reset();
        $this->originalTtl === false ? putenv('SITEMAP_CACHE_TTL') : putenv('SITEMAP_CACHE_TTL=' . $this->originalTtl);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_generated_file_older_than_a_day_is_regenerated_not_served(): void
    {
        $path = $this->writeGenerated(age: 86_400 + 60);

        $served = $this->serve();

        self::assertStringNotContainsString(self::STALE_MARKER, $served, 'a months-old cache was served as the sitemap');
        self::assertStringContainsString('<urlset', $served);
        self::assertStringNotContainsString(self::STALE_MARKER, (string) file_get_contents($path), 'the regenerated sitemap must replace the stale file');
    }

    #[Test]
    public function a_fresh_generated_file_is_served_as_is(): void
    {
        $this->writeGenerated(age: 60);

        self::assertStringContainsString(self::STALE_MARKER, $this->serve());
    }

    #[Test]
    public function the_max_age_comes_from_sitemap_cache_ttl(): void
    {
        $this->writeGenerated(age: 600);

        putenv('SITEMAP_CACHE_TTL=3600');
        self::assertStringContainsString(self::STALE_MARKER, $this->serve());

        putenv('SITEMAP_CACHE_TTL=300');
        self::assertStringNotContainsString(self::STALE_MARKER, $this->serve());
    }

    #[Test]
    public function a_manual_override_still_wins_over_regeneration(): void
    {
        $this->writeGenerated(age: 86_400 * 150);
        file_put_contents($this->root . '/public/sitemap.xml', '<urlset><!-- hand written --></urlset>');

        self::assertStringContainsString('hand written', $this->serve());
    }

    private function writeGenerated(int $age): string
    {
        $dir = SitemapStoragePath::generatedDirectory($this->tenant());
        mkdir($dir, 0777, true);
        $path = $dir . '/sitemap.xml';
        file_put_contents($path, '<urlset><url><loc>' . self::STALE_MARKER . '</loc></url></urlset>');
        touch($path, time() - $age);

        return $path;
    }

    private function serve(): string
    {
        $handler = new SitemapXmlHandler();
        SitemapTestKit::set($handler, 'request', new Request('GET', '/sitemap.xml', ['Host' => 'museum.test'], [], [], ['HTTP_HOST' => 'museum.test'], []));
        SitemapTestKit::set($handler, 'tenantContext', $this->tenant());
        SitemapTestKit::set($handler, 'generator', new SitemapGenerator());

        return (string) $handler->handle(new SitemapXmlPayload(), new ResourceResponse())->getContent();
    }

    private function tenant(): TenantContext
    {
        return TenantContext::fromResolution('museum', 'test');
    }
}
