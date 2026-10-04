<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\WatchScopes;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\AuthenticationException;
use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;

/**
 * The serving choreography shared by every canonical feed on the
 * `{data, meta}` envelope — collection (list) and document (single record)
 * alike. Lives ONCE, in semitexa-ssr (the owner of KISS and Track-R).
 *
 * A feed has exactly two ways to be read, and neither is a stream of its own:
 *   - a plain JSON GET — the builder's response, untouched (typed 400s keep
 *     propagating to the ExceptionMapper);
 *   - a re-run on the worker that owns the page's KISS stream — the feed was
 *     subscribed there through HUG ({@see \Semitexa\Ssr\Application\Service\Stream\FeedStreamControl}),
 *     and every change re-runs it; the SAME envelope comes back framed with
 *     this feed's typed event, a domain error as an error frame (an exception
 *     on a stream has no response to ride).
 *
 * A concrete feed base supplies THREE seams:
 *   - {@see buildResponse()} — "bind a source to criteria and render the
 *     canonical envelope" (the only domain-specific logic);
 *   - {@see successEventType()} / {@see errorEventType()} — the typed SSE
 *     event names this feed's data/error frames ride (`ui.collection.*` for a
 *     collection, `ui.document.*` for a document), funnelled through the single
 *     {@see frame()} chokepoint so a client-controlled `_type` can never spoof
 *     a framework event name.
 */
abstract class AbstractSseFeedHandler
{
    #[InjectAsReadonly]
    protected SseServer $sseServer;

    /**
     * Container-injected in production; falls back to the facade's wired
     * instance so a subclass constructed bare (fixtures, scripts) still talks
     * to the same per-worker SSE state instead of exploding on an
     * uninitialized typed property.
     */
    private function sse(): SseServer
    {
        return $this->sseServer ??= AsyncResourceSseServer::instance();
    }

    /**
     * Payload class → declared `#[WatchScopes]` keys, memoized per worker.
     * The declaration is classmap-stable for the life of a worker, so it is
     * reflected once per feed payload class, not once per connect.
     *
     * @var array<class-string, list<string>>
     */
    private static array $watchScopeCache = [];

    // ---- The feed-specific seams -------------------------------------------

    /**
     * Build the feed's canonical response from its typed payload — the single
     * envelope-resolution path, shared by both response modes. Feed deviations
     * keep raising their typed {@see DomainException}s; this base decides per
     * transport whether they propagate (JSON pull) or become an error frame
     * (SSE).
     */
    abstract protected function buildResponse(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse;

    /** The typed SSE event name this feed's success (data) frames ride. */
    abstract protected function successEventType(): UiSseEventType;

    /** The typed SSE event name this feed's error frames ride. */
    abstract protected function errorEventType(): UiSseEventType;

    // ---- The shared pipeline -----------------------------------------------

    /**
     * The one intake. A re-run tick on the KISS-owning worker gets the framed
     * body; anything else is a plain pull and gets the builder's response
     * untouched. A concrete handler's `handle(ConcretePayload,
     * ConcreteJsonResponse)` delegates here.
     */
    protected function serve(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        if (!$this->sse()->isReRunInProgress()) {
            return $this->buildResponse($payload, $response);
        }

        [$envelope, $success] = $this->resolveEnvelope($payload, $response);

        return $this->jsonResponse($response, $success ? 200 : 400, $this->frame($envelope, $success));
    }

    /**
     * Resolve the canonical envelope once for a re-run tick.
     * A success is the builder's own rendered body decoded back to an array
     * (so the frame and the pull body stay the same document); a feed
     * deviation ({@see DomainException}: invalid sort / filter / pagination /
     * cursor / record key) becomes the ExceptionMapper-shaped error body — the
     * SAME `{error, message, context}` document a pull-mode 400 carries.
     *
     * Auth-shaped exceptions are NOT feed deviations and always propagate to
     * {@see \Semitexa\Core\Pipeline\RouteExecutor::reExecute()}, which
     * TERMINATEs the subscription — the same lost-access guarantee the
     * route-level subject gate provides. (A denied caller never got this far:
     * HUG admitted the subscribe through the same gate.)
     *
     * @return array{0: array<string, mixed>, 1: bool} [envelope, success]
     */
    protected function resolveEnvelope(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): array {
        try {
            $built = $this->buildResponse($payload, $response);
            $decoded = json_decode($built->getContent(), true);

            return [is_array($decoded) ? $decoded : [], true];
        } catch (AuthenticationException|AccessDeniedException $e) {
            throw $e;
        } catch (DomainException $e) {
            return [[
                'error'   => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'context' => $e->getErrorContext(),
            ], false];
        }
    }

    /**
     * Wrap the canonical envelope in its typed SSE frame body: prepend the
     * `_type` the server's frame chokepoint promotes to an `event:` line (this
     * feed's {@see successEventType()} / {@see errorEventType()}). Used for
     * BOTH the initial frame and the re-run body, so the two are
     * byte-identical. The `['_type' => …] + $envelope` order makes the frame
     * type ALWAYS win over a row-sourced `_type` — a hostile payload can never
     * spoof the SSE event name.
     *
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    protected function frame(array $envelope, bool $success): array
    {
        $type = $success ? $this->successEventType() : $this->errorEventType();

        return ['_type' => $type->value] + $envelope;
    }

    /**
     * The live-on-events scope keys a feed payload declares via
     * `#[WatchScopes]` — the single source of
     * {@see SubscriptionRecord::$scopeKeys} for canonical feeds. The watch
     * list rides the API surface itself (the payload that IS the route), one
     * declaration for both the subscription and the contract projection.
     * Memoized per worker.
     *
     * @param class-string $payloadClass
     * @return list<string>
     */
    public static function watchScopesOf(string $payloadClass): array
    {
        return self::$watchScopeCache[$payloadClass] ??= (static function () use ($payloadClass): array {
            $attrs = (new \ReflectionClass($payloadClass))->getAttributes(WatchScopes::class);
            if ($attrs === []) {
                return [];
            }

            /** @var WatchScopes $declared */
            $declared = $attrs[0]->newInstance();

            return $declared->scopes;
        })();
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function jsonResponse(JsonResourceResponse $response, int $status, array $body): JsonResourceResponse
    {
        $response
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent(self::encode($body));

        return $response;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected static function encode(array $body): string
    {
        try {
            return json_encode(
                $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return '{"ok":false,"reason":"json_encode_failed"}';
        }
    }
}
