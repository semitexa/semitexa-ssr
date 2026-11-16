<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Support\Row;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;
use Semitexa\Ssr\Application\Service\Component\ComponentHtmlRenderer;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;

/**
 * The HUG fallback for deferred components a stream left pending
 * (`GET /__semitexa_hug?components=…`): each instance rendered here and now, from
 * the props its page recorded, as the visitor of this request — the route
 * pipeline has established who that is, which the KISS stream had to do itself.
 * The stream's own path is {@see DeferredBlockOrchestrator::streamDeferredBlocks()}.
 */
#[AsService]
final class DeferredComponentFallbackRenderer
{
    #[InjectAsReadonly]
    protected ComponentHtmlRenderer $componentRenderer;

    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    /**
     * One that fails to render is left out, and keeps its placeholder.
     *
     * @param list<array<string, mixed>> $instances as recorded with the page
     * @param array<mixed> $pageContext the page's recorded context: its live UI SSE session
     * @return array<string, string> instance id => rendered HTML
     */
    public function render(array $instances, array $pageContext = []): array
    {
        $uiSseSession = $pageContext['__ui_sse_session'] ?? null;
        $result = [];
        foreach ($instances as $instance) {
            $instanceId = Row::asString($instance['instance_id'] ?? '');
            $name = Row::asString($instance['name'] ?? '');
            $props = is_array($instance['props'] ?? null) ? $instance['props'] : [];
            if ($instanceId === '' || $name === '') {
                continue;
            }

            try {
                // Its `sub` claim names the page's live stream, as on the stream's path.
                if (is_string($uiSseSession) && $uiSseSession !== '') {
                    UiSseSessionState::restore($uiSseSession);
                }
                $result[$instanceId] = $this->componentRenderer->render($name, $props, [], forceImmediateRender: true);
            } catch (\Throwable $e) {
                SseSessionCoroutines::rethrowIfCancellation($e);
                $this->logger->error('Deferred component fallback render failed', [
                    'component' => $name,
                    'instance_id' => $instanceId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}
