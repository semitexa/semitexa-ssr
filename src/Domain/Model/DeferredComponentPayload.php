<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Domain\Model;

/**
 * SSE delivery envelope for a deferred component instance.
 *
 * Keyed by (component_name, instance_id) so the client can target the matching
 * <div data-ssr-deferred-component data-ssr-component-instance> placeholder.
 * Distinct from DeferredBlockPayload (layout slots) by design — the two
 * placeholder types are deliberately kept separate.
 *
 * @param array<string, mixed> $meta
 */
final readonly class DeferredComponentPayload
{
    public function __construct(
        public string $componentName,
        public string $instanceId,
        public string $html,
        public array $meta = [],
    ) {
        if ($this->componentName === '') {
            throw new \InvalidArgumentException('Component name must be non-empty.');
        }
        if ($this->instanceId === '') {
            throw new \InvalidArgumentException('Instance id must be non-empty.');
        }
    }

    /**
     * @return array{type: string, component_name: string, instance_id: string, html: string, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => 'deferred_component',
            'component_name' => $this->componentName,
            'instance_id' => $this->instanceId,
            'html' => $this->html,
            'meta' => $this->meta,
        ];
    }
}
