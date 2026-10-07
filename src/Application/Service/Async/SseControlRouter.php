<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Server\PageTimeline;
use Semitexa\Core\Support\Row;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Server\SseTransportInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * The SSE control plane: what happens when a drain finds a control marker
 * instead of a data frame.
 *
 * Four commands — re-run, view change, subscribe, unsubscribe — and one rule
 * they all obey: **a control frame never reaches the socket as content.** Each
 * returns one of {@see SseControlFrame}'s outcomes, and the caller uses that to
 * decide whether to keep draining or close the stream.
 *
 * Every failure path here is deliberately contained. A re-run that throws is
 * logged and the stream survives for the next signal; a subscribe that throws
 * denies only that subscription rather than escaping and tearing down the KISS
 * drain loop, which would skip cleanup for the connection's sibling
 * subscriptions. Losing one feed must never cost a client the other four.
 *
 * Collaborators arrive as four objects rather than the nine this used to need,
 * because {@see SseRuntime} gathers the worker-boot ones. Those stay nullable:
 * before the lifecycle listeners run, every command degrades to a documented
 * safe no-op instead of crashing a drain that is otherwise healthy.
 */
final class SseControlRouter
{
    public function __construct(
        private readonly SseRuntime $runtime,
        private readonly SseFrameFactory $frames,
        private readonly SseSessionRegistry $sessions,
        private readonly SseReRunScope $scope,
    ) {
    }

    /**
     * Classify a drained frame and, if it is a control, act on it.
     *
     * @param array<string, mixed> $data
     * @return int one of {@see SseControlFrame}'s outcome codes.
     */
    public function handle(string $sessionId, mixed $response, array $data): int
    {
        return match (SseControlFrame::kindOf($data)) {
            SseControlFrame::RERUN => $this->handleReRun($sessionId, $response, $data),
            SseControlFrame::VIEWCHANGE => $this->handleViewChange($sessionId, $response, $data),
            SseControlFrame::SUBSCRIBE => $this->handleSubscribe($sessionId, $response, $data),
            SseControlFrame::UNSUBSCRIBE => $this->handleUnsubscribe($sessionId, $data),
            null => SseControlFrame::NOT_CONTROL,
            default => $this->refuseUnknownControl($sessionId, $data),
        };
    }

    /**
     * A frame carrying the control marker with a kind we do not implement.
     *
     * It is consumed, never written. Returning NOT_CONTROL here would hand it to
     * the drain, which would write `{"__ctrl":"..."}` to the browser as if it
     * were content — breaking the invariant that a control frame is a signal and
     * never bytes for the wire. Logged, because reaching this means a producer
     * and this router disagree about the protocol.
     *
     * @param array<string, mixed> $data
     */
    private function refuseUnknownControl(string $sessionId, array $data): int
    {
        StaticLoggerBridge::warning('ssr', 'sse_unknown_control_dropped', [
            'session_id' => $sessionId,
            'kind' => SseControlFrame::kindOf($data),
        ]);

        return SseControlFrame::HANDLED_CONTINUE;
    }

