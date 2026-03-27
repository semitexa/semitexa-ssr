<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

final readonly class DeferredSlotDefinition
{
    public function __construct(
        public string $slotId,
        public string $templateName,
        public string $pageHandle,
        public string $mode = 'html',
        public int $priority = 0,
        public int $cacheTtl = 0,
        public ?string $dataProviderClass = null,
        public ?string $skeletonTemplate = null,
        public int $refreshInterval = 0,
        public ?string $resourceClass = null,
        public array $clientModules = [],
    ) {}
}
