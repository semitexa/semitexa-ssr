<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Ssr\Application\Payload\Request\SitemapJsonPayload;
use Semitexa\Ssr\Seo\AiSitemapJsonRenderer;

#[AsPayloadHandler(payload: SitemapJsonPayload::class, resource: ResourceResponse::class)]
final class SitemapJsonHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsMutable]
    protected TenantContextInterface $tenantContext;

    public function handle(SitemapJsonPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setContent($this->resolveContent())
            ->setHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function resolveContent(): string
    {
        $projectRoot = ProjectRoot::get();

        foreach ([$projectRoot . '/sitemap.json', $projectRoot . '/public/sitemap.json'] as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $content = file_get_contents($candidate);
            if ($content !== false) {
                return $content;
            }
        }

        return AiSitemapJsonRenderer::render($this->request, $this->tenantContext);
    }
}
