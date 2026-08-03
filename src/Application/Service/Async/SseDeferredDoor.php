<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;

/**
 * The door a `deferred_request_id` has to come through before its blocks stream.
 *
 * A deferred request is minted during the page render and redeemed moments later
 * over SSE. Two things have to be true for that to be safe: the redeemer must
 * hold the bind token the render issued, and the request must still be in the
 * registry. Either check failing ends the exchange politely with a terminal frame
 * rather than an error, because the usual cause is a stale tab or a double
 * redeem, not an attack.
 *
 * Note that {@see DeferredRequestRegistry::consume()} does not live up to its
 * name: it deletes the row only when the TTL has passed and otherwise returns the
 * entry where it is. Redemption is therefore bounded by that TTL, not by a single
 * use — which is why the door reads the registry twice rather than holding a
 * result it believes nobody else can obtain.
 *
 * Every failure path emits the same terminal frame: done, not live, close, do
 * not reconnect. Saying so in one place matters — the client's `close` listener
 * only fires deterministically if every abandonment looks identical, and this
 * literal was previously written out four separate times.
 *
 * Extracted from AsyncResourceSseServer by ep-slay-sse-god-class-2
 * (tk-sse2-deferred-door), which also collapsed the coroutine and non-coroutine
 * paths: they were the same body twice, differing only in a cancellation check
 * and whether the failure log carried a stack trace.
 */
final class SseDeferredDoor
{
    /**
     * The one way this exchange ends when it cannot proceed.
     *
     * `reconnect: false` is the load-bearing part. A client that retried here has
     * already failed the bind-token check or found no entry, and neither outcome
     * changes on a retry: the token is whatever the render issued, and an entry
     * that is absent only becomes more absent once its TTL passes. Retrying would
     * loop against a verdict that cannot flip.
     */
    private const TERMINAL_FRAME = [
        'type' => 'done',
        'live' => false,
        'close' => true,
        'reconnect' => false,
    ];

    /** @var \Closure(string): ?array{page_handle: string, page_context: mixed, locale: string, slots: mixed} */
    private readonly \Closure $readRegistry;

    /**
     * @param \Closure(): DeferredBlockOrchestrator          $orchestrator resolved lazily; throws if unwired
     * @param \Closure(string, array<string, mixed>): void   $deliver      queue a frame for a session
     * @param \Closure(mixed, array<string, mixed>): void    $writeFrame   write a frame straight to a response
     * @param \Closure(callable, string): void               $spawn        run a callable in a session coroutine
     * @param null|\Closure(string): ?array{page_handle: string, page_context: mixed, locale: string, slots: mixed} $readRegistry the page lookup; see below
     */
    public function __construct(
        private readonly \Closure $orchestrator,
        private readonly \Closure $deliver,
        private readonly \Closure $writeFrame,
        private readonly \Closure $spawn,
        ?\Closure $readRegistry = null,
    ) {
        // The second of the door's two registry reads, injectable purely so the
        // race between them can be exercised.
        //
        // open() checks the bind token and then streams, and both paths go through
        // DeferredRequestRegistry::consume(), so an entry that expires is caught by
        // the FIRST read — which means the interesting case, an entry that survives
        // the token check and is gone by the page lookup, was unreachable from a
        // test. It is reachable in production: another worker or a TTL sweep can
        // delete the row between the two calls. Defaults to the real registry, so
        // production behaviour is unchanged.
        $this->readRegistry = $readRegistry
            ?? static fn (string $id): ?array => DeferredRequestRegistry::consume($id);
    }

    /**
     * Admit a deferred request and start streaming its blocks.
     *
     * @param mixed $response   the live SSE response, written to directly on rejection
     * @param mixed $lastEventId resume point, when the client is reconnecting
     *
     * @return bool false when the door stayed shut — the caller must then close
     *              the connection, as the terminal frame has already gone out
     */
    public function open(
        mixed $response,
        string $sessionId,
        string $deferredRequestId,
        string $bindToken,
        mixed $lastEventId,
        bool $allowPersistentDeferredSse,
        bool $keepChannelOpen,
    ): bool {
        if (!DeferredRequestRegistry::matchesBindToken($deferredRequestId, $bindToken)) {
            // Written straight to the response rather than queued: the caller
            // closes the connection immediately after, so a queued frame might
            // never be flushed.
            ($this->writeFrame)($response, self::TERMINAL_FRAME);

            return false;
        }

        $this->stream(
            $sessionId,
            $deferredRequestId,
            is_string($lastEventId) ? $lastEventId : null,
            $allowPersistentDeferredSse,
            $keepChannelOpen,
        );

        return true;
    }

    private function stream(
        string $sessionId,
        string $deferredRequestId,
        ?string $lastEventId,
        bool $allowPersistentDeferredSse,
        bool $keepChannelOpen,
    ): void {
        $registry = ($this->readRegistry)($deferredRequestId);

        if ($registry === null) {
            // Already redeemed, expired, or never minted — or deleted between the
            // bind-token check and this read. Nothing to stream either way.
            self::debug('registry_null', ['deferred_request_id' => $deferredRequestId]);
            ($this->deliver)($sessionId, self::TERMINAL_FRAME);

            return;
        }

        self::debug('registry_found', [
            'deferred_request_id' => $deferredRequestId,
            'page_handle' => $registry['page_handle'],
            'slots' => $registry['slots'],
            'locale' => $registry['locale'],
        ]);

        $run = function () use ($sessionId, $registry, $lastEventId, $deferredRequestId, $allowPersistentDeferredSse, $keepChannelOpen): void {
            $this->streamBlocks(
                $sessionId,
                $registry,
                $lastEventId,
                $deferredRequestId,
                $allowPersistentDeferredSse,
                $keepChannelOpen,
            );
        };

        // Blocks resolve concurrently when there is a coroutine to run them in;
        // otherwise the same work happens inline. One body either way.
        if (self::inCoroutine()) {
            ($this->spawn)($run, $sessionId);

            return;
        }

        $run();
    }

    /**
     * @param array{page_handle: string, page_context: mixed, locale: string, slots: mixed} $registry
     */
    private function streamBlocks(
        string $sessionId,
        array $registry,
        ?string $lastEventId,
        string $deferredRequestId,
        bool $allowPersistentDeferredSse,
        bool $keepChannelOpen,
    ): void {
        try {
            $orchestrator = ($this->orchestrator)();
            self::debug('orchestrator_resolved', ['session_id' => $sessionId]);

            $orchestrator->streamDeferredBlocks(
                sessionId: $sessionId,
                pageHandle: $registry['page_handle'],
                pageContext: $registry['page_context'],
                lastEventId: $lastEventId,
                deferredRequestId: $deferredRequestId,
                locale: $registry['locale'] !== '' ? $registry['locale'] : null,
                startLiveLoop: $allowPersistentDeferredSse,
                keepChannelOpen: $keepChannelOpen,
            );
        } catch (\Throwable $e) {
            // A cancelled coroutine is a normal shutdown, not a failure: the
            // client went away and there is nobody left to send a frame to.
            if (SseSessionCoroutines::isCancellation($e)) {
                self::debug('streaming_cancelled', ['session_id' => $sessionId]);

                return;
            }

            self::debug('streaming_failed', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            StaticLoggerBridge::error('ssr', 'Deferred block streaming failed', [
                'session_id' => $sessionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            ($this->deliver)($sessionId, self::TERMINAL_FRAME);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function debug(string $message, array $data): void
    {
        StaticLoggerBridge::debug('ssr', $message, $data);
    }

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0;
    }
}
