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
use Semitexa\Ssr\Application\Service\Seo\AiSitemapLocator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\GeneratedSitemapCache;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapStoragePath;

#[AsPayloadHandler(payload: SitemapXmlPayload::class, resource: ResourceResponse::class)]
final class SitemapXmlHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsMutable]
    protected TenantContextInterface $tenantContext;

    #[InjectAsReadonly]
    protected SitemapGenerator $generator;

    public function handle(SitemapXmlPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setContent($this->resolveContent())
            ->setHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    private function resolveContent(): string
    {
        $projectRoot = ProjectRoot::get();

        // A generated file is a cache with a maximum age, written by a given
        // release (GeneratedSitemapCache); a stale one is regenerated rather
        // than served for ever or across a deploy.
        $generated = GeneratedSitemapCache::readFreshIndex($this->resolveGeneratedSitemapDirectory());
        if ($generated !== null) {
            return $generated;
        }

        // Manual overrides are the project's own files and never age.
        foreach ([
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

        // Missing or stale: generate now (and persist for the next request).
        return $this->generateDynamic();
    }

    private function generateDynamic(): string
    {
        if (!isset($this->generator)) {
            return $this->renderEmptySitemap();
        }

        $baseUrl = AiSitemapLocator::originUrl($this->request, $this->tenantContext);
        $context = new SitemapGenerationContext(
            baseUrl: $baseUrl,
            tenantContext: $this->tenantContext,
        );

        $result = $this->generator->generate($context);
        // Persisting is best effort: an unwritable directory (files owned by
        // the scheduler's user, say) is logged by write() and must not turn a
        // sitemap this request already generated into an error.
        $this->generator->write($result, $this->resolveGeneratedSitemapDirectory());

        return $result['xml'];
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
