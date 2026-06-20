<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\WatchScopes;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\AuthenticationException;
use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\PayloadMetadataReflector;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Resource\JsonResourceResponse;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;
use Semitexa\Ssr\Domain\Contract\DynamicallyScopedFeedInterface;
use Semitexa\Ssr\Domain\Contract\SseFeedPayloadInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * The generic held-open-SSE serving choreography shared by every canonical
 * feed on the `{data, meta}` envelope — collection (list) and document
 * (single record) alike. Lives ONCE, in semitexa-ssr (the owner of the SSE
 * drain loop and Track-R; "serve a resource over SSE" is generic API
 * machinery, not UI).
 *
 * The choreography — server-minted stream id + `ui.stream.id` first frame, the
 * held-open serve handed to {@see AsyncResourceSseServer::serveResourceStream()},
 * the `X-Semitexa-Stream-Rehydrate` re-hydrate intake via
 * {@see AsyncResourceSseServer::submitViewChange()}, the SSE-vs-JSON content
 * negotiation, and the JSON degrade — is identical across feed shapes. The
 * feed's own route path/method come from the payload class's route attribute
 * (via {@see PayloadMetadataReflector}), and the held-open subscription's
 * watched scope keys come from the payload's `#[WatchScopes]` declaration.
 *
 * A concrete feed base supplies THREE seams:
 *   - {@see buildResponse()} — "bind a source to criteria and render the
 *     canonical envelope" (the only domain-specific logic);
 *   - {@see successEventType()} / {@see errorEventType()} — the typed SSE
 *     event names this feed's data/error frames ride (`ui.collection.*` for a
 *     collection, `ui.document.*` for a document), funnelled through the single
 *     {@see frame()} chokepoint so a client-controlled `_type` can never spoof
 *     a framework event name.
 *
 * ONE PIPELINE — the wire transport (SSE frame vs JSON body) is the ONLY fork:
 * same DTO ⇒ same envelope; mode does not fork the pipeline. In plain JSON
 * pull mode the concrete builder's response is returned UNTOUCHED (typed 400s
 * keep propagating to the ExceptionMapper); only when SSE is in play are
 * domain errors caught and framed as an error frame, because an exception on a
 * held-open stream has no response to ride.
 */
abstract class AbstractSseFeedHandler
{
    /**
     * One-URL re-hydrate intake header. A PURE intent flag: its presence
     * routes the POST to the view-change branch; the stream id + new view
     * params ride the typed payload, never this header. Read for routing
     * intent ONLY, exactly like `Accept` — NEVER as a filter.
     */
    public const REHYDRATE_HEADER = 'X-Semitexa-Stream-Rehydrate';

    /**
     * SSE transport unification · Phase 2 — multiplex subscribe/unsubscribe intake.
     * Pure intent flags (like {@see REHYDRATE_HEADER}): their presence routes the
     * POST to the subscribe/unsubscribe branch instead of a fresh held-open serve.
     * The transport coordinates — the caller's KISS connection id and the
     * client-minted subscription id — ride dedicated headers; the feed's own
     * params ride the QUERY exactly as on the GET connect, so the re-run's rebuilt
     * request hydrates identically. The Protected pipeline already authorized the
     * POST (admission); the per-tick re-run on the owning worker re-authorizes.
     */
    public const SUBSCRIBE_HEADER = 'X-Semitexa-Stream-Subscribe';
    public const UNSUBSCRIBE_HEADER = 'X-Semitexa-Stream-Unsubscribe';
    public const KISS_SESSION_HEADER = 'X-Semitexa-Kiss-Session';
    public const SUBSCRIPTION_ID_HEADER = 'X-Semitexa-Subscription-Id';

    #[InjectAsReadonly]
    protected RouteRegistry $routeRegistry;

