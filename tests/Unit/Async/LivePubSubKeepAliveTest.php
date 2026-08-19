<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\LivePubSubChannelController;
use Swoole\Coroutine;
use Swoole\Timer;

/**
 * Pins the Track R · Gap C-2 deadlock guard, which until now only production
 * could falsify: the blocking `pubSubLoop` parks its coroutine on a socket
 * read, and when it is the ONLY live coroutine in an idle worker Swoole raises
 * the FATAL "all coroutines are asleep - deadlock!", killing the worker and
 * every held-open KISS connection it owns. The guarantee is a persistent
 * `Timer::tick` armed for exactly the loop's lifetime — its *existence*, not
 * its work, keeps the reactor non-empty.
 *
 * White-box on purpose: the arm/disarm pair is private and the controller's
 * collaborator is a final class wired for Redis, so these tests drive the
 * pair directly on a constructor-less instance. What they pin is the contract
 * a refactor could silently drop — armed means a REAL pending timer, disarm
 * clears it, and both are safe no-ops outside a coroutine runtime.
 */
final class LivePubSubKeepAliveTest extends TestCase
{
    private static function bareController(): LivePubSubChannelController
    {
        return (new \ReflectionClass(LivePubSubChannelController::class))->newInstanceWithoutConstructor();
    }

    private static function invoke(LivePubSubChannelController $controller, string $method): void
    {
        $m = new \ReflectionMethod(LivePubSubChannelController::class, $method);
        $m->setAccessible(true);
        $m->invoke($controller);
    }

    private static function timerId(LivePubSubChannelController $controller): ?int
    {
        $p = new \ReflectionProperty(LivePubSubChannelController::class, 'keepAliveTimerId');
        $p->setAccessible(true);

        /** @var ?int */
        return $p->getValue($controller);
    }

    #[Test]
    public function arming_registers_a_real_pending_timer_and_disarming_clears_it(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        $armedId = null;
        $existedWhileArmed = null;
        $existsAfterDisarm = null;
        $idAfterDisarm = 0;

        Coroutine\run(function () use (&$armedId, &$existedWhileArmed, &$existsAfterDisarm, &$idAfterDisarm): void {
            $controller = self::bareController();

            self::invoke($controller, 'startKeepAlive');
            $armedId = self::timerId($controller);
            $existedWhileArmed = $armedId !== null && Timer::exists($armedId);

            self::invoke($controller, 'stopKeepAlive');
            $existsAfterDisarm = $armedId !== null && Timer::exists($armedId);
            $idAfterDisarm = self::timerId($controller);
        });

        self::assertNotNull($armedId, 'startKeepAlive() must record the armed timer id.');
        self::assertTrue($existedWhileArmed, 'The keep-alive must be a REAL pending timer — a stored id with no timer keeps no reactor awake.');
        self::assertFalse($existsAfterDisarm, 'stopKeepAlive() must clear the timer, or it outlives the loop it exists for.');
        self::assertNull($idAfterDisarm, 'Disarming must reset the slot so the next loop can re-arm.');
    }

    #[Test]
    public function arming_twice_keeps_the_first_timer(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        $first = null;
        $second = null;

        Coroutine\run(function () use (&$first, &$second): void {
            $controller = self::bareController();

            self::invoke($controller, 'startKeepAlive');
            $first = self::timerId($controller);
            self::invoke($controller, 'startKeepAlive');
            $second = self::timerId($controller);

            self::invoke($controller, 'stopKeepAlive');
        });

        self::assertSame($first, $second, 'Re-arming while armed must be a no-op — a second timer would leak when the single id slot is overwritten.');
    }

    #[Test]
    public function the_loop_launcher_is_a_no_op_outside_a_coroutine(): void
    {
        // CLI/unit context: the blocking subscribe has no meaning, so
        // ensureLoopRunning() must neither flag the loop as running nor arm a
        // timer — otherwise every CLI command would leak a Swoole timer.
        $controller = self::bareController();

        self::invoke($controller, 'ensureLoopRunning');

        $running = new \ReflectionProperty(LivePubSubChannelController::class, 'loopRunning');
        $running->setAccessible(true);

        self::assertFalse($running->getValue($controller));
        self::assertNull(self::timerId($controller));
    }
}
