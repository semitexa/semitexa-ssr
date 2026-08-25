<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Server\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\StopTrackRConsumerListener;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\WireTrackRConsumerListener;

/**
 * Gap C-3 (issue #100) — the teardown half of the Track R consumer wiring, and
 * specifically the PHASE it is bound to.
 *
 * The phase is the whole point of this test, because the obvious choice is wrong.
 * `WorkerStop` reads like "the worker is stopping", but Swoole raises it only
 * AFTER the event loop has exited — by which time `SwooleBootstrap`'s
 * `onWorkerExit` handler has already cancelled every parked coroutine, and the
 * subscribe loop has already caught, logged and reconnected. Binding here to
 * `WorkerExit` is what puts the announcement inside the `reload_async` drain
 * window, where it can still stop the loop from re-parking.
 *
 * Assertions on an attribute look like tautologies right up until someone
 * "tidies" the phase to the more obvious-sounding one and silently reopens #100.
 */
final class StopTrackRConsumerListenerTest extends TestCase
{
    private function attribute(string $class): AsServerLifecycleListener
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(AsServerLifecycleListener::class);
        self::assertCount(1, $attributes, "{$class} must declare exactly one lifecycle binding");

        return $attributes[0]->newInstance();
    }

    #[Test]
    public function it_binds_to_worker_exit_not_worker_stop(): void
    {
        $binding = $this->attribute(StopTrackRConsumerListener::class);

        self::assertSame(
            ServerLifecyclePhase::WorkerExit->value,
            $binding->phase,
            'WorkerStop runs after the event loop has already exited — too late to prevent the ERROR line',
        );
        self::assertNotSame(ServerLifecyclePhase::WorkerStop->value, $binding->phase);
    }

    #[Test]
    public function it_runs_early_within_the_exit_phase(): void
    {
        // Listeners run in ascending priority. The drain window is bounded by
        // max_wait_time (3s), so the announcement has to be near the front of it.
        $binding = $this->attribute(StopTrackRConsumerListener::class);

        self::assertLessThan(0, $binding->priority);
    }

    #[Test]
    public function it_is_the_mirror_of_the_wiring_listener(): void
    {
        // Both halves resolve the same SseServer singleton from the container, so
        // both must declare that they need one.
        $stop = $this->attribute(StopTrackRConsumerListener::class);
        $wire = $this->attribute(WireTrackRConsumerListener::class);

        self::assertTrue($stop->requiresContainer);
        self::assertTrue($wire->requiresContainer);
        self::assertSame(
            ServerLifecyclePhase::WorkerStartAfterContainer->value,
            $wire->phase,
            'the wiring half is unchanged — this test fails loudly if the pair drifts apart',
        );
    }

    #[Test]
    public function it_is_a_lifecycle_listener(): void
    {
        self::assertTrue(
            is_subclass_of(StopTrackRConsumerListener::class, ServerLifecycleListenerInterface::class),
        );
    }
}
