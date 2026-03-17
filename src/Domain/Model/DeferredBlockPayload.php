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
    ) {
        if (!in_array($this->mode, ['html', 'template'], true)) {
            throw new \InvalidArgumentException('Unsupported deferred block mode.');
        }
        if ($this->mode === 'template' && ($this->template === null || $this->template === '')) {
            throw new \InvalidArgumentException('Template mode requires a non-empty template path.');
        }
        if ($this->mode === 'html' && $this->html === null) {
            throw new \InvalidArgumentException('Html mode requires an html payload.');
        }
    }

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
