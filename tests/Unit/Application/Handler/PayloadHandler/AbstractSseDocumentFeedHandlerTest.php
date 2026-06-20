<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Application\Handler\PayloadHandler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\WatchScopes;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Request;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Ssr\Application\Handler\PayloadHandler\AbstractSseDocumentFeedHandler;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Domain\Contract\DynamicallyScopedFeedInterface;
use Semitexa\Ssr\Domain\Contract\SseDocumentFeedPayloadInterface;

/**
 * Collaborative Form Data · Phase 1: the SSE single-document serving base's
 * pure seams — the canonical-envelope frame typing (`ui.document.data` /
 * `ui.document.error` through the single `_type` chokepoint, the object-valued
 * sibling of the collection types) and that the held-open choreography it
 * inherits verbatim from `AbstractSseFeedHandler` resolves a document
 * builder's envelope (and propagates auth deviations) identically to the
 * collection base. The held-open mechanics themselves are proven by the
 * collection feed's live gates; these tests pin the declaration-level
 * contracts the document vocabulary adds.
 */
final class AbstractSseDocumentFeedHandlerTest extends TestCase
{
    #[Test]
    public function a_success_envelope_frames_as_ui_document_data_with_the_envelope_intact(): void
    {
        $envelope = ['data' => ['id' => 'doc1', 'fields' => ['title' => 'hi']], 'meta' => ['version' => 3]];

        $framed = AbstractSseDocumentFeedHandler::frameData($envelope, true);

        self::assertSame('ui.document.data', $framed['_type']);
        self::assertSame($envelope['data'], $framed['data']);
        self::assertSame($envelope['meta'], $framed['meta']);
    }

    #[Test]
    public function an_error_envelope_frames_as_ui_document_error(): void
    {
        $framed = AbstractSseDocumentFeedHandler::frameData(
            ['error' => 'unknown_document', 'message' => 'nope', 'context' => []],
            false,
        );

        self::assertSame('ui.document.error', $framed['_type']);
        self::assertSame('unknown_document', $framed['error']);
    }

    #[Test]
    public function the_frame_type_key_wins_over_a_payload_supplied_type_field(): void
    {
        // `['_type' => …] + $envelope` — the chokepoint's `_type` must always
        // win; a record-sourced `_type` can never become the SSE event name.
        $framed = AbstractSseDocumentFeedHandler::frameData(['_type' => 'spoofed', 'data' => []], true);

        self::assertSame('ui.document.data', $framed['_type']);
    }

    #[Test]
    public function an_access_denied_from_the_builder_propagates_instead_of_framing(): void
    {
        // Auth-shaped exceptions are NOT document deviations: framing one would
        // mint a held-open stream for a DENIED caller. The inherited envelope
        // resolver must rethrow so initial connect 403s via the ExceptionMapper.
        $resolve = new \ReflectionMethod(AbstractSseDocumentFeedHandler::class, 'resolveEnvelope');
        $resolve->setAccessible(true);

        $this->expectException(AccessDeniedException::class);
        $resolve->invoke(new DenyingDocumentFeedHandlerFixture(), new DocumentPayloadStub(), new JsonResourceResponse());
    }

    #[Test]
    public function a_document_deviation_from_the_builder_becomes_the_error_envelope(): void
    {
        // Contrast pin: non-auth DomainExceptions stay framed — the SAME
        // `{error, message, context}` document a pull-mode 400 carries.
        $resolve = new \ReflectionMethod(AbstractSseDocumentFeedHandler::class, 'resolveEnvelope');
        $resolve->setAccessible(true);

        [$envelope, $success] = $resolve->invoke(new DeviatingDocumentFeedHandlerFixture(), new DocumentPayloadStub(), new JsonResourceResponse());

        self::assertFalse($success);
        self::assertSame('No such document.', $envelope['message']);
        self::assertArrayHasKey('error', $envelope);
        self::assertArrayHasKey('context', $envelope);
    }

