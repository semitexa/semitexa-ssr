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
use Semitexa\Ssr\Application\Payload\Request\RobotsTxtPayload;
use Semitexa\Ssr\Seo\RobotsTxtRenderer;

#[AsPayloadHandler(payload: RobotsTxtPayload::class, resource: ResourceResponse::class)]
final class RobotsTxtHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsMutable]
    protected TenantContextInterface $tenantContext;

    public function handle(RobotsTxtPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setContent($this->resolveContent())
            ->setHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function resolveContent(): string
    {
        $projectRoot = ProjectRoot::get();

        foreach ([$projectRoot . '/robots.txt', $projectRoot . '/public/robots.txt'] as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $content = file_get_contents($candidate);
            if ($content !== false) {
                return $content;
            }
        }

        return RobotsTxtRenderer::render($this->request, $this->tenantContext);
    }
}
