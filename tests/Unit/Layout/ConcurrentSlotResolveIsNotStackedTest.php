<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Ssr\Application\Service\DataProviderRegistry;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Domain\Model\DeferredSlotDefinition;

/**
 * A slot resolving on the deferred path still reports its cost — as a MARK.
 *
 * The whole point of that path is that slots resolve concurrently, one
 * coroutine per slot, all sharing the request's tracer. A span stack matched
 * by NAME cannot survive that: two coroutines that open `slot.resolve` and
 * close it in the other order have each closed the other's span, so the trace
 * reports nesting that never happened and durations belonging to a different
 * slot — and the tracer's own unwinding then marks live spans `unfinished`.
 *
 * A mark touches no stack, and carries the duration itself. The number the
 * instrumentation exists for — "is this region slow enough to deserve a
 * skeleton" — survives; the shared mutable state does not.
 */
final class ConcurrentSlotResolveIsNotStackedTest extends TestCase
{
    #[Test]
    public function aDeferredSlotRecordsOneMarkAndOpensNoSpan(): void
    {
        $tracer = $this->recordingTracer();

        $orchestrator = new DeferredBlockOrchestrator();
        $orchestrator->setRequestTracer($tracer);
        (new \ReflectionProperty(DeferredBlockOrchestrator::class, 'dataProviderRegistry'))
            ->setValue($orchestrator, new DataProviderRegistry());

        $resolve = new \ReflectionMethod(DeferredBlockOrchestrator::class, 'resolveSlotData');
        $resolve->invoke(
            $orchestrator,
            new DeferredSlotDefinition(
                slotId: 'nothing_registered_here',
                templateName: '@nonexistent/slot.html.twig',
                pageHandle: 'probe_page',
            ),
            'probe_page',
            [],
            null,
        );

        self::assertSame(
            ['mark:slot.resolve'],
            array_map(static fn (array $e): string => $e['kind'] . ':' . $e['name'], $tracer->events),
            'no begin() and no end(): the stack is shared and the callers are not sequential'
        );
        self::assertArrayHasKey('ms', $tracer->events[0]['context'], 'the duration travels with the mark');
        self::assertSame('nothing_registered_here', $tracer->events[0]['context']['slot']);
    }

    private function recordingTracer(): RequestTracerInterface
    {
        return new class implements RequestTracerInterface {
            /** @var list<array{kind: string, name: string, context: array<string, mixed>}> */
            public array $events = [];

            public function begin(string $name, array $context = []): void
            {
                $this->events[] = ['kind' => 'begin', 'name' => $name, 'context' => $context];
            }

            public function end(string $name, array $context = []): void
            {
                $this->events[] = ['kind' => 'end', 'name' => $name, 'context' => $context];
            }

            public function mark(string $name, array $context = []): void
            {
                $this->events[] = ['kind' => 'mark', 'name' => $name, 'context' => $context];
            }
        };
    }
}
