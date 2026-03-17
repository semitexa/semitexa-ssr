<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

final readonly class DeferredBlockPayload
{
    public function __construct(
        public string $slotId,
        public string $mode,
        public ?string $html = null,
        public ?string $template = null,
        public array $data = [],
        public array $meta = [],
    ) {}

    public function toArray(): array
    {
        return match ($this->mode) {
            'template' => [
                'type' => 'deferred_block',
                'mode' => 'template',
                'slot_id' => $this->slotId,
                'template' => $this->template,
                'data' => $this->data,
                'meta' => $this->meta,
            ],
            default => [
                'type' => 'deferred_block',
                'mode' => 'html',
                'slot_id' => $this->slotId,
                'html' => $this->html,
                'meta' => $this->meta,
            ],
        };
    }
}
