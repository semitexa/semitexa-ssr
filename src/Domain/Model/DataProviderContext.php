<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

final readonly class DataProviderContext
{
    public function __construct(
        public ?object $request,
        public ?string $instanceId,
        public ?string $subscriberId,
    ) {}
}
