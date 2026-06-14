<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Ssr\Domain\Contract\ScopeInvalidationBusInterface;

/**
 * Default {@see ScopeInvalidationBusInterface} binding. Forwards a data-less
 * PUBLISH to the canonical KISS transport's Redis bus
 * ({@see AsyncResourceSseServer::publishScopeInvalidation()}), reusing the
 * existing size-1 SSE pool (a non-blocking request/reply PUBLISH is safe on
 * the pooled connection — only the subscriber's blocking loop needs a
 * dedicated connection, design §C.3). No new SSE endpoint, queue, or stream
 * is introduced; this is publisher-side only — there is no SUBSCRIBE here.
 */
#[SatisfiesServiceContract(of: ScopeInvalidationBusInterface::class)]
final class SseRedisScopeInvalidationBus implements ScopeInvalidationBusInterface
{
    public function publish(string $channel): void
    {
        AsyncResourceSseServer::publishScopeInvalidation($channel);
    }
}