    /**
     * Needed to resolve the re-run route WITH its handler binding.
     * {@see RouteRegistry::findRouteTyped()} only populates a route's
     * `handlers` when a HandlerRegistry is supplied; without it the re-run
     * pipeline runs NO handler and the held-open stream delivers an empty
     * frame. Sourced from AttributeDiscovery (its canonical holder).
     */
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

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
     * The unified intake. When the re-hydrate header is present this request
     * is a VIEW-CHANGE command on the open stream (enqueue + ACK only, never
     * rows); in plain JSON mode the concrete builder's response passes
     * through untouched; when SSE is preferred the SAME envelope is framed
     * onto the held-open stream (initial connect) or returned as the framed
     * re-run body (re-run tick). A concrete handler's
     * `handle(ConcretePayload, ConcreteJsonResponse)` delegates here.
     */
    protected function serve(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        // Multiplex intake (Phase 2) — attach/detach a subscription on the
        // caller's EXISTING KISS connection, never a fresh held-open serve here.
        // Checked before the re-hydrate/connect branches; on a re-run tick the
        // rebuilt request carries no intent header (stripped into the snapshot),
        // so this never re-fires during a re-run.
        if ($this->isUnsubscribeRequest($payload)) {
            return $this->acceptUnsubscribe($payload, $response);
        }
        if ($this->isSubscribeRequest($payload)) {
            return $this->acceptSubscribe($payload, $response);
        }

        if ($this->isReHydrateRequest($payload)) {
            return $this->acceptViewChange($payload, $response);
        }

        // A re-run tick (mutation-driven OR the multiplex subscribe's initial
        // frame) is INTRINSICALLY delivering an SSE frame onto an already-open
        // KISS connection, so it must produce the framed `_type` body even though
        // its rebuilt request carries no `Accept: text/event-stream` — the
        // multiplex attach rides a POST subscribe control, not a GET SSE connect,
        // so `prefersSse()` is structurally false here. Without this guard serve()
        // would short-circuit to the unframed pull body (no `_type`), which the
        // SSE chokepoint emits as a default `message` the client cannot demux to
        // its per-subscription callback (the data frame would silently never
        // reach the grid/collab runtime). Plain pulls (not a re-run, no SSE
        // Accept) still pass through untouched.
        if (!$this->prefersSse($payload) && !AsyncResourceSseServer::isReRunInProgress()) {
            // Plain pull — byte-identical: the builder's response (and its
            // typed 400 exceptions) pass through untouched.
            return $this->buildResponse($payload, $response);
        }

        [$envelope, $success] = $this->resolveEnvelope($payload, $response);

        // RE-RUN TICK: a `{__ctrl:rerun|viewchange}` re-ran this chain (the
        // live fd is already held + being streamed on) — produce the framed
        // BODY as JSON; the held-open loop writes it as the fresh frame.
        if (AsyncResourceSseServer::isReRunInProgress()) {
            return $this->jsonResponse($response, $success ? 200 : 400, $this->frame($envelope, $success));
        }

        // INITIAL CONNECT: hand the live socket to the held-open serve.
        $served = $this->serveHeldOpen($payload, $response, $envelope, $success);
        if ($served !== null) {
            return $served;
        }

        // SSE preferred but no live socket → JSON degrade: the same envelope
        // (canonical document or the mapped error body) as a classic JSON body.
        return $this->jsonResponse($response, $success ? 200 : 400, $envelope);
    }

    /**
     * Resolve the canonical envelope once, mode-agnostic, for the SSE paths.
     * A success is the builder's own rendered body decoded back to an array
     * (so the frame and the pull body stay the same document); a feed
     * deviation ({@see DomainException}: invalid sort / filter / pagination /
     * cursor / record key) becomes the ExceptionMapper-shaped error body — the
     * SAME `{error, message, context}` document a pull-mode 400 carries.
     *
     * Auth-shaped exceptions are NOT feed deviations and always propagate: on
     * initial connect they reach the ExceptionMapper (401/403, no held-open
     * stream is ever minted for a denied caller); on a re-run tick they reach
     * {@see \Semitexa\Core\Pipeline\RouteExecutor::reExecute()}, which
     * TERMINATEs the stream (close frame, full teardown) — the same
     * lost-access guarantee the route-level subject gate provides.
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
     * Is this the re-hydrate (view-change) intake? True iff the
     * `X-Semitexa-Stream-Rehydrate` intent header is present and non-empty.
     * Header read for routing intent ONLY (transport metadata, like `Accept`).
     */
    protected function isReHydrateRequest(SseFeedPayloadInterface $payload): bool
    {
        $request = $payload->getHttpRequest();
        if ($request === null) {
            return false;
        }
        $flag = $request->getHeader(self::REHYDRATE_HEADER);

        return is_string($flag) && trim($flag) !== '';
    }

