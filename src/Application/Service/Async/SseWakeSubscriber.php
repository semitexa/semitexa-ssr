<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\Row;
use Semitexa\Core\Support\StandingCoroutines;

/**
 * The worker's ear for "a frame was queued for one of your sessions".
 *
 * WHY (tk-idle-sse-stream-polls-redis). A held-open stream used to find what
 * another worker queued for it by asking: one LPOP per stream every 0.2 s, on
 * the worker's single SSE connection. A thousand idle streams were five
 * thousand round trips a second for frames that were not there (measured:
 * ~88% of a container's CPU with nothing to send). Now the worker that queues
 * a frame publishes the session id on {@see SseRedisSessionQueue::WAKE_CHANNEL},
 * and this class — ONE standing coroutine per worker — turns that into
 * {@see SseSessionRegistry::wake()}. A loop pops Redis when it is told to.
 *
 * WHAT A STREAM MAY ASSUME, and what it may not:
 *  - While {@see self::isHealthy()} is true, a wake published from now on
 *    arrives. The loop then parks until a deadline of its own.
 *  - While it is false — no subscription yet, Redis down, a reconnect in
 *    progress, the worker leaving — nothing is promised, and every loop polls
 *    on its old 0.2 s tick. Health is this object's to say: a loop never infers
 *    it from silence.
 *  - Every (re)subscribe wakes EVERY session ({@see self::onSubscribed()}): a
 *    wake published while nobody listened is gone, and the subscriber cannot
 *    know for whom. Losing the subscription wakes them too, so a loop parked
 *    on "healthy" learns it is on its own.
 *  - A wake is a hint, never the frame. The frame is in the Redis list, and a
 *    connection that died without a word (so this still reads as healthy) costs
 *    the loop's safety poll, {@see SseHeldOpenState::REDIS_SAFETY_POLL_SECONDS}.
 *
 * DEDICATED connection, exactly like {@see ResourceInvalidationSubscriber} and
 * for the same reason: a blocking SUBSCRIBE on the size-1 {@see SseRedisPool}
 * would starve every other Redis user on the worker. The connection comes from
 * {@see RedisSubscribeConnectionFactory} and this class names no pool.
 *
 * Teardown mirrors it too: a worker's exit drain cancels the parked read, which
 * is an operator action and not an outage — it is recognised from inside the
 * coroutine and latched, so a leaving worker neither logs it nor reconnects.
 */
final class SseWakeSubscriber
{
    /**
     * The loop's timer. Its existence is the guard {@see LivePubSubChannelController}
     * describes (a lone parked coroutine must not read as a deadlock); its
     * work, each tick, is {@see self::wakeTableDeliveries()}.
     */
    private const TICK_INTERVAL_MS = 200;

    /** The delivery signal as last read; null until the first tick, which therefore looks once. */
    private ?int $seenDeliverySignal = null;

    private bool $running = false;

    private bool $healthy = false;

    private bool $stopping = false;

    private ?\Predis\Client $activeConnection = null;

    private ?int $keepAliveTimerId = null;

    private readonly ReconnectAlarmPolicy $alarm;

    public function __construct(
        private readonly SseSessionRegistry $sessions,
        private readonly RedisSubscribeConnectionFactory $connectionFactory,
        ?ReconnectAlarmPolicy $alarm = null,
        private readonly ?SseWorkerTables $tables = null,
    ) {
        $this->alarm = $alarm ?? new ReconnectAlarmPolicy();
    }