    #[Test]
    public function a_subscribe_header_routes_to_the_subscribe_branch(): void
    {
        $handler = new DeviatingDocumentFeedHandlerFixture();
        $is = new \ReflectionMethod(AbstractSseDocumentFeedHandler::class, 'isSubscribeRequest');
        $is->setAccessible(true);

        $withHeader = new SubscribeIntakePayloadFixture(self::subscribeRequest());
        $without = new SubscribeIntakePayloadFixture(new Request('GET', '/feed/doc?ctx=tok', [], ['ctx' => 'tok'], [], [], []));

        self::assertTrue($is->invoke($handler, $withHeader));
        self::assertFalse($is->invoke($handler, $without));
    }

    #[Test]
    public function the_subscribe_snapshot_strips_intent_headers_and_normalises_the_method(): void
    {
        // The worker rebuilds + re-runs this snapshot; if an intent header or the
        // POST method survived, the re-run's serve() would re-enter the subscribe
        // branch instead of producing the frame. Auth cookies + feed query stay.
        $handler = new DeviatingDocumentFeedHandlerFixture();
        $snap = new \ReflectionMethod(AbstractSseDocumentFeedHandler::class, 'subscribeSnapshot');
        $snap->setAccessible(true);

        /** @var array<string,mixed> $out */
        $out = $snap->invoke($handler, new SubscribeIntakePayloadFixture(self::subscribeRequest()));

        self::assertSame('GET', $out['method'], 'normalised to the feed connect verb');
        $headerKeys = array_map('strtolower', array_keys($out['headers']));
        self::assertNotContains('x-semitexa-stream-subscribe', $headerKeys);
        self::assertNotContains('x-semitexa-kiss-session', $headerKeys);
        self::assertNotContains('x-semitexa-subscription-id', $headerKeys);
        self::assertContains('accept', $headerKeys, 'a non-intent header survives');
        self::assertSame(['session' => 'abc'], $out['cookies'], 'auth cookies preserved');
        self::assertSame(['ctx' => 'tok'], $out['query'], 'feed params preserved');
    }

    #[Test]
    public function a_multiplexed_view_change_targets_the_subscription_and_acks(): void
    {
        $accept = new \ReflectionMethod(AbstractSseDocumentFeedHandler::class, 'acceptViewChange');
        $accept->setAccessible(true);
        $handler = new DeviatingDocumentFeedHandlerFixture();
        $kiss = 'sse_' . str_repeat('a', 32);
        $sub = 'sse_' . str_repeat('b', 32);

        // Both multiplex coordinates present + valid → accepted (delivered to the
        // KISS session queue, targeting the subscription).
        $ok = $accept->invoke($handler, new ViewChangeIntakePayloadFixture($kiss, $sub, ''), new JsonResourceResponse());
        self::assertStringContainsString('"accepted":true', $ok->getContent());

        // A half-supplied multiplex pair (kiss without subscription) is rejected.
        $bad = $accept->invoke($handler, new ViewChangeIntakePayloadFixture($kiss, '', ''), new JsonResourceResponse());
        self::assertStringContainsString('"accepted":false', $bad->getContent());

        // No multiplex headers + a valid adopted stream id → the legacy
        // standalone path accepts (streaming_id == session_id).
        $legacy = $accept->invoke($handler, new ViewChangeIntakePayloadFixture('', '', $sub), new JsonResourceResponse());
        self::assertStringContainsString('"accepted":true', $legacy->getContent());
    }

    #[Test]
    public function a_rerun_frames_the_document_even_when_the_rebuilt_request_does_not_prefer_sse(): void
    {
        // REGRESSION (SSE transport unification · Phase 6): the multiplex re-run
        // rebuilds the request from the subscribe POST snapshot, which carries
        // `Accept: application/json` (NOT text/event-stream) — the attach rides a
        // POST control, not a GET SSE connect, so prefersSse() is structurally
        // false here. serve() MUST still frame the re-run body (prepend
        // `_type: ui.document.data`); otherwise the SSE chokepoint emits an
        // un-typed default `message` the client can never demux to its
        // per-subscription callback, and live updates silently never arrive.
        $out = self::invokeServeInReRunScope(
            new SucceedingDocumentFeedHandlerFixture(),
            new NonSsePullDocumentPayloadFixture(),
        );
        $decoded = json_decode($out->getContent(), true);

        self::assertSame('ui.document.data', $decoded['_type'] ?? null, 're-run body is the typed frame, not the raw pull');
        self::assertSame(['title' => 'hi'], $decoded['data']['values'] ?? null);
    }

