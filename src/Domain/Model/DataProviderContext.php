<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

final readonly class DataProviderContext
{
    /**
     * @param object|array<string, mixed>|null $request HTTP request — full Request object in sync path,
     *                                                  snapshot array {query, route, method} in deferred path.
     */
    public function __construct(
        public object|array|null $request = null,
        public ?string $instanceId = null,
        public ?string $subscriberId = null,
        public ?string $slotId = null,
        public ?string $pageHandle = null,
    ) {}
}
