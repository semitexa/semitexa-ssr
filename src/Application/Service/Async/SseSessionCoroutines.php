<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Container\SemitexaContainer;
use Swoole\Coroutine;

/**
 * Which coroutines belong to which SSE session, and how to stop them.
 *
 * A stream spawns background work — the deferred-block trigger, the demo stream
 * producer — in coroutines that outlive the call that started them. When the
 * connection goes away, those coroutines must go with it, or a worker slowly
 * fills with producers writing to sockets nobody is reading.
 *
 * Registration happens **inside** the spawned coroutine rather than at the call
 * site, because only the coroutine itself can report its own id, and it
 * deregisters in a `finally` so an exception cannot leave a phantom id behind
 * that cancellation would later try to kill.
 *
 * Two details that look like bugs and are not:
 *
 * 1. **Cancellation skips the current coroutine.** Teardown normally runs inside
 *    one of the session's own coroutines; cancelling itself would abort the very
 *    cleanup in progress.
 * 2. **A cancelled coroutine's exception is swallowed, but only if it really is
 *    a cancellation.** Swoole signals cancellation by throwing inside the target,
 *    and that throw is expected. Anything else is rethrown — a genuine failure
 *    must not be silently eaten just because it happened in background work.
 *    The check is deliberately loose (class or message containing "cancel")
 *    because Swoole has not been consistent about the exception type across
 *    versions.
 *
 * No coroutine support (CLI, tests) means the callback runs **inline** and
 * `false` is returned, so callers get their side effects either way.
 */
final class SseSessionCoroutines
{
    /** @var array<string, array<int, true>> session id → set of live coroutine ids. */
    private array $bySession = [];

    /**
     * @param SemitexaContainer|null $container carries the parent's execution context
     *        into each spawned coroutine; null (tests, the facade fallback) spawns bare
     */
    public function __construct(
        private readonly ?SemitexaContainer $container = null,
    ) {}

    /**
     * Run `$callback` in a coroutine tracked against `$sessionId`.
     *
     * @return int|false the coroutine id, or `false` when it ran inline.
     */
    public function create(callable $callback, string $sessionId): int|false
    {
        if (!class_exists(Coroutine::class, false) || Coroutine::getCid() < 0) {
            $callback();

            return false;
        }

        // Execution context (auth, tenant, locale, session) is coroutine-local, so a
        // spawned coroutine starts without it. Measured: a deferred slot whose handler
        // injects AuthContextInterface was refused ("No execution context value") and
        // its region rendered empty. Carry the parent's context into the child.
        $container = $this->container;
        $context = $container?->captureExecutionContext();

        /** @var int|false $result */
        $result = Coroutine::create(function () use ($callback, $sessionId, $container, $context): void {
            $cid = self::currentCid();
            if ($cid >= 0) {
                $this->bySession[$sessionId][$cid] = true;
            }

            try {
                if ($container !== null && $context !== null) {
                    $container->runWithExecutionContext($context, $callback);
                } else {
                    $callback();
                }
            } catch (\Throwable $e) {
                if (!self::isCancellation($e)) {
                    throw $e;
                }
            } finally {
                // Re-read the id: this runs in the same coroutine, but reading it
                // again keeps the deregistration honest if the guard above failed.
                $cid = self::currentCid();
                if ($cid >= 0 && isset($this->bySession[$sessionId][$cid])) {
                    unset($this->bySession[$sessionId][$cid]);
                    if ($this->bySession[$sessionId] === []) {
                        unset($this->bySession[$sessionId]);
                    }
                }
            }
        });

        return $result;
    }

    /**
     * Cancel every coroutine registered to a session, except the caller's own.
     * Best-effort: a coroutine that has already finished, or refuses to die,
     * must not stop the rest of teardown.
     */
    public function cancelFor(string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || !isset($this->bySession[$sessionId])) {
            return;
        }

        $currentCid = self::currentCid();
        foreach (array_keys($this->bySession[$sessionId]) as $cid) {
            if ($cid < 0 || $cid === $currentCid) {
                continue;
            }

            try {
                self::cancel($cid);
            } catch (\Throwable) {
                // Best-effort cancellation only.
            }
        }
    }

    /**
     * Forget a session's coroutine set without cancelling — for teardown that
     * has already cancelled, or for a session being discarded wholesale.
     */
    public function forget(string $sessionId): void
    {
        unset($this->bySession[trim($sessionId)]);
    }

    /** @return list<int> live coroutine ids for a session. */
    public function idsFor(string $sessionId): array
    {
        return array_map('intval', array_keys($this->bySession[trim($sessionId)] ?? []));
    }

    public function hasAny(string $sessionId): bool
    {
        return ($this->bySession[trim($sessionId)] ?? []) !== [];
    }

    public static function currentCid(): int
    {
        if (!class_exists(Coroutine::class, false)) {
            return -1;
        }

        $cid = Coroutine::getCid();

        return is_int($cid) ? $cid : -1;
    }

    private static function cancel(int $cid): void
    {
        if (self::supportsSynchronousCancel()) {
            // The second arg forces a synchronous cancel that throws inside the
            // target — without it Coroutine::sleep() merely returns false and a
            // tight loop keeps running. The Swoole stub PHPStan sees omits it.
            /** @phpstan-ignore-next-line arguments.count */
            Coroutine::cancel($cid, true);

            return;
        }

        Coroutine::cancel($cid);
    }

    private static function supportsSynchronousCancel(): bool
    {
        static $supported;
        if (is_bool($supported)) {
            return $supported;
        }

        try {
            $supported = (new \ReflectionMethod(Coroutine::class, 'cancel'))->getNumberOfParameters() >= 2;
        } catch (\ReflectionException) {
            $supported = false;
        }

        return $supported;
    }

    /**
     * Let a cancellation keep unwinding. For a catch-all that logs failures:
     * a cancel is thrown INTO a coroutine so it stops, and caught there it read
     * as a failed slot on every restart while the coroutine carried on. The
     * session and stream entry points end it quietly.
     */
    public static function rethrowIfCancellation(\Throwable $e): void
    {
        if (self::isCancellation($e)) {
            throw $e;
        }
    }

    /**
     * Whether a throwable is Swoole signalling a cancellation rather than a real
     * failure. Public because the deferred-block trigger runs its own catch and
     * needs the same distinction — one definition, not two drifting copies.
     *
     * By EXACT type, through the previous-chain so a wrapped cancellation
     * still counts. Earlier versions matched "cancel" in the message, then in
     * the class name — both caught ordinary failures ('Payment cancellation
     * failed', PaymentCancellationFailedException), and now that cancellations
     * are rethrown that would abort a render instead of taking the failure
     * path. Swoole 6.2 throws Swoole\Coroutine\CanceledException, with an
     * empty message.
     */
    public static function isCancellation(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (in_array($current::class, self::CANCELLATION_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /** @var list<string> the coroutine cancellation exceptions Swoole throws */
    private const CANCELLATION_TYPES = [
        \Swoole\Coroutine\CanceledException::class,
    ];
}