    /**
     * The absorbed view-change command body: enqueue the view change onto the
     * held stream and ACK ONLY (never rows). Reuses
     * {@see AsyncResourceSseServer::submitViewChange()} VERBATIM — it
     * validates the stream-id shape, coalesces (latest-view-wins), and
     * enqueues a `{__ctrl:viewchange}` control onto that stream's
     * session-addressed queue; the owning worker re-runs the feed chain and
     * pushes the fresh envelope on the SAME open fd. The response carries NO
     * rows — only `{ok, accepted}`, 202 (queued) / 400 (invalid stream id).
     */
    protected function acceptViewChange(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        $request = $payload->getHttpRequest();
        $kissSession = trim((string) ($request?->getHeader(self::KISS_SESSION_HEADER) ?? ''));
        $subscriptionId = trim((string) ($request?->getHeader(self::SUBSCRIPTION_ID_HEADER) ?? ''));

        if ($kissSession !== '' || $subscriptionId !== '') {
            // Multiplexed view-change (SSE transport unification): deliver to the
            // caller's KISS session queue and TARGET the named subscription, so
            // the right one of N subscriptions on that one connection re-runs.
            // Both coordinates are required — a half-supplied pair is rejected.
            $accepted = $kissSession !== '' && $subscriptionId !== ''
                && AsyncResourceSseServer::submitViewChange(
                    $kissSession,
                    $payload->toViewParams(),
                    $subscriptionId,
                );
        } else {
            // Standalone own-route stream: streaming_id == session_id, addressed
            // by the adopted server stream id the payload carries.
            $accepted = AsyncResourceSseServer::submitViewChange(
                (string) $payload->getStreamId(),
                $payload->toViewParams(),
            );
        }

        return $this->jsonResponse(
            $response,
            $accepted ? 202 : 400,
            $accepted
                ? ['ok' => true, 'accepted' => true]
                : ['ok' => false, 'accepted' => false, 'reason' => 'invalid_session'],
        );
    }

    /** Multiplex subscribe intake? True iff the subscribe intent header is set. */
    protected function isSubscribeRequest(SseFeedPayloadInterface $payload): bool
    {
        return self::headerIsSet($payload, self::SUBSCRIBE_HEADER);
    }

    /** Multiplex unsubscribe intake? True iff the unsubscribe intent header is set. */
    protected function isUnsubscribeRequest(SseFeedPayloadInterface $payload): bool
    {
        return self::headerIsSet($payload, self::UNSUBSCRIBE_HEADER);
    }

    private static function headerIsSet(SseFeedPayloadInterface $payload, string $header): bool
    {
        $request = $payload->getHttpRequest();
        if ($request === null) {
            return false;
        }
        $flag = $request->getHeader($header);

        return is_string($flag) && trim($flag) !== '';
    }

