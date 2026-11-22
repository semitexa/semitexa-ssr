<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler\Fixture;

/** Duck-typed stand-in for core's final PipelineListenerRegistry: the executor only calls getListeners(). */
final class HugListenerRegistry
{
    /** @param list<class-string> $listeners the listeners of every phase asked for */
    public function __construct(private readonly array $listeners)
    {
    }

    /** @return list<array{class: class-string, phase: string, priority: int}> */
    public function getListeners(string $phaseClass): array
    {
        return array_map(
            static fn (string $class): array => ['class' => $class, 'phase' => $phaseClass, 'priority' => 0],
            $this->listeners,
        );
    }
}
