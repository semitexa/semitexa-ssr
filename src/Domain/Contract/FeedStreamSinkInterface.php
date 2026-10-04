<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Contract;

/**
 * Where feed control lands: the three stream commands the KISS server accepts
 * for a subscription on a page's one stream. {@see \Semitexa\Ssr\Application\Service\Async\SseServer}
 * is the implementation; HUG's feed control depends only on this.
 */
interface FeedStreamSinkInterface
{
    /**
     * @param array<string, mixed> $requestSnapshot the admitted feed request the owning worker rebuilds
     * @param string $routeName the feed's route name; a non-empty name also stamps the requester's tenant
     */
    public function submitSubscribe(
        string $sessionId,
        string $streamingId,
        string $routePath,
        string $routeMethod,
        array $requestSnapshot,
        string $routeName = '',
        ?string $requesterTenantId = null,
    ): bool;

    /** @param array<string, mixed> $params */
    public function submitViewChange(string $sessionId, array $params, ?string $streamingId = null): bool;

    public function submitUnsubscribe(string $sessionId, string $streamingId): bool;
}