    /**
     * Multiplex subscribe (Phase 2): attach THIS feed as a subscription on the
     * caller's already-open KISS connection. ACK ONLY — never rows; the initial
     * frame (and every re-run) rides the KISS fd, tagged with the subscription id.
     *
     * The Protected pipeline already authorized the POST (admission); the
     * frame-producing re-run on the owning worker re-authorizes auth-first via
     * {@see \Semitexa\Core\Pipeline\RouteExecutor::reExecute()} — a denied caller
     * gets a per-subscription denial frame and NO record. The transport
     * coordinates ride headers; the feed's params rode the query and are captured
     * in the snapshot, with the intent headers stripped + the method normalised to
     * the feed connect verb so the worker's rebuilt request is a clean connect.
     */
    protected function acceptSubscribe(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        $request = $payload->getHttpRequest();
        $kissSession = trim((string) ($request?->getHeader(self::KISS_SESSION_HEADER) ?? ''));
        $subscriptionId = trim((string) ($request?->getHeader(self::SUBSCRIPTION_ID_HEADER) ?? ''));

        $accepted = AsyncResourceSseServer::submitSubscribe(
            $kissSession,
            $subscriptionId,
            $this->feedRoutePath($payload),
            $this->feedRouteMethod($payload),
            $this->subscribeSnapshot($payload),
        );

        return $this->jsonResponse(
            $response,
            $accepted ? 202 : 400,
            $accepted
                ? ['ok' => true, 'accepted' => true, 'subscription_id' => $subscriptionId]
                : ['ok' => false, 'accepted' => false, 'reason' => 'invalid_subscribe'],
        );
    }

    /** Multiplex unsubscribe (Phase 2): detach one subscription; siblings survive. */
    protected function acceptUnsubscribe(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
    ): JsonResourceResponse {
        $request = $payload->getHttpRequest();
        $kissSession = trim((string) ($request?->getHeader(self::KISS_SESSION_HEADER) ?? ''));
        $subscriptionId = trim((string) ($request?->getHeader(self::SUBSCRIPTION_ID_HEADER) ?? ''));

        $accepted = AsyncResourceSseServer::submitUnsubscribe($kissSession, $subscriptionId);

        return $this->jsonResponse(
            $response,
            $accepted ? 202 : 400,
            ['ok' => $accepted, 'accepted' => $accepted],
        );
    }

