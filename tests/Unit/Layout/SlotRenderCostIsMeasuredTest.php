<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Layout;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry;

/**
 * Deferring is a decision that deserves a number.
 *
 * A skeleton buys patience for a region that takes time; for one that does not
 * it costs a round trip and a frame of placeholder to hide work that had
 * already finished, and it reads as jank. Measured on a real console
 * 2026-09-16: every page rendered in 13-31ms as a chrome-less fragment and
 * 24-50ms as a full document — not one region was slow enough to deserve a
 * skeleton, and the framework had no way to say so.
 *
 * Slot renders happen inside Twig, outside every pipeline seam, so the
 * waterfall showed one `response.render` and no way to attribute it. These
 * tests pin that a slot now reports its own cost, and — just as important —
 * that a production worker with no tracer pays nothing for the privilege.
 */
final class SlotRenderCostIsMeasuredTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $registrySnapshot = [];

    protected function setUp(): void
    {
        ModuleTemplateRegistry::reset();
        ModuleTemplateRegistry::setModuleRegistry(new ModuleRegistry());
        TwigExtensionRegistry::setClassDiscovery(new ClassDiscovery());

        // The slot registry is worker-global and populated by discovery. It has
        // no reset() on purpose — emptying it would empty it for every test in
        // this process, which is how a suite starts failing in company and
        // passing alone. Snapshot and restore instead.
        $this->registrySnapshot = $this->registryProperty()->getValue();
    }

    protected function tearDown(): void
    {
        LayoutSlotRegistry::setRequestTracer(null);
        $this->registryProperty()->setValue(null, $this->registrySnapshot);
    }

    private function registryProperty(): \ReflectionProperty
    {
        return new \ReflectionProperty(LayoutSlotRegistry::class, 'slots');
    }

    private function registerSlot(bool $deferred = false): void
    {
        LayoutSlotRegistry::register(
            handle: 'probe_page',
            slot: 'probe_slot',
            template: '@nonexistent/slot.html.twig',
            deferred: $deferred,
            resourceClass: 'App\\Slot\\MissingSlot',
        );
    }

    #[Test]
    public function aSlotRenderOpensAndClosesItsOwnSpan(): void
    {
        $tracer = $this->recordingTracer();
        LayoutSlotRegistry::setRequestTracer($tracer);
        $this->registerSlot();

        LayoutSlotRegistry::render('probe_page', 'probe_slot');

        $names = array_column($tracer->events, 'name');
        self::assertSame(['begin:slot.render', 'end:slot.render'], $names);
    }

    #[Test]
    public function theSpanNamesTheSlotAndWhetherItWasDeferred(): void
    {
        $tracer = $this->recordingTracer();
        LayoutSlotRegistry::setRequestTracer($tracer);
        $this->registerSlot(deferred: true);

        LayoutSlotRegistry::render('probe_page', 'probe_slot');

        $begin = $tracer->events[0]['context'];
        self::assertSame('probe_slot', $begin['slot']);
        self::assertSame('probe_page', $begin['handle']);
        self::assertSame('App\\Slot\\MissingSlot', $begin['resource']);
        self::assertTrue($begin['deferred'], 'the answer to "was this worth deferring" needs to know which it was');
    }

    #[Test]
    public function aFailedRenderStillClosesItsSpan(): void
    {
        // The slot renderer swallows its own failures by design — a broken
        // region must not take the page with it. An unclosed span would leave
        // every later span nested under a slot that is no longer rendering.
        $tracer = $this->recordingTracer();
        LayoutSlotRegistry::setRequestTracer($tracer);

        // No resource class, and a template that does not exist: Twig throws
        // straight out of the render loop.
        LayoutSlotRegistry::register(
            handle: 'probe_page',
            slot: 'probe_slot',
            template: '@nonexistent/missing.html.twig',
        );

        try {
            LayoutSlotRegistry::render('probe_page', 'probe_slot');
        } catch (\Throwable) {
            // The registry does not swallow this one — the span still closes.
        }

        self::assertSame(
            ['begin:slot.render', 'end:slot.render'],
            array_column($tracer->events, 'name'),
        );
    }

    #[Test]
    public function withNoTracerNothingIsBuiltAtAll(): void
    {
        // Production registers no tracer. The cost there has to stay one null
        // check per slot — the same bargain the request pipeline makes.
        LayoutSlotRegistry::setRequestTracer(null);
        $this->registerSlot();

        self::assertSame('', LayoutSlotRegistry::render('probe_page', 'probe_slot'));
    }

    private function recordingTracer(): RequestTracerInterface
    {
        return new class implements RequestTracerInterface {
            /** @var list<array{name: string, context: array<string, mixed>}> */
            public array $events = [];

            public function begin(string $name, array $context = []): void
            {
                $this->events[] = ['name' => 'begin:' . $name, 'context' => $context];
            }

            public function end(string $name, array $context = []): void
            {
                $this->events[] = ['name' => 'end:' . $name, 'context' => $context];
            }

            public function mark(string $name, array $context = []): void
            {
                $this->events[] = ['name' => 'mark:' . $name, 'context' => $context];
            }
        };
    }
}
