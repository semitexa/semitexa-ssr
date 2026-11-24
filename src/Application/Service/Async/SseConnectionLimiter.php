<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * Admission accounting for open SSE connections — the connection caps that
 * bound coroutine and file-descriptor pressure.
 *
 * THREE CAPS, with `G` = `SSE_MAX_CONN_GLOBAL`:
 *
 *  - server-wide, `G` x workers, counted in shared memory
 *    ({@see SseConnectionGauge}). This is the cap a deployment sizes: under
 *    dispatch_mode 2 a connection lands on `fd % workers` and the split is
 *    skewed (one worker of four held about 60% on the stand), so a cap counted
 *    per worker refused at 500 on one worker while the server held about 900
 *    of a nominal 2000;
 *  - per worker, `G` x {@see WORKER_CEILING_FACTOR}: the coroutine guard, loose
 *    enough to let a skewed split through; from three workers up it also keeps
 *    one process from holding the whole server's streams (with two, the
 *    ceiling equals the server cap);
 *  - per client IP (`SSE_MAX_CONN_PER_IP`), counted on this worker.
 *
 * WITHOUT SHARED MEMORY (a console process, a test, a server whose Swoole
 * tables were not created) there is nothing to sum, and the worker cap is `G`
 * itself: the behaviour before the server-wide cap existed.
 *
 * THE SERVER-WIDE CAP IS SOFT. The check reads the other workers' rows and
 * then counts this connection, and two workers can both do that at the last
 * free slot: the total can overshoot by at most workers - 1. That is accepted
 * for a cap whose purpose is to bound load; a lock on every connect is not.
 *
 * Extracted during `ep-slay-sse-god-class` primarily to **collapse a
 * duplication**. The identical cap-check-and-account sequence lived twice in
 * {@see AsyncResourceSseServer}: once on the KISS admit path and once on the
 * held-open resource stream. Two copies of a counter protocol is exactly how the
 * counter-leak regression (see `SseConnectionCounterLeakTest`) became possible —
 * a fix applied to one path is not a fix. Acquire and release now exist once.
 *
 * The per-IP and per-connection state is per-worker and keyed, deliberately. The facade holds one instance per
 * worker process, and every entry is keyed by client IP or by connection key, so
 * two coroutines serving two different connections never touch the same entry.
 * This is the same shape the statics had, and it is correct — worth stating
 * because a shared mutable map looks like the coroutine trap and is not one
 * here. Note also that acquire is a tight synchronous block with no yield
 * between the cap check and the increment, so there is no TOCTOU window.
 *
 * Accounting rule worth knowing: a connection whose client IP could not be
 * resolved is **admitted without a per-IP entry** — there is nobody to attribute
 * it to — but it still counts toward the worker and server caps. Those are two
 * separate counters on purpose: deriving the total by summing the per-IP map would
 * let a flood of unattributable connections pass a cap they were never added to.
 */
final class SseConnectionLimiter
{
    public const DENIED_SERVER = 'SSE connection cap reached for this server.';
    public const DENIED_WORKER = 'SSE connection cap reached for this worker.';

    /**
     * @deprecated The cap this named was the per-worker one, and its message
     *             says so: use {@see DENIED_WORKER}, or {@see DENIED_SERVER}
     *             for the server-wide refusal.
     */
    public const DENIED_GLOBAL = self::DENIED_WORKER;
    public const DENIED_PER_IP = 'SSE connection cap reached for your IP.';

    private const DEFAULT_MAX_CONN_PER_IP = 5;
    private const DEFAULT_MAX_CONN_GLOBAL = 500;

    /**
     * With a server-wide cap in force, one worker may hold this many times
     * `SSE_MAX_CONN_GLOBAL`: twice its even share.
     */
    public const WORKER_CEILING_FACTOR = 2;

    /**
     * @worker-scoped The server's shared counts and this worker's place in
     * them, set once when the worker starts ({@see shareAcrossWorkers()}); null
     * when this process counts alone. Static, like {@see SseOrder}'s counter,
     * because the worker's one limiter is created lazily inside the facade and
     * the worker-start listener has no handle on it.
     */
    private static ?SseConnectionGauge $workerGauge = null;
    private static int $workerGaugeId = 0;
    private static int $workerGaugePid = 0;

    /** An instance's own place, which wins over the worker's: several limiters in one process (a test) each stand for a worker. */
    private ?SseConnectionGauge $gauge = null;
    private int $workerId = 0;
    private int $pid = 0;

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
     * Count this worker's connections toward the server-wide cap. Called once
     * when the worker starts
     * ({@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\BindAsyncResourceSseServerListener}):
     * it takes the worker's row and resets it, so nothing the previous worker
     * with this id left behind (it crashed, was killed, or is draining) is
     * carried over. Null goes back to counting alone.
     */
    public static function shareAcrossWorkers(?SseConnectionGauge $gauge, int $workerId = 0, int $pid = 0): void
    {
        self::$workerGauge = $gauge;
        self::$workerGaugeId = $workerId;
        self::$workerGaugePid = $pid;
        $gauge?->claim($workerId, $pid);
    }

    /** The same for this one limiter only, whatever the process shares. */
    public function shareWith(SseConnectionGauge $gauge, int $workerId, int $pid): void
    {
        $this->gauge = $gauge;
        $this->workerId = $workerId;
        $this->pid = $pid;
        $gauge->claim($workerId, $pid);
        $gauge->publish($workerId, $pid, $this->openConnections);
    }

    /**
     * Check the caps and, if admitted, account the connection.
     *
     * The worker and server caps are evaluated before the per-IP cap so a
     * server at capacity reports that reason rather than blaming the caller's
     * IP.
     *
     * @return string|null `null` when admitted (and accounted); otherwise the
     *                     message the caller should render as a 429.
     */
    public function tryAcquire(string $clientIp, string $sessionId, object $response): ?string
    {
        $maxPerIp = SseEnv::int('SSE_MAX_CONN_PER_IP', self::DEFAULT_MAX_CONN_PER_IP);
        $maxGlobal = SseEnv::int('SSE_MAX_CONN_GLOBAL', self::DEFAULT_MAX_CONN_GLOBAL);

        $gauge = $this->gauge ?? self::$workerGauge;
        if ($gauge === null) {
            if ($this->openConnections >= $maxGlobal) {
                return self::DENIED_WORKER;
            }
        } else {
            if ($this->openConnections >= $maxGlobal * self::WORKER_CEILING_FACTOR) {
                return self::DENIED_WORKER;
            }
            // Soft: see the class comment for the overshoot of workers - 1.
            if ($gauge->total() >= $maxGlobal * $gauge->workerCount()) {
                return self::DENIED_SERVER;
            }
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
        $this->publish();

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
            $this->publish();

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
        $this->publish();
    }

    /** Tell the other workers how many connections this one holds now. */
    private function publish(): void
    {
        if ($this->gauge !== null) {
            $this->gauge->publish($this->workerId, $this->pid, $this->openConnections);

            return;
        }

        self::$workerGauge?->publish(self::$workerGaugeId, self::$workerGaugePid, $this->openConnections);
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

    /** Every open connection on this worker, including the ones with no attributable IP. */
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
        $this->publish();
    }
}
