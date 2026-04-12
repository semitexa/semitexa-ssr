<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Ssr\Application\Payload\Request\SitemapXmlPayload;
use Semitexa\Ssr\Seo\AiSitemapLocator;
use Semitexa\Ssr\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Seo\Sitemap\SitemapStoragePath;

#[AsPayloadHandler(payload: SitemapXmlPayload::class, resource: ResourceResponse::class)]
final class SitemapXmlHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsMutable]
    protected TenantContextInterface $tenantContext;

    #[InjectAsReadonly]
    protected ?SitemapGenerator $generator = null;

    public function handle(SitemapXmlPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setContent($this->resolveContent())
            ->setHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    private function resolveContent(): string
    {
        $projectRoot = ProjectRoot::get();
        $generatedDir = $this->resolveGeneratedSitemapDirectory();

        // Check for pre-generated or manual override files
        foreach ([
            $generatedDir . '/sitemap.xml',
            $projectRoot . '/sitemap.xml',
            $projectRoot . '/public/sitemap.xml',
        ] as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $content = file_get_contents($candidate);
            if ($content !== false) {
                return $content;
            }
        }

        // Fall back to dynamic generation
        return $this->generateDynamic();
    }

    private function generateDynamic(): string
    {
        if ($this->generator === null) {
            return $this->renderEmptySitemap();
        }

        $baseUrl = AiSitemapLocator::originUrl($this->request, $this->tenantContext);
        $context = new SitemapGenerationContext(
            baseUrl: $baseUrl,
            tenantContext: $this->tenantContext,
        );

        $outputDir = $this->resolveGeneratedSitemapDirectory();
        $result = $this->generator->generate($context);
        $this->persistGeneratedSitemaps($outputDir, $result);

        return $result['xml'];
    }

    /**
     * @param array{xml: string, parts: array<string, string>, totalUrls: int} $result
     */
    private function persistGeneratedSitemaps(string $outputDir, array $result): void
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Unable to create sitemap directory: {$outputDir}");
        }

        foreach ($result['parts'] as $filename => $xml) {
            if (file_put_contents($outputDir . '/' . $filename, $xml) === false) {
                throw new \RuntimeException("Unable to write sitemap part: {$filename}");
            }
        }

        if (file_put_contents($outputDir . '/sitemap.xml', $result['xml']) === false) {
            throw new \RuntimeException('Unable to write sitemap.xml');
        }
    }

    private function resolveGeneratedSitemapDirectory(): string
    {
        return SitemapStoragePath::generatedDirectory($this->tenantContext);
    }

    private function renderEmptySitemap(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>' . "\n";
    }
}
