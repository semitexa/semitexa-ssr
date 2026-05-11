<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Ssr\Application\Payload\Request\UiEventEnvelopePayload;
use Semitexa\Ssr\Application\Service\UiEvent\InvalidUiEventEnvelopeException;
use Semitexa\Ssr\Application\Service\UiEvent\SignedContext;
use Semitexa\Ssr\Application\Service\UiEvent\UiEventEnvelope;

/**
 * Foundation handler for the unified HTTP UI event endpoint.
 *
 * Step-1 scope:
 *   1. Read the raw JSON body — not the hydrated payload — so smuggled
 *      handler-selection keys cannot be silently dropped by the setter
 *      convention. UiEventEnvelope::validateShape() then rejects any
 *      forbidden field at the top level OR inside scanned containers
 *      (payload / transport / metadata / context).
 *   2. Verify the signed context blob if present.
 *   3. Return a stable, dev-safe "not_implemented" response — handler
 *      resolution, dispatch through UiInteractionDispatcher, payload-DTO
 *      hydration, and response normalization land in later steps.
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
 * envelope + signed context (later) identify the source kind.
 */
#[AsPayloadHandler(payload: UiEventEnvelopePayload::class, resource: ResourceResponse::class)]
final class UiEventEndpointHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected Request $request;

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
        $signedOk = SignedContext::verify($envelope->signedContext) !== null;
        if (!$signedOk) {
            throw new ValidationException([
                'signedContext' => ['Signed context verification failed.'],
            ]);
        }

        $body = json_encode(
            [
                'status' => 'accepted',
                'phase' => 'foundation',
                'message' => 'UI event envelope received. Handler resolution is not implemented yet (step 1).',
                'eventId' => $envelope->eventId,
                'correlationId' => $envelope->correlationId,
                'semanticEvent' => $envelope->semanticEvent,
                'schemaVersion' => $envelope->schemaVersion,
                'signedContext' => [
                    'present' => true,
                    'verified' => true,
                ],
                'resolution' => [
                    'status' => 'not_implemented',
                    'reason' => 'handler_resolution_pending',
                    'plan' => 'See framework-layer-improvements.md §7.6 + §15 step 4.5.',
                ],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return $resource
            ->setStatusCode(202)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent($body);
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
}
