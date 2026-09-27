<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Ssr\Application\Payload\Request\LlmsTxtPayload;
use Semitexa\Ssr\Application\Service\Seo\AiSitemapLocator;
use Semitexa\Ssr\Application\Service\Seo\LlmsTxtRenderer;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerationContext;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapGenerator;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\SitemapUrl;

#[AsPayloadHandler(payload: LlmsTxtPayload::class, resource: ResourceResponse::class)]
final class LlmsTxtHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsMutable]
    protected TenantContextInterface $tenantContext;

    #[InjectAsReadonly]
    protected SitemapGenerator $generator;

    public function handle(LlmsTxtPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setContent($this->resolveContent())
            ->setHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function resolveContent(): string
    {
        $projectRoot = ProjectRoot::get();

        foreach ([$projectRoot . '/llms.txt', $projectRoot . '/public/llms.txt'] as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            $content = file_get_contents($candidate);
            if ($content !== false) {
                return $content;
            }
        }

        return LlmsTxtRenderer::render($this->request, $this->tenantContext, $this->sitemapPages());
    }

    /**
     * The same pages sitemap.xml lists for this tenant. A failure here costs the
     * Pages section, never the file.
     *
     * @return list<SitemapUrl>
     */
    private function sitemapPages(): array
    {
        if (!isset($this->generator)) {
            return [];
        }

        try {
            return $this->generator->urls(new SitemapGenerationContext(
                baseUrl: AiSitemapLocator::originUrl($this->request, $this->tenantContext),
                tenantContext: $this->tenantContext,
            ));
        } catch (\Throwable $e) {
            StaticLoggerBridge::warning('ssr', 'llms.txt could not collect sitemap pages', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