    /**
     * The auth-bearing request snapshot the owning worker rebuilds + re-hydrates +
     * re-runs. The intent/transport headers are stripped and the method normalised
     * to the feed connect verb so the rebuilt request is a CLEAN connect — never
     * re-entering the subscribe branch on a re-run tick — while the auth cookies
     * and the feed's query params (which rode the query, like the GET connect) are
     * preserved so identity re-resolves and the DTO hydrates identically.
     *
     * @return array<string, mixed>
     */
    private function subscribeSnapshot(SseFeedPayloadInterface $payload): array
    {
        $request = $payload->getHttpRequest();
        if ($request === null) {
            return [];
        }

        $strip = array_map('strtolower', [
            self::SUBSCRIBE_HEADER,
            self::UNSUBSCRIBE_HEADER,
            self::REHYDRATE_HEADER,
            self::KISS_SESSION_HEADER,
            self::SUBSCRIPTION_ID_HEADER,
        ]);
        $headers = [];
        foreach ($request->headers as $key => $value) {
            if (!in_array(strtolower((string) $key), $strip, true)) {
                $headers[$key] = $value;
            }
        }

        return [
            'method'  => $this->feedRouteMethod($payload),
            'uri'     => $request->uri,
            'headers' => $headers,
            'query'   => $request->query,
            'post'    => $request->post,
            'server'  => $request->server,
            'cookies' => $request->cookies,
            'content' => $request->content,
            'files'   => $request->files,
        ];
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
     * Convert the one-shot serve into a HELD-OPEN stream serviced by the
     * framework drain loop. Grabs the raw Swoole socket (the same way
     * {@see SseKissHandler} does), then hands it to
     * {@see AsyncResourceSseServer::serveResourceStream()} with the
     * consumer-half inputs. Returns the already-sent response on success, or
     * null when no live socket is available so the caller degrades to JSON.
     *
     * The server-minted id is the SOLE stream coordinate: the framework mints
     * it at connect, announces it as the first `ui.stream.id` event for the
     * client to adopt, AND keys the held-open stream by it — announced ==
     * addressing key, unconditionally. A client-sent `?stream_id=` on the GET
     * connect is ignored. (The POST re-hydrate command still carries the
     * ADOPTED server id in its body — that IS this very id.)
     *
     * @param array<string, mixed> $envelope
     */
    private function serveHeldOpen(
        SseFeedPayloadInterface $payload,
        JsonResourceResponse $response,
        array $envelope,
        bool $success,
    ): ?JsonResourceResponse {
        if (!class_exists(SwooleBootstrap::class)) {
            return null;
        }
        $context = SwooleBootstrap::getCurrentSwooleRequestResponse();
        if ($context === null || ($context[1] ?? null) === null || ($context[2] ?? null) === null) {
            return null;
        }
        [$swooleRequest, $swooleResponse, $server] = $context;

        $serverStreamId = AsyncResourceSseServer::mintStreamId();
        $sessionId = $serverStreamId;
        $reRunContext = $this->buildReRunContext($payload, $sessionId);
        // The record + context share streaming_id (= sessionId); if the route
        // can't be resolved for the re-run chain we still hold the stream
        // open, just without a live re-run source (passed as null/null).
        $record = $reRunContext === null ? null : $this->buildSubscriptionRecord($payload, $sessionId);

        AsyncResourceSseServer::setServer($server);
        AsyncResourceSseServer::serveResourceStream(
            $swooleRequest,
            $swooleResponse,
            $sessionId,
            $this->frame($envelope, $success),
            $record,
            $reRunContext,
            $serverStreamId,
        );

        $response->setContent('');
        $response->markAsAlreadySent();

        return $response;
    }

    /**
     * Build the worker-local re-run state ({@see ReRunContext}) the
     * coordinator stores under streaming_id, re-run auth-first on each
     * mutation. The cached DTO supplies the unchanged request SHAPE only —
     * identity is re-resolved each tick. Returns null when this feed's route
     * cannot be resolved (then the stream holds open without a live re-run
     * source).
     */
    private function buildReRunContext(SseFeedPayloadInterface $payload, string $sessionId): ?ReRunContext
    {
        $route = $this->routeRegistry->findRouteTyped(
            $this->feedRoutePath($payload),
            $this->feedRouteMethod($payload),
            // WITHOUT the handler registry the route carries no handlers and
            // the re-run pipeline invokes nothing → an empty frame. Pass it so
            // the re-run re-runs THIS handler.
            $this->attributeDiscovery->getHandlerRegistry(),
        );
        if ($route === null) {
            return null;
        }

        $request = $payload->getHttpRequest();
        $snapshot = $request === null ? [] : [
            'method'  => $request->method,
            'uri'     => $request->uri,
            'headers' => $request->headers,
            'query'   => $request->query,
            'post'    => $request->post,
            'server'  => $request->server,
            'cookies' => $request->cookies,
            'content' => $request->content,
            'files'   => $request->files,
        ];

        // tenantContext is INTENTIONALLY null for the held-open feed re-run.
        // R4 re-invokes this handler IN THE SAME coroutine that already holds
        // the open fd — so the request's tenant context is ALREADY established
        // (and immutable for the life of the HTTP request). Passing a captured
        // context here makes RouteReRunner's TenantContextStore::set() throw
        // `TenantContextImmutableException`. The SubscriptionRecord still
        // carries the tenant id/blob (read-only) for cross-worker channel
        // scoping.
        return new ReRunContext(
            cachedDto: $payload,
            route: $route,
            requestSnapshot: $snapshot,
            sessionId: $sessionId,
            subjectRef: self::currentSubjectRef(),
            tenantContext: null,
        );
    }

    /**
     * Build the cross-worker subscription row ({@see SubscriptionRecord}) —
     * the identity-free, routable record the reverse-index scan filters on.
     * streamingId == sessionId (one stream per connection); scopeKeys is the
     * payload's declared `#[WatchScopes]` watch list; tenantId/tenantBlob
     * mirror the publisher so the channel names agree.
     *
     * An empty `scopeKeys` (no `#[WatchScopes]` on the payload) is a valid
     * record: R1 indexes no invalidation channel for it, so it never
     * live-re-runs (a static feed), yet the record + its worker-local
     * {@see ReRunContext} are still registered so the held-open stream's
     * view-change re-hydrate keeps working (that rides the context, not
     * scopeKeys).
     */
    private function buildSubscriptionRecord(SseFeedPayloadInterface $payload, string $sessionId): SubscriptionRecord
    {
        return new SubscriptionRecord(
            streamingId: $sessionId,
            sessionId: $sessionId,
            tenantId: self::currentTenantId(),
            scopeKeys: self::resolveScopeKeys($payload),
            tenantBlob: self::currentTenantBlob(),
        );
    }

    /**
     * The held-open subscription's watched scope keys: the payload's static
     * `#[WatchScopes]` declaration UNIONed with any request-time scopes a
     * payload contributes via {@see DynamicallyScopedFeedInterface} (a
     * collaborative document's per-record `formdoc:{formKey}:{recordId}` key,
     * which cannot ride a class-level attribute). Blank/duplicate entries are
     * dropped; order is static-first, dynamic-appended, stable.
     *
     * @return list<string>
     */
    private static function resolveScopeKeys(SseFeedPayloadInterface $payload): array
    {
        $scopes = self::watchScopesOf($payload::class);

        if ($payload instanceof DynamicallyScopedFeedInterface) {
            foreach ($payload->dynamicWatchScopes() as $scope) {
                if (is_string($scope) && $scope !== '' && !in_array($scope, $scopes, true)) {
                    $scopes[] = $scope;
                }
            }
        }

        return array_values($scopes);
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
     * The feed's own route path, derived from the payload class's route
     * attribute (the payload already declares the route once).
     */
    private function feedRoutePath(SseFeedPayloadInterface $payload): string
    {
        $document = PayloadMetadataReflector::describe($payload::class);

        return is_string($document['endpoint'] ?? null) ? $document['endpoint'] : '/';
    }

    /** The held-open connect verb — GET when the route declares it, else the first declared method. */
    private function feedRouteMethod(SseFeedPayloadInterface $payload): string
    {
        $document = PayloadMetadataReflector::describe($payload::class);
        $methods = is_array($document['methods'] ?? null) ? $document['methods'] : [];

        return in_array('GET', $methods, true) ? 'GET' : (string) ($methods[0] ?? 'GET');
    }

    /**
     * The frozen subject reference (the immutable-block anchor a re-auth
     * compares the live session's subject against). Best-effort: '' when no
     * subject is resolvable. Read defensively so a missing/renamed auth
     * surface degrades rather than fatals.
     */
    private static function currentSubjectRef(): string
    {
        $store = '\Semitexa\Auth\Context\AuthContextStore';
        if (class_exists($store) && method_exists($store, 'getUser')) {
            /** @var object|null $user */
            $user = $store::getUser();
            if (is_object($user) && method_exists($user, 'getId')) {
                return (string) $user->getId();
            }
        }

        return '';
    }

    private static function currentTenantId(): string
    {
        $tenant = self::resolveTenant();
        if (is_object($tenant) && method_exists($tenant, 'getTenantId')) {
            $id = trim((string) $tenant->getTenantId());
            if ($id !== '') {
                return $id;
            }
        }

        return 'default';
    }

    private static function currentTenantBlob(): string
    {
        $tenant = self::resolveTenant();
        $blob = null;
        if (is_object($tenant) && method_exists($tenant, 'forSerialization')) {
            $blob = $tenant->forSerialization();
        }

        return self::encode(is_array($blob) ? $blob : []);
    }

    /** Resolve the live tenant context defensively (null in non-tenancy paths). */
    private static function resolveTenant(): ?object
    {
        $ctx = '\Semitexa\Tenancy\Context\TenantContext';
        if (class_exists($ctx) && method_exists($ctx, 'get')) {
            $tenant = $ctx::get();

            return is_object($tenant) ? $tenant : null;
        }

        return null;
    }

    /** Content-negotiation: does the caller prefer an event stream? */
    protected function prefersSse(SseFeedPayloadInterface $payload): bool
    {
        $request = $payload->getHttpRequest();
        if ($request === null) {
            return false;
        }
        $accept = $request->getHeader('accept');
        if (!is_string($accept) || $accept === '') {
            return false;
        }

        return str_contains(strtolower($accept), 'text/event-stream');
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