    /** Is a subscription confirmed right now, so that a wake published from here on arrives? */
    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /**
     * Start the standing coroutine if it is not running — called when a stream
     * is about to be held open, so a worker that holds none keeps no connection.
     * Relaunchable: a loop that ended (it crashed) is started again by the next
     * stream. Never after teardown, and a no-op outside a coroutine runtime.
     */
    public function ensureRunning(): void
    {
        if ($this->running || $this->stopping) {
            return;
        }
        if (!class_exists(\Swoole\Coroutine::class, false) || \Swoole\Coroutine::getCid() < 0) {
            return;
        }

        $this->running = true;
        if (class_exists(\Swoole\Timer::class, false)) {
            $this->keepAliveTimerId = \Swoole\Timer::tick(self::TICK_INTERVAL_MS, function (): void {
                try {
                    $this->wakeTableDeliveries();
                } catch (\Throwable $e) {
                    StaticLoggerBridge::error('ssr', 'SSE deliver-table wake failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
                }
            });
        }
        \Swoole\Coroutine::create(function (): void {
            try {
                $this->subscribeLoop();
            } catch (\Throwable $e) {
                StaticLoggerBridge::error('ssr', 'SSE wake subscribe loop crashed', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            } finally {
                $this->healthy = false;
                $this->running = false;
                if ($this->keepAliveTimerId !== null) {
                    \Swoole\Timer::clear($this->keepAliveTimerId);
                    $this->keepAliveTimerId = null;
                }
                StandingCoroutines::forget();
            }
        });
    }

    private function subscribeLoop(): void
    {
        while (!$this->stopping) {
            $connection = $this->connectionFactory->create(); // DEDICATED — never the pool.
            $this->activeConnection = $connection;
            $subscribedAt = hrtime(true);

            StandingCoroutines::declare('sse wake receiver', 'subscribed to ' . SseRedisSessionQueue::WAKE_CHANNEL . ' — waiting for a frame queued by another worker');

            try {
                /** @var \Predis\PubSub\Consumer $pubsub */
                $pubsub = $connection->pubSubLoop(['subscribe' => [SseRedisSessionQueue::WAKE_CHANNEL]]);

                // Predis yields each message as a stdClass ({kind, channel, payload}).
                /** @var object{kind?: string, channel?: string, payload?: string} $message */
                foreach ($pubsub as $message) {
                    $kind = Row::asString($message->kind ?? null);
                    if ($kind === 'subscribe') {
                        $this->onSubscribed();
                    } elseif ($kind === 'message') {
                        $sessionId = Row::asString($message->payload ?? null);
                        StandingCoroutines::busy(fn () => $this->onMessage($sessionId));
                    }
                }
            } catch (\Throwable $e) {
                $this->onDropped($e, (hrtime(true) - $subscribedAt) / 1_000_000_000, self::wasCancelled());
            } finally {
                $this->markUnhealthy();
                $this->activeConnection = null;
                try {
                    $connection->disconnect();
                } catch (\Throwable) {
                    // Best-effort: the connection is being discarded either way.
                }
            }

            if ($this->stopping) {
                return;
            }

            \Swoole\Coroutine::sleep($this->alarm->backoffSeconds());

            // A cancellation landing during the backoff resumes the sleep early:
            // without this the loop would open a fresh connection in a worker
            // that is being torn down.
            if (self::wasCancelled()) {
                $this->latchTeardown();

                return;
            }
        }
    }

    /**
     * The subscription is confirmed. Healthy first, then everyone is woken: what
     * was queued while nobody listened was published to nobody, so each session
     * here asks Redis once. Until that pass a loop is still on its fallback tick
     * (it read "unhealthy" on its previous pass), so the gap closes from both sides.
     */
    public function onSubscribed(): void
    {
        if ($this->stopping) {
            return;
        }
        $this->healthy = true;
        $this->sessions->wakeAll();
    }

    /**
     * Wake the sessions another worker parked a frame for in the shared deliver
     * table — the path a frame takes when Redis refused it, so nothing was
     * published and a loop parked on a healthy-looking subscription would not
     * look for seconds (review of semitexa-ssr#190).
     *
     * Called every tick, and almost always one table cell read: the table is
     * scanned only when this worker's delivery signal moved. The wake does not
     * mark Redis dirty; the frame is not there.
     *
     * @return int how many sessions were woken
     */
    public function wakeTableDeliveries(): int
    {
        $signal = $this->tables?->deliverySignal();
        if ($this->tables === null || $signal === $this->seenDeliverySignal) {
            return 0;
        }
        // Recorded before the scan: a frame parked during it moves the signal again.
        $this->seenDeliverySignal = $signal;

        $woken = 0;
        foreach ($this->tables->sessionsWithDeliveries() as $sessionId) {
            $woken += $this->sessions->wake($sessionId, false) ? 1 : 0;
        }

        return $woken;
    }

    /** A wake arrived: the session's loop, if this worker holds it, goes round now. */
    public function onMessage(string $sessionId): bool
    {
        $sessionId = trim($sessionId);

        return $sessionId !== '' && $this->sessions->wake($sessionId);
    }

    /**
     * The subscription ended with an error. A cancellation is the worker
     * leaving (latched, silent); anything else is reported on the reconnect
     * alarm's ladder, so a flapping Redis is one escalating incident and not a
     * line per attempt.
     */
    public function onDropped(\Throwable $e, float $uptimeSeconds, bool $cancelled = false): void
    {
        // First, before anything that can yield: from here the loops poll.
        $this->markUnhealthy();
        if ($cancelled) {
            $this->latchTeardown();

            return;
        }
        if ($this->stopping) {
            return; // stop() closed the connection: announced, not a failure.
        }

        $recoveredFrom = $this->alarm->recordDwell($uptimeSeconds);
        if ($recoveredFrom !== null) {
            StaticLoggerBridge::info('ssr', 'SSE wake subscribe loop recovered', [
                'recovered_after_failed_reconnects' => $recoveredFrom,
                'uptime_seconds' => round($uptimeSeconds, 3),
            ]);
        }

        $level = $this->alarm->recordDrop();
        $context = [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'consecutive_failures' => $this->alarm->consecutiveFailures(),
            'uptime_seconds' => round($uptimeSeconds, 3),
            'next_retry_in_seconds' => $this->alarm->backoffSeconds(),
        ];

        match ($level) {
            ReconnectAlarmLevel::Error => StaticLoggerBridge::error('ssr', 'SSE wake subscribe loop cannot reconnect; held streams are polling Redis on the fallback tick', $context),
            ReconnectAlarmLevel::Warning => StaticLoggerBridge::warning('ssr', 'SSE wake subscribe loop has failed several reconnects; still retrying', $context),
            ReconnectAlarmLevel::Debug => StaticLoggerBridge::debug('ssr', 'SSE wake subscribe loop dropped; reconnecting', $context),
            ReconnectAlarmLevel::Silent => null,
        };
    }

    /**
     * Announce worker teardown: latch it, then close the dedicated connection
     * so the parked read unblocks and the loop exits. Idempotent, and safe when
     * no loop is running.
     */
    public function stop(): void
    {
        $this->latchTeardown();

        $connection = $this->activeConnection;
        if ($connection === null) {
            return;
        }

        try {
            $connection->disconnect();
        } catch (\Throwable) {
            // Best-effort: the loop's own finally discards it too.
        }
    }

    private function latchTeardown(): void
    {
        $this->stopping = true;
        $this->markUnhealthy();
        $this->alarm->reset();
    }

    /**
     * The subscription is gone, and every loop is told. A loop reads health
     * once per pass and then parks on that answer: one parked for seconds on
     * "healthy" would sit out the whole gap with nothing able to wake it.
     * Woken, it makes a pass, reads the new answer and is back on its tick.
     * Only on the change itself — a reconnect that keeps failing was never
     * healthy, and its loops are already ticking.
     */
    private function markUnhealthy(): void
    {
        if (!$this->healthy) {
            return;
        }
        $this->healthy = false;
        $this->sessions->wakeAll();
    }

    /** Was the current coroutine cancelled (the exit drain), read from inside it? */
    private static function wasCancelled(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) && (bool) \Swoole\Coroutine::isCanceled();
    }
}
