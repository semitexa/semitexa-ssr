<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Async;

use Semitexa\Core\Attributes\AsEventListener;
use Semitexa\Core\Events\HandlerCompleted;
use Semitexa\Core\Event\EventExecution;

#[AsEventListener(event: HandlerCompleted::class, execution: EventExecution::Async)]
final class HandlerCompletedListener
{
    public function handle(HandlerCompleted $event): void
    {
        $sessionId = $this->getCurrentSessionId();
        
        if (!$sessionId) {
            return;
        }

        AsyncResourceSseServer::broadcast(
            $sessionId,
            $event->handlerClass,
            $event->resource
        );
    }

    private function getCurrentSessionId(): ?string
    {
        return $_SESSION['semitexa_sse_session'] ?? null;
    }
}
