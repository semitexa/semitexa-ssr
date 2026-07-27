<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

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

        /** @var int|false $result */
        $result = Coroutine::create(function () use ($callback, $sessionId): void {
            $cid = self::currentCid();
            if ($cid >= 0) {
                $this->bySession[$sessionId][$cid] = true;
            }

            try {
                $callback();
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
     * Whether a throwable is Swoole signalling a cancellation rather than a real
     * failure. Public because the deferred-block trigger runs its own catch and
     * needs the same distinction — one definition, not two drifting copies.
     */
    public static function isCancellation(\Throwable $e): bool
    {
        $class = strtolower($e::class);
        $message = strtolower($e->getMessage());

        return str_contains($class, 'cancel') || str_contains($message, 'cancel');
    }
}
