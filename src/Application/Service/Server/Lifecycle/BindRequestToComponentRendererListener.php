<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Server\Lifecycle;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Ssr\Application\Service\Component\ComponentRenderer;

/**
 * Binds the in-flight Request into the static ComponentRenderer so that
 * #[WithDataProvider] providers can receive it via DataProviderContext::$request.
 *
 * Why a pipeline listener (and not a server-lifecycle one):
 *   ComponentRenderer::$currentRequest is per-request state. Setting it at
 *   worker-start would leak a single boot-time value across every request
 *   served by that worker. Binding here on AuthCheck (after session/locale,
 *   before HandleRequest dispatch) keeps the value scoped to the active
 *   coroutine via CoroutineLocal.
 */
#[AsPipelineListener(phase: AuthCheck::class, priority: 100)]
final class BindRequestToComponentRendererListener implements PipelineListenerInterface
{
    public function handle(RequestPipelineContext $context): void
    {
        ComponentRenderer::setCurrentRequest($context->request);
    }
}
