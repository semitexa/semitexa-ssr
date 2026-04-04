<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsMutable;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Util\ProjectRoot;
use Semitexa\Ssr\Application\Payload\Request\LlmsTxtPayload;
use Semitexa\Ssr\Seo\LlmsTxtRenderer;

#[AsPayloadHandler(payload: LlmsTxtPayload::class, resource: GenericResponse::class)]
final class LlmsTxtHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsMutable]
    protected TenantContextInterface $tenantContext;

    public function handle(LlmsTxtPayload $payload, GenericResponse $resource): GenericResponse
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

        return LlmsTxtRenderer::render($this->request, $this->tenantContext);
    }
}