    #[Test]
    public function a_non_sse_pull_outside_a_rerun_stays_the_raw_unframed_body(): void
    {
        // Contrast pin: the `&& !isReRunInProgress()` guard must NOT widen plain
        // pulls — a non-SSE request that is not a re-run still returns the
        // builder's byte-identical body, never the typed frame.
        $depth = new \ReflectionProperty(AsyncResourceSseServer::class, 'reRunDepthFallback');
        $depth->setAccessible(true);
        $depth->setValue(null, 0);

        $serve = new \ReflectionMethod(AbstractSseDocumentFeedHandler::class, 'serve');
        $serve->setAccessible(true);
        /** @var JsonResourceResponse $out */
        $out = $serve->invoke(new SucceedingDocumentFeedHandlerFixture(), new NonSsePullDocumentPayloadFixture(), new JsonResourceResponse());
        $decoded = json_decode($out->getContent(), true);

        self::assertArrayNotHasKey('_type', $decoded, 'a plain pull stays byte-identical — never framed');
        self::assertSame(['title' => 'hi'], $decoded['data']['values'] ?? null);
    }

    /**
     * Invoke the protected serve() with the static re-run scope active. A unit
     * test runs outside a Swoole coroutine, so {@see AsyncResourceSseServer::isReRunInProgress()}
     * reads the private `$reRunDepthFallback` counter — set it here, always reset.
     */
    private static function invokeServeInReRunScope(
        AbstractSseDocumentFeedHandler $handler,
        SseDocumentFeedPayloadInterface $payload,
    ): JsonResourceResponse {
        $depth = new \ReflectionProperty(AsyncResourceSseServer::class, 'reRunDepthFallback');
        $depth->setAccessible(true);
        $depth->setValue(null, 1);

        $serve = new \ReflectionMethod(AbstractSseDocumentFeedHandler::class, 'serve');
        $serve->setAccessible(true);

        try {
            /** @var JsonResourceResponse $out */
            $out = $serve->invoke($handler, $payload, new JsonResourceResponse());

            return $out;
        } finally {
            $depth->setValue(null, 0);
        }
    }

    private static function subscribeRequest(): Request
    {
        return new Request(
            'POST',
            '/feed/doc?ctx=tok',
            [
                'Accept' => 'application/json',
                'X-Semitexa-Stream-Subscribe' => '1',
                'X-Semitexa-Kiss-Session' => 'sse_' . str_repeat('a', 32),
                'X-Semitexa-Subscription-Id' => 'sse_' . str_repeat('b', 32),
            ],
            ['ctx' => 'tok'],
            [],
            [],
            ['session' => 'abc'],
        );
    }

    #[Test]
    public function watch_scopes_resolve_from_the_document_payload_class_declaration(): void
    {
        self::assertSame(
            ['formdoc:article:42'],
            AbstractSseDocumentFeedHandler::watchScopesOf(ScopedDocumentPayloadFixture::class),
        );
    }

    #[Test]
    public function dynamic_scopes_union_with_static_watch_scopes_dropping_blanks_and_dupes(): void
    {
        // A collaborative document's per-record scope is only known at request
        // time, so it rides DynamicallyScopedFeedInterface and is unioned with
        // the static #[WatchScopes] when the subscription record is built.
        $resolve = new \ReflectionMethod(AbstractSseDocumentFeedHandler::class, 'resolveScopeKeys');
        $resolve->setAccessible(true);

        $scopes = $resolve->invoke(null, new DynamicallyScopedDocumentPayloadFixture());

        self::assertSame(['static_scope', 'formdoc:article:42'], $scopes);
    }
}

