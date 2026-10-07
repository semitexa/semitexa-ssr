<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Component;

/**
 * A package's last word on a component's rendered HTML: it may annotate the
 * root or add markup beside it (ep-platform-live-state: Platform UI marks a
 * live island). Registered at worker boot, worker-lifetime, never request state.
 */
interface ComponentRenderFinisherInterface
{
    /**
     * @param class-string|string     $componentClass
     * @param array<array-key, mixed> $props the caller's own props — what a re-render must be given again
     */
    public function finish(string $componentName, string $componentClass, string $instanceId, array $props, string $html): string;
}
