<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

/**
 * Emits the showcase stream: an attach notification, then a tick on every wall-clock
 * minute boundary, for as long as the session stays open.
 *
 * This exists so a visitor can watch a live server-push arrive without any
 * application data behind it. It is doubly gated — `APP_DEBUG` must be truthy and
 * the client must ask for `demo_stream=showcase` — and the SSE auth gate rejects
 * guests outright, so it cannot fire on an ordinary production connection.
 *
 * ⚠️ It is nonetheless demonstration code living in a production transport
 * package. Its only consumer is `semitexa-demo`'s `sse-demo.js`, and the session
 * state it leans on (`tryStartDemoProducer` / `stopDemoProducer` on
 * {@see SseSessionRegistry}) is likewise a demo-only concern sitting in a
 * general-purpose collaborator. ep-slay-sse-god-class-2 lifted it out of
 * AsyncResourceSseServer's body, which was the part in scope; moving the whole
 * feature into semitexa-demo behind a seam is a separate, cross-package decision
 * recorded on tk-sse2-demo-producer.
 *
 * The producer runs in its own session coroutine and is registered with the
 * session registry so a reconnect cannot start a second one.
 */
final class SseDemoStreamProducer
{
    /**
     * The only stream name this producer answers to.
     */
    public const SHOWCASE = 'showcase';

    /**
     * How long to wait before announcing the attach, so the client has finished
     * wiring its listeners and actually sees the first frame.
     */
    private const ATTACH_DELAY_SECONDS = 0.35;

    private const TICK_PERIOD_SECONDS = 60.0;

    /**
     * Guard against a tick firing twice on the same minute: if the next boundary
     * is closer than this, skip to the following one.
     */
    private const BOUNDARY_EPSILON_SECONDS = 0.05;

    /**
     * @param \Closure(string, array<string, mixed>): void $deliver   push one frame to a session
     * @param \Closure(callable, string): void             $spawn     run a callable in a session coroutine
     */
    public function __construct(
        private readonly SseSessionRegistry $sessions,
        private readonly \Closure $deliver,
        private readonly \Closure $spawn,
    ) {
    }

    /**
     * Start the producer for a session, unless this is not the showcase stream,
     * one is already running, or there is no coroutine context to run in.
     */
    public function start(string $sessionId, string $demoStream): void
    {
        if ($demoStream !== self::SHOWCASE) {
            return;
        }

        if (!$this->sessions->tryStartDemoProducer($sessionId)) {
            return;
        }

        if (!self::inCoroutine()) {
            // Nothing can hold the loop open here, so release the slot rather
            // than leaving the session marked as having a live producer.
            $this->sessions->stopDemoProducer($sessionId);

            return;
        }

        ($this->spawn)(function () use ($sessionId): void {
            $this->run($sessionId);
        }, $sessionId);
    }

    private function run(string $sessionId): void
    {
        \Swoole\Coroutine::sleep(self::ATTACH_DELAY_SECONDS);

        if (!$this->sessions->isOpen($sessionId)) {
            $this->sessions->stopDemoProducer($sessionId);

            return;
        }

        $this->announceAttached($sessionId);

        $tick = 0;
        while ($this->sessions->isOpen($sessionId)) {
            \Swoole\Coroutine::sleep(self::secondsToNextBoundary(microtime(true)));

            if (!$this->sessions->isOpen($sessionId)) {
                break;
            }

            $this->announceTick($sessionId, ++$tick);
        }

        $this->sessions->stopDemoProducer($sessionId);
    }

    /**
     * Seconds until the next wall-clock minute boundary.
     *
     * Aligning to the wall clock — rather than sleeping a flat 60s — is what lets
     * the demo page show a countdown that visibly agrees with the server.
     *
     * Public because it is a pure function of the clock and the only part of this
     * class testable without a coroutine; exposing it beats adding another
     * reflection-into-a-private, which is the contract ep-slay-sse-god-class-2 is
     * trying to shrink rather than grow.
     */
    public static function secondsToNextBoundary(float $now): float
    {
        $remaining = self::TICK_PERIOD_SECONDS - fmod($now, self::TICK_PERIOD_SECONDS);

        return $remaining < self::BOUNDARY_EPSILON_SECONDS
            ? $remaining + self::TICK_PERIOD_SECONDS
            : $remaining;
    }

    private function announceAttached(string $sessionId): void
    {
        ($this->deliver)($sessionId, [
            'id' => 'demo_attached_' . self::sessionTag($sessionId),
            'event' => 'notification',
            'level' => 'info',
            'title' => 'Stream attached',
            'message' => 'The backend SSE stream is open. A new server-side minute tick will arrive every 60 seconds.',
            'source' => 'swoole-worker',
            'sent_at' => self::utcNow()->format(DATE_ATOM),
        ]);
    }

    private function announceTick(string $sessionId, int $tick): void
    {
        $sentAt = self::utcNow();

        ($this->deliver)($sessionId, [
            'id' => 'demo_minute_' . $tick . '_' . self::sessionTag($sessionId),
            'event' => 'scheduler.tick',
            'level' => 'success',
            'title' => 'Minute boundary reached',
            'message' => sprintf(
                'Backend minute tick #%d emitted at %s. The countdown should now restart for the next full minute.',
                $tick,
                $sentAt->format('H:i:s')
            ),
            'source' => 'scheduler',
            'tick' => $tick,
            'sent_at' => $sentAt->format(DATE_ATOM),
        ]);
    }

    /**
     * Short, stable per-session suffix so frame ids are unique across concurrent
     * sessions without putting the session id itself on the wire.
     */
    private static function sessionTag(string $sessionId): string
    {
        return substr(md5($sessionId), 0, 8);
    }

    private static function utcNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0;
    }
}