#[WatchScopes('formdoc:article:42')]
final class ScopedDocumentPayloadFixture
{
}

#[WatchScopes('static_scope')]
final class DynamicallyScopedDocumentPayloadFixture implements SseDocumentFeedPayloadInterface, DynamicallyScopedFeedInterface
{
    public function getHttpRequest(): ?Request
    {
        return null;
    }

    public function getStreamId(): ?string
    {
        return null;
    }

    public function toViewParams(): array
    {
        return [];
    }

    public function dynamicWatchScopes(): array
    {
        // Includes a duplicate of the static scope and a blank — both dropped.
        return ['static_scope', '', 'formdoc:article:42'];
    }
}

final class DocumentPayloadStub implements SseDocumentFeedPayloadInterface
{
    public function getHttpRequest(): ?Request
    {
        return null;
    }

    public function getStreamId(): ?string
    {
        return null;
    }

    public function toViewParams(): array
    {
        return [];
    }
}

#[\Semitexa\Core\Attribute\AsPublicPayload(path: '/feed/doc', methods: ['GET', 'POST'])]
final class SubscribeIntakePayloadFixture implements SseDocumentFeedPayloadInterface
{
    public function __construct(private readonly Request $request) {}

    public function getHttpRequest(): ?Request
    {
        return $this->request;
    }

    public function getStreamId(): ?string
    {
        return null;
    }

    public function toViewParams(): array
    {
        return [];
    }
}

#[\Semitexa\Core\Attribute\AsPublicPayload(path: '/feed/doc', methods: ['GET', 'POST'])]
final class ViewChangeIntakePayloadFixture implements SseDocumentFeedPayloadInterface
{
    public function __construct(
        private readonly string $kissHeader,
        private readonly string $subHeader,
        private readonly string $streamId,
    ) {}

    public function getHttpRequest(): ?Request
    {
        $headers = ['X-Semitexa-Stream-Rehydrate' => '1'];
        if ($this->kissHeader !== '') {
            $headers['X-Semitexa-Kiss-Session'] = $this->kissHeader;
        }
        if ($this->subHeader !== '') {
            $headers['X-Semitexa-Subscription-Id'] = $this->subHeader;
        }

        return new Request('POST', '/feed/doc', $headers, [], [], [], []);
    }

    public function getStreamId(): ?string
    {
        return $this->streamId !== '' ? $this->streamId : null;
    }

    public function toViewParams(): array
    {
        return ['q' => 'x'];
    }
}

/** A plain document feed connect: `Accept: application/json`, no intent headers. */
#[\Semitexa\Core\Attribute\AsPublicPayload(path: '/feed/doc', methods: ['GET', 'POST'])]
final class NonSsePullDocumentPayloadFixture implements SseDocumentFeedPayloadInterface
{
    public function getHttpRequest(): ?Request
    {
        return new Request('GET', '/feed/doc?ctx=tok', ['Accept' => 'application/json'], ['ctx' => 'tok'], [], [], []);
    }

    public function getStreamId(): ?string
    {
        return null;
    }

    public function toViewParams(): array
    {
        return [];
    }
}

final class SucceedingDocumentFeedHandlerFixture extends AbstractSseDocumentFeedHandler
{
    protected function buildDocumentResponse(
        SseDocumentFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        return $this->jsonResponse($response, 200, ['data' => ['values' => ['title' => 'hi']], 'meta' => []]);
    }
}

final class DenyingDocumentFeedHandlerFixture extends AbstractSseDocumentFeedHandler
{
    protected function buildDocumentResponse(
        SseDocumentFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        throw new AccessDeniedException('Document is not readable.');
    }
}

final class DeviatingDocumentFeedHandlerFixture extends AbstractSseDocumentFeedHandler
{
    protected function buildDocumentResponse(
        SseDocumentFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        throw new DocumentDeviationFixtureException('No such document.');
    }
}

final class DocumentDeviationFixtureException extends DomainException
{
    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::NotFound;
    }
}
