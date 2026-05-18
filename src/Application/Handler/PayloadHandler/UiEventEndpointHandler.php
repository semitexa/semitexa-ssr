<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Payload\Request\UiEventEnvelopePayload;
use Semitexa\Ssr\Application\Service\UiEvent\InvalidUiEventEnvelopeException;
use Semitexa\Ssr\Application\Service\UiEvent\NotConfiguredUiResponseDispatcher;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatcherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiResponseDispatchResult;
use Throwable;

/**
 * Canonical handler for `POST /__ui/event` — the framework's single unified
 * inbound UI event endpoint.
 *
 * Pipeline (each step fails closed before the next):
 *
 *   1. Read the raw JSON body (not the hydrated payload) so smuggled
 *      handler-selection keys cannot be silently dropped by the setter
 *      convention. {@see UiEventEnvelope::validateShape()} rejects any
 *      forbidden field at the top level OR inside scanned containers
 *      (payload / transport / metadata / context).
 *   2. Verify the signed context blob. An unverifiable blob NEVER reaches
 *      the dispatcher.
 *   3. Delegate to {@see UiResponseDispatcherInterface}. The endpoint
 *      itself is dispatcher-agnostic; concrete implementations are
 *      registered through the service-contract registry. The framework
 *      ships {@see NotConfiguredUiResponseDispatcher} as the default
 *      so downstream-less calls still produce a stable, well-typed
 *      response.
 *
 * Hard rule (framework-layer §11):
 *   Server-side metadata validated through signed context is the only source
 *   of handler identity. The signed context may reference a render id,
 *   manifest id, component instance id, or future server-side metadata
 *   record. The backend resolves the actual handler from server-side
 *   metadata. The frontend must never provide handler identity.
 *
 * The endpoint is intentionally single, unified, and source-kind-agnostic:
 * primitive / part / component / composite events all POST here, and the
 * envelope + signed context identify the source kind.
 *
 * Stable response shape (canonical envelope keys; dispatcher `body` fields
 * are folded in only if they do NOT collide with these — the allow-list
 * defends against a buggy dispatcher overwriting the canonical shape):
 *
 *   {
 *     "status":        "accepted" | "rejected" | "error",
 *     "phase":         "foundation" | "dispatch",
 *     "reason":        "<stable code>",
 *     "message":       "<operator-safe>",
 *     "eventId":       "<from envelope>",
 *     "correlationId": "<from envelope>",
 *     "semanticEvent": "<from envelope>",
 *     "schemaVersion": <int>,
 *     "signedContext": { "present": true, "verified": true }
 *     // + any non-colliding keys the dispatcher chose to surface.
 *   }
 */
#[AsPayloadHandler(payload: UiEventEnvelopePayload::class, resource: ResourceResponse::class)]
final class UiEventEndpointHandler implements TypedHandlerInterface
{
    /**
     * Canonical envelope keys the endpoint always emits. A dispatcher's
     * `UiResponseDispatchResult::$body` MUST NOT overwrite these — the
     * endpoint silently drops collisions rather than letting a buggy
     * dispatcher rewrite the contract.
     *
     * @var array<int, string>
     */
    private const RESERVED_ENVELOPE_KEYS = [
        'status', 'phase', 'reason', 'message',
        'eventId', 'correlationId', 'semanticEvent', 'schemaVersion',
        'signedContext',
    ];

    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsReadonly]
    protected UiResponseDispatcherInterface $dispatcher;

    public function handle(UiEventEnvelopePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $raw = $this->request->getJsonBody();
        // The body must be a JSON object, not a JSON array. `is_array($raw)`
        // alone is true for both — `array_is_list()` rejects list-shaped
        // bodies (`[]`, `[{}]`) so they cannot fall through and produce
        // misleading per-field envelope errors.
        if (!is_array($raw) || array_is_list($raw)) {
            throw new ValidationException([
                'body' => ['Request body must be a JSON object.'],
            ]);
        }

        try {
            $envelope = UiEventEnvelope::fromArray($raw);
        } catch (InvalidUiEventEnvelopeException $e) {
            throw new ValidationException($e->errors);
        }

        // Fail closed when a signed context is present but does not verify.
        // The signed-context blob is the trust boundary for server-side
        // handler resolution, so a `verified=false` envelope must never sit
        // on the success path where a later dispatch step might treat it as
        // healthy. An empty signedContext is rejected at envelope-shape
        // validation, so the only branch reaching here is a non-empty blob.
        $verifiedClaims = SignedContext::verify($envelope->signedContext);
        if ($verifiedClaims === null) {
            throw new ValidationException([
                'signedContext' => ['Signed context verification failed.'],
            ]);
        }

        try {
            $result = $this->dispatcher->dispatch($envelope, $verifiedClaims);
        } catch (Throwable) {
            // The dispatcher contract allows throwing, but we MUST NOT let
            // its stack trace / FQCN / secrets leak. Caller sees a stable
            // error reason; operator inspects logs through whatever
            // observability hook is wired by the dispatcher itself or the
            // framework's error pipeline.
            $result = new UiResponseDispatchResult(
                statusCode: 500,
                status:     'error',
                phase:      'dispatch',
                reason:     'ui_event_dispatcher_failure',
                message:    'UI event dispatcher failed to handle the request.',
            );
        }

        return $resource
            ->setStatusCode($result->statusCode)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent($this->encodeEnvelope($envelope, $result));
    }

    /**
     * Compose the canonical JSON envelope from the (already-validated)
     * UI event envelope + the dispatcher result. The composition is the
     * single place the wire shape lives — adding/removing fields here is
     * the only allowed contract change.
     */
    private function encodeEnvelope(UiEventEnvelope $envelope, UiResponseDispatchResult $result): string
    {
        $canonical = [
            'status'        => $result->status,
            'phase'         => $result->phase,
            'reason'        => $result->reason,
            'message'       => $result->message,
            'eventId'       => $envelope->eventId,
            'correlationId' => $envelope->correlationId,
            'semanticEvent' => $envelope->semanticEvent,
            'schemaVersion' => $envelope->schemaVersion,
            'signedContext' => [
                'present'  => true,
                'verified' => true,
            ],
        ];

        // Fold in the dispatcher's extra `body` fields, but drop any key
        // that would overwrite a reserved canonical field. The dispatcher
        // contract documents this — we enforce it here as defence in
        // depth (a buggy dispatcher cannot rewrite the envelope).
        foreach ($result->body as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array($key, self::RESERVED_ENVELOPE_KEYS, true)) {
                continue;
            }
            $canonical[$key] = $value;
        }

        return json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Test seam — InjectAsMutable does not run in unit tests, so allow the
     * test to attach a stub Request via this method without going through
     * the container.
     */
    public function withRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Test seam mirroring {@see withRequest()} for the dispatcher
     * dependency. Production wiring resolves the dispatcher through
     * `#[InjectAsReadonly]`; unit tests inject deterministic fakes
     * (e.g. a deny-all dispatcher, a throwing dispatcher, a recorder)
     * without booting the framework container.
     */
    public function withDispatcher(UiResponseDispatcherInterface $dispatcher): self
    {
        $this->dispatcher = $dispatcher;

        return $this;
    }
}
