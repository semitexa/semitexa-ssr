<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Event\HandlerCompleted;
use Semitexa\Core\Event\EventExecution;

#[AsEventListener(event: HandlerCompleted::class, execution: EventExecution::Async)]
final class HandlerCompletedListener
{
    public function handle(HandlerCompleted $event): void
    {
        $sessionId = trim($event->sessionId);
        if ($sessionId === '') {
            return;
        }

        AsyncResourceSseServer::broadcast(
            $sessionId,
            $event->handlerClass,
            $event->resource
        );
    }
}
