<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * Per-worker admission accounting for open SSE connections — the connection
 * caps that bound coroutine and file-descriptor pressure.
 *
 * Extracted during `ep-slay-sse-god-class` primarily to **collapse a
 * duplication**. The identical cap-check-and-account sequence lived twice in
 * {@see AsyncResourceSseServer}: once on the KISS admit path and once on the
 * held-open resource stream. Two copies of a counter protocol is exactly how the
 * counter-leak regression (see `SseConnectionCounterLeakTest`) became possible —
 * a fix applied to one path is not a fix. Acquire and release now exist once.
 *
 * State is per-worker and keyed, deliberately. The facade holds one instance per
 * worker process, and every entry is keyed by client IP or by connection key, so
 * two coroutines serving two different connections never touch the same entry.
 * This is the same shape the statics had, and it is correct — worth stating
 * because a shared mutable map looks like the coroutine trap and is not one
 * here. Note also that acquire is a tight synchronous block with no yield
 * between the cap check and the increment, so there is no TOCTOU window.
 *
 * Accounting rule worth knowing: a connection whose client IP could not be
 * resolved is **admitted without a per-IP entry** — there is nobody to attribute
 * it to — but it still counts toward the global cap. Those are two separate
 * counters on purpose: deriving the global total by summing the per-IP map would
 * let a flood of unattributable connections pass a cap they were never added to.
 */
final class SseConnectionLimiter
{
    public const DENIED_GLOBAL = 'SSE connection cap reached for this worker.';
    public const DENIED_PER_IP = 'SSE connection cap reached for your IP.';

    private const DEFAULT_MAX_CONN_PER_IP = 5;
    private const DEFAULT_MAX_CONN_GLOBAL = 500;

    /** @var array<string, int> Client IP → open-connection count on this worker. */
    private array $ipConnections = [];

    /** @var array<string, string> Connection key → client IP, so release can decrement. */
    private array $sessionIps = [];

    /**
     * Every admitted connection, attributable or not.
     *
     * Kept separately from {@see $ipConnections} because a connection whose
     * client IP could not be resolved is admitted WITHOUT a per-IP entry. Summing
     * the per-IP map would therefore miss it entirely, and a flood of
     * unattributable connections would never raise the global total — each one
     * passing a cap it should have been counted against.
     */
    private int $openConnections = 0;

    /** @var array<string, true> Connection keys admitted without an attributable IP. */
    private array $unattributed = [];

    /**
     * Check the caps and, if admitted, account the connection.
     *
     * The global cap is evaluated before the per-IP cap so a worker at capacity
     * reports the worker-level reason rather than blaming the caller's IP.
     *
     * @return string|null `null` when admitted (and accounted); otherwise the
     *                     message the caller should render as a 429.
     */
    public function tryAcquire(string $clientIp, string $sessionId, object $response): ?string
    {
        $maxPerIp = SseEnv::int('SSE_MAX_CONN_PER_IP', self::DEFAULT_MAX_CONN_PER_IP);
        $maxGlobal = SseEnv::int('SSE_MAX_CONN_GLOBAL', self::DEFAULT_MAX_CONN_GLOBAL);

        if ($this->openConnections >= $maxGlobal) {
            return self::DENIED_GLOBAL;
        }

        if ($clientIp !== '' && ($this->ipConnections[$clientIp] ?? 0) >= $maxPerIp) {
            return self::DENIED_PER_IP;
        }

        $connectionKey = self::connectionKey($sessionId, $response);
        if ($clientIp !== '') {
            $this->ipConnections[$clientIp] = ($this->ipConnections[$clientIp] ?? 0) + 1;
            $this->sessionIps[$connectionKey] = $clientIp;
        } else {
            $this->unattributed[$connectionKey] = true;
        }
        $this->openConnections++;

        return null;
    }

    /**
     * Release a connection's accounting. Idempotent and safe for a connection
     * that was never counted (an unresolvable client IP), which matters because
     * the caller registers this as a `Coroutine::defer` teardown that must run
     * on every exit path — including one an exception unwound past.
     */
    public function release(string $sessionId, object $response): void
    {
        $connectionKey = self::connectionKey($sessionId, $response);

        if (isset($this->unattributed[$connectionKey])) {
            unset($this->unattributed[$connectionKey]);
            $this->openConnections = max(0, $this->openConnections - 1);

            return;
        }

        $ip = $this->sessionIps[$connectionKey] ?? '';
        if ($ip === '') {
            return;
        }

        if (isset($this->ipConnections[$ip])) {
            $this->ipConnections[$ip]--;
            if ($this->ipConnections[$ip] <= 0) {
                unset($this->ipConnections[$ip]);
            }
        }

        unset($this->sessionIps[$connectionKey]);
        $this->openConnections = max(0, $this->openConnections - 1);
    }

    /**
     * A connection is identified by session AND response object: one session id
     * can legitimately be reconnected on a new response, and the old one's
     * teardown must not decrement the new one's slot.
     */
    public static function connectionKey(string $sessionId, object $response): string
    {
        return $sessionId . '#' . spl_object_id($response);
    }

    public function openConnectionsFor(string $clientIp): int
    {
        return $this->ipConnections[$clientIp] ?? 0;
    }

    /** Every open connection, including the ones with no attributable IP. */
    public function totalOpenConnections(): int
    {
        return $this->openConnections;
    }

    /** Only the connections attributed to a client IP. */
    public function attributedOpenConnections(): int
    {
        return array_sum($this->ipConnections);
    }

    /**
     * Drop all accounting. For tests and for a worker recycling its state — a
     * live worker never calls this, since releasing a connection it still holds
     * would let the caps be exceeded.
     */
    public function reset(): void
    {
        $this->ipConnections = [];
        $this->sessionIps = [];
        $this->unattributed = [];
        $this->openConnections = 0;
    }
}