    /**
     * Register a subscription with the connect coordinator. Failures are logged,
     * never thrown: a stream that cannot register a feed still serves the ones
     * it already has.
     */
    public function attach(SubscriptionRecord $record, ReRunContext $context): void
    {
        $coordinator = $this->runtime->connectCoordinator;
        if ($coordinator === null) {
            return;
        }

        try {
            $coordinator->onConnect($record, $context);
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('ssr', 'multiplex_attach_failed', [
                'streaming_id' => $record->streamingId,
                'session_id' => $record->sessionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function detach(string $streamingId): void
    {
        $streamingId = trim($streamingId);
        if ($streamingId === '') {
            return;
        }

        $this->diff()->forget($streamingId);
        try {
            $this->runtime->connectCoordinator?->onDisconnect($streamingId);
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('ssr', 'multiplex_detach_failed', [
                'streaming_id' => $streamingId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A mutation-driven re-run: re-run the cached DTO verbatim, no override.
     *
     * The coalescer's pending mark is cleared on EVERY exit path, including the
     * ones that do nothing — leaving it set would suppress the next mutation's
     * signal for that stream, so a single unresolvable control would silently
     * freeze the feed.
     *
     * @param array<string, mixed> $data
     */
    private function handleReRun(string $sessionId, mixed $response, array $data): int
    {
        $streamingId = trim(Row::of($data)->string('streaming_id'));
        if ($streamingId === '') {
            return SseControlFrame::HANDLED_CONTINUE;
        }

        $context = SubscriptionDtoRegistry::get($streamingId);
        if ($context === null || $this->runtime->reRunner === null) {
            $this->clearPending($streamingId);

            return SseControlFrame::HANDLED_CONTINUE;
        }

        $outcome = $this->dispatchReRun($streamingId, $sessionId, $response, $context, [], true);
        $this->clearPending($streamingId);

        return $outcome;
    }

    /**
     * A view change: re-run with a FILTER-ONLY parameter override.
     *
     * The coalescer holds the latest view when several changes arrived in quick
     * succession; the inline `params` are the uncoalesced fallback for when no
     * coalescer is wired. Note the asymmetry with re-run: `consume()` already
     * clears the pending mark, so there is nothing to clear afterwards.
     *
     * @param array<string, mixed> $data
     */
    private function handleViewChange(string $sessionId, mixed $response, array $data): int
    {
        $streamingId = trim(Row::of($data)->string('streaming_id'));
        if ($streamingId === '') {
            return SseControlFrame::HANDLED_CONTINUE;
        }

        $override = $this->runtime->viewChangeCoalescer?->consume($streamingId);
        if ($override === null) {
            $inline = $data['params'] ?? null;
            $override = is_array($inline) ? Row::keyedByName($inline) : [];
        }

        $context = SubscriptionDtoRegistry::get($streamingId);
        if ($context === null || $this->runtime->reRunner === null) {
            return SseControlFrame::HANDLED_CONTINUE;
        }

        // A new view is a new page: sent whole, never as a patch.
        return $this->dispatchReRun($streamingId, $sessionId, $response, $context, $override, false);
    }

    /**
     * Attach a feed subscription to the fd-owning connection, then push its
     * authorized initial frame through the SAME re-run engine every tick uses.
     *
     * The re-run scope around that call is load-bearing: inside it the re-invoked
     * feed handler takes its `isReRunInProgress()` branch and returns the FRAMED
     * envelope carrying the `_type` the SSE chokepoint promotes to an `event:`
     * line. Without the scope it would return the raw `{data, meta}` body with no
     * `_type`, and the client's typed listener would never fire.
     *
     * @param array<string, mixed> $data
     */
    private function handleSubscribe(string $sessionId, mixed $response, array $data): int
    {
        $frame = Row::of($data);
        $streamingId = trim($frame->string('streaming_id'));
        if ($streamingId === '' || $this->runtime->subscriptionFactory === null || $this->runtime->reRunner === null) {
            return SseControlFrame::HANDLED_CONTINUE;
        }

        // A subscription admitted on HUG carries the tenant its request resolved;
        // it must be the tenant this KISS connection captured, or one tenant's
        // page could attach another tenant's feed. A named frame always carries
        // that claim; one without it is refused rather than left unchecked.
        $requesterTenant = $data['requester_tenant_id'] ?? null;
        $named = $frame->string('route_name') !== '';
        if (
            ($named && !is_string($requesterTenant))
            || (is_string($requesterTenant) && $requesterTenant !== ($this->sessions->capturedTenantId($sessionId) ?? ''))
        ) {
            return $this->deny($response, $streamingId, 'subscribe_tenant_mismatch', UiSseEventType::UiError->value);
        }

        $inlineSnapshot = $data['request_snapshot'] ?? null;
        $snapshot = is_array($inlineSnapshot) ? Row::keyedByName($inlineSnapshot) : [];
        $attachment = $this->runtime->subscriptionFactory->build(
            $sessionId,
            $streamingId,
            $frame->string('route_path'),
            $frame->string('route_method', 'GET'),
            $snapshot,
            // Scope the record to the tenant THIS connection resolved at connect
            // time, not the draining coroutine's ambient one.
            $this->sessions->capturedTenantId($sessionId),
            $this->sessions->capturedTenantBlob($sessionId),
            $frame->string('route_name'),
        );

        if ($attachment === null) {
            return $this->deny($response, $streamingId, 'subscribe_unresolved', UiSseEventType::UiError->value);
        }

        $this->scope->begin();
        try {
            $result = $this->runtime->reRunner->reRun($attachment->context, []);
        } catch (\Throwable $e) {
            // A throwing initial re-run must deny ONLY this subscribe — not escape
            // and tear down the whole KISS drain loop, which would skip cleanup
            // for the connection's sibling subscriptions.
            StaticLoggerBridge::error('ssr', 'Multiplex subscribe re-run failed', [
                'session_id' => $sessionId,
                'streaming_id' => $streamingId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->deny($response, $streamingId, 'subscribe_failed', $attachment->errorEventType);
        } finally {
            $this->scope->end();
        }

        if ($result->isTerminated() || $result->getFrame() === null) {
            // Not authorized for this feed (or no frame) — deny, register nothing.
            return $this->deny($response, $streamingId, 'subscribe_denied', $attachment->errorEventType);
        }

        // Authorized: register both tiers, THEN push the initial frame.
        $this->attach($attachment->record, $attachment->context);
        $this->diff()->accept($streamingId, ($data['patches'] ?? false) === true);
        PageTimeline::record($sessionId, 'subscribe', ['feed' => $frame->string('route_name'), 'sub' => $streamingId, 'patches' => ($data['patches'] ?? false) === true]);

        $wrote = $this->transport()->writeFrame(
            $response,
            $this->frames->build(SseFrameFactory::stampSubscriptionId($this->diff()->next($streamingId, $this->frameData($result->getFrame()), false) ?? [], $streamingId)),
        );

        if (!$wrote) {
            // Socket died writing the first frame — reap the just-registered tier.
            $this->detach($streamingId);

            return SseControlFrame::HANDLED_CLOSE;
        }

        return SseControlFrame::HANDLED_CONTINUE;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleUnsubscribe(string $sessionId, array $data): int
    {
        $streamingId = trim(Row::of($data)->string('streaming_id'));
        if ($streamingId !== '') {
            $this->detach($streamingId);
            PageTimeline::record($sessionId, 'unsubscribe', ['sub' => $streamingId]);
        }

        return SseControlFrame::HANDLED_CONTINUE;
    }

    /**
     * Run the re-run and write whatever it produced.
     *
     * @param array<string, mixed> $filterOverride
     */
    private function dispatchReRun(
        string $streamingId,
        string $sessionId,
        mixed $response,
        ReRunContext $context,
        array $filterOverride,
        bool $mayPatch = false,
    ): int {
        try {
            $this->scope->begin();
            try {
                $result = $this->runtime->reRunner?->reRun($context, $filterOverride);
            } finally {
                $this->scope->end();
            }
        } catch (\Throwable $e) {
            // A re-run failure must neither leak data nor kill the stream — log
            // and keep the stream alive for the next signal.
            StaticLoggerBridge::error('ssr', 'track_r_rerun_failed', [
                'streaming_id' => $streamingId,
                'session_id' => $sessionId,
                'override' => $filterOverride === [] ? 'none' : array_keys($filterOverride),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return SseControlFrame::HANDLED_CONTINUE;
        }

        if ($result === null) {
            return SseControlFrame::HANDLED_CONTINUE;
        }

        if ($result->isTerminated()) {
            // Lost-access path: the subject no longer has access. Emit a close
            // frame, NO data frame, and signal the loop to end the stream.
            $this->writeClose($response, $result->getReason() ?? 'unauthorized');

            return SseControlFrame::HANDLED_CLOSE;
        }

        $frame = $result->getFrame();
        if ($frame === null) {
            // Defensive: a non-terminated result with no frame — nothing to write.
            return SseControlFrame::HANDLED_CONTINUE;
        }

        // The re-run re-queried under the recipient's CURRENT authorization (and,
        // for a view change, the new view), so this is fresh, not the stale cached
        // value: a re-run over a now-absent resource yields the handler's
        // empty/"gone" frame, written as-is — no crash, no stale data.
        // A mutation re-run sends only what changed since the page this
        // subscription was last sent (a keyed patch), or nothing at all.
        $data = $this->diff()->next($streamingId, $this->frameData($frame), $mayPatch);
        PageTimeline::record($sessionId, 'rerun', [
            'sub' => $streamingId,
            'cause' => $mayPatch ? 'write' : 'view',
            'sent' => $data === null ? 'nothing' : (is_string($data['_type'] ?? null) ? $data['_type'] : 'frame'),
        ]);
        if ($data === null) {
            return SseControlFrame::HANDLED_CONTINUE;
        }
        if (!$this->transport()->writeFrame($response, $this->frames->build(SseFrameFactory::stampSubscriptionId($data, $streamingId)))) {
            return SseControlFrame::HANDLED_CLOSE;
        }

        return SseControlFrame::HANDLED_CONTINUE;
    }

    private function deny(mixed $response, string $streamingId, string $reason, string $errorEventType): int
    {
        $wrote = $this->transport()->writeFrame($response, $this->frames->build([
            '_type' => $errorEventType,
            'streaming_id' => $streamingId,
            'error' => $reason,
        ]));

        // A denial written to a dead socket must close the stream, like every
        // other write path here — otherwise the connection is never reaped.
        return $wrote ? SseControlFrame::HANDLED_CONTINUE : SseControlFrame::HANDLED_CLOSE;
    }

    private function writeClose(mixed $response, string $reason): void
    {
        $this->transport()->writeFrame($response, $this->frames->build([
            'event' => 'close',
            'reason' => $reason,
            'close' => true,
        ]));
    }

    /**
     * A control frame's body, keyed by name.
     *
     * `json_decode(..., true)` gives `array<mixed>` — a JSON ARRAY decodes to a
     * list, and a control frame that arrived as `[1,2]` would then be handed to
     * every `array<string, mixed>` parameter downstream with integer keys.
     * Narrowing here rather than at each of those keeps one answer to "what
     * shape is a frame".
     *
     * @return array<string, mixed>
     */
    private function frameData(HttpResponse $frame): array
    {
        $decoded = json_decode($frame->getContent(), true);

        return is_array($decoded) ? Row::keyedByName($decoded) : ['data' => $frame->getContent()];
    }


    private function clearPending(string $streamingId): void
    {
        $this->runtime->rerunCoalescer?->clearPending($streamingId);
    }

    private function diff(): CollectionFrameDiff
    {
        return $this->runtime->collectionDiff ??= new CollectionFrameDiff();
    }

    private function transport(): SseTransportInterface
    {
        return $this->runtime->transport ??= new SwooleSseTransport();
    }
}
