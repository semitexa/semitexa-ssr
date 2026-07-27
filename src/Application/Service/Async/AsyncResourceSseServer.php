<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\HttpResponse;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Pipeline\ReRun\ReRunnerInterface;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Server\SseFrame;
use Semitexa\Core\Server\SseTransportInterface;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Domain\Contract\SubscriptionFactoryInterface;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;
use Predis\Client;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class AsyncResourceSseServer
{
    /**
     * Strict shape for an anonymous bearer-channel subscriber id.
     *
     * 128 bits of entropy (16 random bytes hex-encoded) with the `sse_` prefix.
     * Platform-UI mints ids of this shape; the KISS endpoint admits anonymous
     * bare GET requests only when the supplied session_id matches.
     *
     * Owned by {@see SseRequestGuard} since `ep-slay-sse-god-class`; aliased here
     * because callers outside this package read it from the facade.
     */
    public const SAFE_BEARER_SESSION_ID_PATTERN = SseRequestGuard::SAFE_BEARER_SESSION_ID_PATTERN;

    /**
     * Stream Lifecycle · Axis 1(b) — the single server-side source of new stream
     * ids. Mints a server-authoritative id of the SAME safe shape every store
     * already keys on (`sse_<32hex>`, 128 bits of CSPRNG entropy), so it satisfies
     * {@see self::SAFE_BEARER_SESSION_ID_PATTERN}, the {@see SubscriptionTable}
     * key discipline, and the anti-injection table-key validator with no other
     * change. The server owns id generation: the held-open resource stream mints
     * here at connect ({@see serveResourceStream()}) and announces it as the first
     * `ui.stream.id` SSE event so a Phase-3 client can adopt it. Lives in `ssr`
     * (not `core`) because the id is meaningful only to the SSE serving path that
     * keys, delivers, and reaps by it.
     */
    public static function mintStreamId(): string
    {
        return 'sse_' . bin2hex(random_bytes(16));
    }

    /**
     * Short-lived KISS transport: flush queued frames + close. Default for
     * public/guest pages that opt into the canonical subscriber channel —
     * bounds worker coroutine / FD pressure by not holding the connection
     * open after the queue is drained.
     */
    public const TRANSPORT_MODE_DRAIN = SseTransportModePolicy::MODE_DRAIN;

    /**
     * Long-lived KISS transport: enter the existing while-loop and stay
     * open until max-age / done / disconnect. Reserved for authenticated
     * dashboards, admin/internal tools, monitoring, terminal-like
     * interfaces, and other explicitly trusted deployments.
     */
    public const TRANSPORT_MODE_LIVE = SseTransportModePolicy::MODE_LIVE;

    /**
     * No explicit mode supplied. Behaviour:
     *   - deferred_request_id present, authenticated session, or
     *     SSE_PUBLIC_ANONYMOUS=1 → preserve the existing long-lived loop
     *     (legacy callers, deferred SSR streams);
     *   - safe anonymous bearer (`sse_<32hex>`) only → upgrade to drain so
     *     a guest page that forgot the mode marker does NOT silently open
     *     a long-lived stream.
     */


    /** @see SseTransportModePolicy::HEARTBEAT_INTERVAL_SECONDS */
    private const HEARTBEAT_INTERVAL_SECONDS = SseTransportModePolicy::HEARTBEAT_INTERVAL_SECONDS;


    /**
     * Track R · R8a — the set of request paths served by the SSE intercept,
     * keyed for O(1) membership (`path => true`).
     *
     * Populated per worker by {@see WireSseServedPathsListener} from every
     * discovered route whose `transport` is {@see TransportType::Sse} — so the
     * serve dispatch in {@see handle()} keys on the route's declared transport,
     * not on a hardcoded path. `/__semitexa_kiss` is itself a `transport: Sse`
     * route ({@see \Semitexa\Ssr\Application\Payload\Request\SseKissPayload}), so
     * it lands in this set and continues to be served by the same generalized
     * path — no kiss-specific branch survives.
     *
     * @var array<string, true>
     */

    /**
     * Swoole-free SSE write port (core contract). The Swoole adapter binds
     * lazily as a soft runtime dependency, mirroring how the rest of the
     * Swoole runtime adapters are wired. Held here so the byte-writing path
     * goes through the {@see SseTransportInterface} contract rather than
     * touching `Swoole\Http\Response::write()` directly.
     */

    /**
     * Track R · R4 — the loop branch's worker-static collaborators.
     *
     * The re-run unit is core (R2's {@see ReRunnerInterface}); the loop body
     * stays here in ssr, bridged to it by this worker-static reference (design
     * §B.3 "loop body stays in ssr, re-run unit is core, bridged by a
     * worker-static closure"). The coalescer (R3) is the cross-worker
     * idempotency table whose pending mark R4 CLEARS after handling a control,
     * re-arming the next mutation's signal.
     *
     * Both are null until the live binding is wired (R8 / the dispatcher-wiring
     * brick). While null a `{__ctrl:rerun}` is a SAFE no-op — dropped without a
     * re-run and without ever reaching the socket — so R4 is inert until lit up,
     * keeping {@see handleControlFrame()} plain-constructable (no DI binding,
     * mirroring R1/R3/R5).
     */

    /**
     * Track R · Intended Grid Model · Phase 2 (C2) — the view-change coalescer.
     *
     * The cross-worker "latest view wins, collapse pending" table for a
     * `{__ctrl:viewchange}` command (distinct from {@see $rerunCoalescer} so a
     * mutation re-run and a view-change re-run never suppress each other). Wired
     * alongside the rerun coalescer; null until then — while null a view-change is
     * carried inline in the control payload as a best-effort, uncoalesced fallback.
     */

    /**
     * Track R · R8c (C2) — the per-worker connect coordinator (R5) that the
     * held-open resource stream ({@see serveResourceStream()}) drives on
     * connect/disconnect to populate / reap the three-tier subscription store and
     * subscribe-on-first / unsubscribe-on-last. Wired live by
     * {@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\WireTrackRConsumerListener}.
     * Null until wired (and on the kiss path, which has no resource subscription) —
     * a null coordinator makes the consumer-half a safe no-op.
     */

    /**
     * SSE transport unification · Phase 1 — builds a multiplexed subscription
     * (record + re-run context) on this worker from a subscribe control, so one
     * KISS connection can host many subscriptions. Wired by
     * {@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\WireTrackRConsumerListener};
     * until then a subscribe control is a safe no-op.
     */

    /**
     * `ep-slay-sse-god-class` — the extracted admission-control collaborator.
     * Unlike the wired slots above this one is self-creating ({@see requestGuard()}),
     * so it is never null at use and needs no lifecycle listener.
     */
    private static ?SseRequestGuard $requestGuard = null;

    /** `ep-slay-sse-god-class` — the extracted stream-lifetime policy. Self-creating, stateless. */
    private static ?SseTransportModePolicy $transportModePolicy = null;

    /** `ep-slay-sse-god-class` — the extracted wire-shape factory. Self-creating, stateless. */
    private static ?SseFrameFactory $frameFactory = null;

    /**
     * `ep-slay-sse-god-class` — the extracted connection-cap accounting. Unlike
     * its stateless siblings this one HOLDS the per-worker counters, so the
     * single lazily-created instance is what keeps the caps meaningful.
     */
    private static ?SseConnectionLimiter $connectionLimiter = null;

    /** `ep-slay-sse-god-class` — the extracted Redis pool + session-queue store. */
    private static ?SseRedisPool $redisPoolResolver = null;
    private static ?SseRedisSessionQueue $redisSessionQueue = null;

    /** `ep-slay-sse-god-class` — the extracted user<->session index. */
    private static ?SseAuthSessionMap $authSessionMap = null;

    /**
     * `ep-slay-sse-god-class` — the extracted coroutine-local re-run depth.
     * {@see SseReRunScope} documents why the locality is load-bearing.
     */
    private static ?SseReRunScope $reRunScope = null;

    /** `ep-slay-sse-god-class` — the extracted per-session coroutine tracker. */
    private static ?SseSessionCoroutines $sessionCoroutines = null;

    /** `ep-slay-sse-god-class` — the extracted per-worker session/queue/buffer state. */
    private static ?SseSessionRegistry $sessionRegistry = null;

    /** `ep-slay-sse-god-class` — the extracted shared-memory cross-worker transport. */
    private static ?SseWorkerTables $workerTables = null;

    /** `ep-slay-sse-god-class` — the eight worker-boot collaborators, gathered. */
    private static ?SseRuntime $runtime = null;

    /** `ep-slay-sse-god-class` — the extracted control plane. */
    private static ?SseControlRouter $controlRouter = null;

    /**
     * {@see handleControlFrame()} outcomes. A control marker is a SIGNAL, never
     * bytes for the wire (§C.4): NOT_CONTROL → the caller writes the ordinary
     * data frame as before; HANDLED_CONTINUE → the control was consumed (re-run
     * frame written, or a safe no-op), the drain continues; HANDLED_CLOSE → the
     * re-run TERMINATEd (lost access) or the fresh-frame write failed, the stream
     * must close.
     */
    private const CTRL_NOT_CONTROL = SseControlFrame::NOT_CONTROL;
    private const CTRL_HANDLED_CONTINUE = SseControlFrame::HANDLED_CONTINUE;
    private const CTRL_HANDLED_CLOSE = SseControlFrame::HANDLED_CLOSE;

    /** The control kind key + the recognised control kinds on a session queue. */
    private const CTRL_KEY = SseControlFrame::KEY;
    private const CTRL_RERUN = SseControlFrame::RERUN;
    private const CTRL_VIEWCHANGE = SseControlFrame::VIEWCHANGE;
    // SSE transport unification · Phase 1 — attach/detach a feed subscription to
    // an already-open KISS connection (the multiplex case).
    private const CTRL_SUBSCRIBE = SseControlFrame::SUBSCRIBE;
    private const CTRL_UNSUBSCRIBE = SseControlFrame::UNSUBSCRIBE;

    public static function handle(Request $request, Response $response): bool
    {
        $server = is_array($request->server) ? $request->server : [];
        $path = $server['path_info'] ?? '';
        if ($path === '') {
            $uri = $server['request_uri'] ?? '/';
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        }

        // Track R · R8a — serve any route that DECLARES transport: Sse, not a
        // hardcoded path. The served-path set is built from the discovered
        // routes' transport (see {@see $sseServedPaths} / WireSseServedPathsListener),
        // so /__semitexa_kiss continues to be served via the same generalized
        // dispatch (it declares transport: Sse) while an own-route SSE endpoint
        // declaring transport: Sse is served on equal footing — no path branch.
        // (Two historical reserved-path intercepts were removed here — both were
        // dead/redundant branches retired once all SSE unified on kiss: an
        // unreachable orphaned-client route, and a byte-identical duplicate alias.)
        if (self::shouldServeAsSse($path)) {
            self::handleSse($request, $response);
            return true;
        }

        return false;
    }

    /**
     * Track R · R8a — does the resolved route for $path declare transport: Sse?
     *
     * Membership in {@see $sseServedPaths} is equivalent to `transport === Sse`:
     * the set is populated exclusively from routes whose declared transport is
     * {@see TransportType::Sse}. A non-Sse route's path is absent, so it is NOT
     * served as a stream (the dispatch is correct, not over-broad).
     */
    /**
     * Turn an accepted request into a live stream: 200 + SSE headers, the
     * worker-local session record, the authenticated-session index entry, and
     * the cross-worker ownership claim.
     *
     * Order matters. The session record and its empty queue must exist before
     * any frame is flushed, or a concurrent deliver() on this worker would judge
     * the session unknown and route it cross-worker to a socket that is right
     * here.
     *
     * @return string the authenticated user id, or '' for a guest stream.
     */
    private static function openSseStream(Request $request, Response $response, string $sessionId): string
    {
        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        // Capture the tenant THIS connection resolved, in its own coroutine where
        // TenantContext is authoritative (TenancyPhase ran before route dispatch),
        // so a later multiplex subscribe scopes its record from the connecting
        // tenant rather than the draining coroutine's ambient one.
        self::sessionRegistry()->open($sessionId, $response, self::currentTenantId(), self::currentTenantBlob());

        $authenticatedUserId = self::resolveAuthenticatedUserId($request);
        if ($authenticatedUserId !== '') {
            self::registerAuthenticatedSession($sessionId, $authenticatedUserId);
        }
        self::touchActiveSession($sessionId);

        self::workerTables()->recordOwnership($sessionId);

        self::sessionRegistry()->ensureQueue($sessionId);

        return $authenticatedUserId;
    }

    /**
     * Flush everything that arrived for this session before it had a socket on
     * this worker: first the worker-local buffer, then the cross-worker pending
     * table. That order is arrival order, which is why both stores exist rather
     * than one.
     */
    private static function flushBacklog(string $sessionId, Response $response): void
    {
        foreach (self::sessionRegistry()->takeBuffered($sessionId) as $data) {
            self::writeSse($response, $data);
        }

        // Flush pending table for this session only
        foreach (self::workerTables()->takePendingFor($sessionId) as $payload) {
            $data = json_decode($payload, true);
            if (is_array($data)) {
                self::writeSse($response, $data);
            }
        }
    }

    /**
     * Drain-mode ending: flush anything that landed between admit and here, then
     * emit the canonical close frame so the client's `close` listener fires
     * deterministically instead of the stream merely going quiet.
     */
    private static function completeDrain(string $sessionId, Response $response): void
    {
        foreach (self::sessionRegistry()->takeQueued($sessionId) as $data) {
            self::writeSse($response, $data);
        }
        self::writeSse($response, [
            'event'  => 'close',
            'type'   => 'done',
            'close'  => true,
            'live'   => false,
            'reason' => 'drain_complete',
        ]);
    }

    /**
     * Serve a deferred-SSR request, gated on its bind token.
     *
     * The token binds the stream to the page load that asked for it. A mismatch
     * closes with `reconnect: false`, because retrying cannot help — the request
     * id belongs to somebody else's page.
     *
     * @return bool `false` when the caller must close the stream.
     */
    private static function openDeferredDoor(
        Request $request,
        Response $response,
        string $sessionId,
        string $deferredRequestId,
        mixed $lastEventId,
        string $resolvedMode,
    ): bool {
        $bindToken = self::getSsrBindToken($request);
        if (!\Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry::matchesBindToken($deferredRequestId, $bindToken)) {
            self::writeSse($response, [
                'type' => 'done',
                'live' => false,
                'close' => true,
                'reconnect' => false,
            ]);

            return false;
        }
        self::triggerDeferredBlocks(
            $sessionId,
            $deferredRequestId,
            $lastEventId,
            self::canUsePersistentDeferredSse($request),
            $resolvedMode === self::TRANSPORT_MODE_LIVE,
        );

        return true;
    }

    private static function shouldServeAsSse(string $path): bool
    {
        return self::runtime()->servesPath($path);
    }

    private static function handleSse(Request $request, Response $response): void
    {
        $get = is_array($request->get) ? $request->get : [];
        $sessionId = trim((string) (($get['session_id'] ?? null) ?: uniqid('sse_', true)));
        $demoStream = '';
        if (isset($get['demo_stream'])) {
            $demoStream = trim((string) $get['demo_stream']);
        }
        $deferredRequestId = trim((string) ($get['deferred_request_id'] ?? ''));
        $rawMode = trim((string) ($get['mode'] ?? ''));

        // Auth gate — only persistent streams require a session:
        //  1. demo_stream runs an infinite per-minute producer → auth always.
        //  2. deferred_request_id requests are guest-safe: the orchestrator runs
        //     delivery then sends done/close (canUsePersistentDeferredSse() keeps
        //     the persistent live loop auth-gated), so we let guests through the
        //     gate and rely on the delivery-complete close.
        //  3. a bare kiss stream with no deferred_request_id is long-lived →
        //     auth required, unless SSE_PUBLIC_ANONYMOUS is opt-in.
        $authenticated = self::hasAuthenticatedSession($request);
        $anonymousAllowed = filter_var((string) (\getenv('SSE_PUBLIC_ANONYMOUS') ?: ''), FILTER_VALIDATE_BOOLEAN);
        $safeBearerSessionId = self::isSafeBearerSessionId($get['session_id'] ?? null);

        $authError = self::resolveSseAuthorizationError(
            authenticated: $authenticated,
            anonymousAllowed: $anonymousAllowed,
            demoStream: $demoStream,
            deferredRequestId: $deferredRequestId,
            safeBearerSessionId: $safeBearerSessionId,
            // An explicit mode=live request is persistent — it must not borrow
            // the deferred door's guest-permissive bypass (see method docblock).
            persistentRequested: $rawMode === self::TRANSPORT_MODE_LIVE,
        );
        $rejection = self::resolveSseRequestRejection(
            sameOrigin: self::isSameOriginRequest($request),
            authError: $authError,
        );
        if ($rejection !== null) {
            if ($rejection['status'] === 401) {
                self::rejectUnauthorized($response, $rejection['message']);
                return;
            }

            $response->status($rejection['status']);
            $response->end();
            return;
        }

        // Resolve transport mode early — an explicit unknown `mode=` value
        // gets a clean 400 before any IP cap accounting. Missing mode is
        // legal: the resolver maps it to drain for anonymous bearer
        // channels and to legacy for everything else.
        $resolvedMode = self::resolveTransportMode(
            rawMode: $rawMode,
            authenticated: $authenticated,
            anonymousAllowed: $anonymousAllowed,
            safeBearerSessionId: $safeBearerSessionId,
            deferredRequestId: $deferredRequestId,
        );
        if ($resolvedMode === null) {
            self::rejectBadRequest($response, 'Unknown SSE transport mode.');
            return;
        }

        // Per-IP + global connection caps. Apply to every connection (authenticated
        // or anonymous) to bound worker/FD consumption.
        $denial = self::connectionLimiter()->tryAcquire(
            self::resolveClientIp($request),
            $sessionId,
            $response,
        );
        if ($denial !== null) {
            self::rejectTooManyRequests($response, $denial);
            return;
        }

        // Exactly-once teardown for EVERY exit path. The connection the limiter
        // just accounted, and the session/queue/worker-table registration below,
        // must be released even when a later step throws — a throw from writeSse,
        // triggerDeferredBlocks or runHeldOpenLoop otherwise leaves the
        // {@see SseConnectionLimiter} slot held for the worker's whole life, and
        // after enough such throws the worker rejects ALL new SSE with 429
        // despite no live connections. Coroutine::defer
        // runs the cleanup when the request coroutine ends (return OR uncaught
        // throw); the explicit $close() calls below cover the SSE-impossible
        // non-coroutine path and release immediately on normal exits. The
        // $closed flag keeps it single-shot either way.
        $closed = false;
        $close = static function () use (&$closed, $sessionId, $response): void {
            if ($closed) {
                return;
            }
            $closed = true;
            self::closeSession($sessionId, $response);
        };
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::defer($close);
        }

        $authenticatedUserId = self::openSseStream($request, $response, $sessionId);

        // Flush local buffer for this session only
        self::flushBacklog($sessionId, $response);

        if (self::drainRedisQueueForSession($sessionId, $response)) {
            $close();
            return;
        }

        // Send initial event so the client receives something immediately (fixes "Connecting..." stuck
        // and ensures response is flushed; some proxies don't send headers until first byte).
        self::writeSse($response, [
            'event' => 'connected',
            'connected' => true,
            'mode' => $resolvedMode,
        ]);

        // Drain mode short-circuit. deferred_request_id wins when both are
        // set — its own streamDeferredBlocks() pipeline owns the done/close
        // semantics. Buffer, pending-table, and Redis queue were already
        // drained above; flush any same-worker queue items that landed
        // between admit and here, then emit the canonical close frame so
        // the client's `close` listener fires deterministically.
        if ($resolvedMode === self::TRANSPORT_MODE_DRAIN && $deferredRequestId === '') {
            self::completeDrain($sessionId, $response);
            $close();
            return;
        }

        $enableDemoStream = filter_var((string) (\getenv('APP_DEBUG') ?: ''), FILTER_VALIDATE_BOOLEAN);
        if ($demoStream !== '' && $enableDemoStream) {
            self::startDemoStreamProducer($sessionId, $demoStream);
        }

        // Trigger deferred block streaming if deferred_request_id is present
        $header = is_array($request->header) ? $request->header : [];
        $lastEventId = $header['last-event-id'] ?? null;
        if ($deferredRequestId !== '' && !self::openDeferredDoor($request, $response, $sessionId, $deferredRequestId, $lastEventId, $resolvedMode)) {
            $close();
            return;
        }

        self::runHeldOpenLoop($sessionId, $request, $response, $authenticatedUserId);

        $close();
    }

    /**
     * The held-open servicing loop — the single drain loop that keeps an SSE fd
     * open and delivers subsequent frames (queue → cross-worker deliver-table →
     * Redis queue), catches the R4 `{__ctrl:rerun}` control on each path, applies
     * the connection-age cap, and emits the idle keepalive.
     *
     * Extracted from {@see handleSse()} so a non-kiss own-route stream
     * ({@see serveResourceStream()}, the Track R · R8c held-open grid) is serviced
     * by the EXACT same loop — including R4's re-run branch — rather than a parallel
     * copy. Kiss is byte-unchanged: {@see handleSse()} still computes the same
     * pre-loop state and calls this with it. The loop does NOT close the session;
     * the caller owns {@see closeSession()} (so a caller can run teardown hooks
     * around it).
     */
    private static function runHeldOpenLoop(
        string $sessionId,
        Request $request,
        Response $response,
        string $authenticatedUserId,
    ): void {
        $closed = false;
        $lastAuthTouchAt = time();
        $connectionStartedAt = time();
        // Seed the heartbeat clock from the `connected` frame written just
        // above; refreshed on every successful outbound write below.
        $lastWriteAt = time();
        $maxAgeSeconds = self::maxConnectionAgeSeconds();
        while (!$closed && self::sessionRegistry()->isOpen($sessionId)) {
            // Hard connection-age cap — bounds hanging-connection attacks.
            if ($maxAgeSeconds > 0 && (time() - $connectionStartedAt) >= $maxAgeSeconds) {
                self::writeSse($response, ['event' => 'close', 'reason' => 'max_age', 'close' => true]);
                break;
            }

            if ((time() - $lastAuthTouchAt) >= SseAuthSessionMap::TOUCH_INTERVAL_SECONDS) {
                $authenticatedUserId = self::refreshAuthenticatedSessionMapping($request, $sessionId, $authenticatedUserId);
                self::touchActiveSession($sessionId);
                $lastAuthTouchAt = time();
            }

            while (($data = self::sessionRegistry()->shiftQueued($sessionId)) !== null) {

                // Track R · R4 — catch a control marker before it can be written
                // as a data frame (same-worker path: X==W, the control landed on
                // this worker's in-memory queue).
                $ctrl = self::handleControlFrame($sessionId, $response, $data);
                if ($ctrl === self::CTRL_HANDLED_CLOSE) {
                    $closed = true;
                    break;
                }
                if ($ctrl === self::CTRL_HANDLED_CONTINUE) {
                    $lastWriteAt = time();
                    continue;
                }

                if (!self::writeSse($response, $data)) {
                    // Durability: the socket died mid-send. Requeue this
                    // in-flight payload (already shifted off the queue) to
                    // Redis so the reconnecting subscriber drains it; any
                    // remaining queue items are flushed by closeSession.
                    self::requeueToRedis($sessionId, [$data]);
                    $closed = true;
                    break;
                }
                $lastWriteAt = time();
                if (self::shouldCloseAfterPayload($data)) {
                    $closed = true;
                    break;
                }
            }

            if (!$closed && self::workerTables()->canRouteCrossWorker()) {
                $tables = self::workerTables();
                $toDel = [];
                $deliverCount = 0;
                foreach ($tables->readDeliveriesFor($tables->currentWorkerId(), $sessionId) as $row) {
                    $data = json_decode($row['payload'], true);
                    if (!is_array($data)) {
                        continue;
                    }

                    // Track R · R4 — catch a control marker on the cross-worker
                    // Swoole-table fallback (no-Redis path). The owning worker W
                    // self-selects via the worker_id match in the read above, so
                    // the tier-2 context resolves locally here.
                    $ctrl = self::handleControlFrame($sessionId, $response, $data);
                    if ($ctrl === self::CTRL_HANDLED_CLOSE) {
                        $toDel[] = $row['key'];
                        $closed = true;
                        break;
                    }
                    if ($ctrl === self::CTRL_HANDLED_CONTINUE) {
                        $toDel[] = $row['key'];
                        $deliverCount++;
                        $lastWriteAt = time();
                        continue;
                    }

                    if (self::writeSse($response, $data)) {
                        $toDel[] = $row['key'];
                        $deliverCount++;
                        $lastWriteAt = time();
                        if (self::shouldCloseAfterPayload($data)) {
                            $closed = true;
                            break;
                        }
                    }
                }
                // Only rows whose frame actually got out are consumed; a failed
                // write leaves its row for the next tick.
                foreach ($toDel as $k) {
                    $tables->deleteDelivery($k);
                }
            }

            if (!$closed && self::drainRedisQueueForSession($sessionId, $response)) {
                $closed = true;
            }

            if ($closed) {
                break;
            }

            if (function_exists('connection_aborted') && connection_aborted()) {
                break;
            }

            // Keepalive: emit an inert comment after an idle gap so the
            // connection survives proxy idle timeouts and a dead socket is
            // detected here rather than only on the next data frame.
            if (self::shouldSendHeartbeat(time(), $lastWriteAt, self::HEARTBEAT_INTERVAL_SECONDS)) {
                if (!self::writeSseComment($response)) {
                    break;
                }
                $lastWriteAt = time();
            }

            \Swoole\Coroutine::sleep(0.2);
        }
    }

    /**
     * Track R · R8c (C1/C2) — serve a Protected own-route resource stream as a
     * HELD-OPEN SSE stream serviced by the same drain loop kiss uses.
     *
     * This is the seam R8a/R8b left inert: the grid endpoint declares
     * `transport: Sse` (so its path is in {@see $sseServedPaths}) but R8b served it
     * as a ONE-SHOT frame. Here the OWN handler (it has already flowed the normal
     * Protected pipeline — hydration + the Subject gate — so authorization is DONE
     * upstream and this method does NO auth of its own) hands the live socket to
     * this method, which:
     *
     *   1. applies the per-IP / global connection caps (same bound as kiss);
     *   2. registers the session + worker-table row (so cross-worker
     *      {@see deliver()} of a `{__ctrl:rerun}` control reaches THIS fd);
     *   3. writes the INITIAL frame ({@see writeSse()} → the typed-`_type`
     *      chokepoint, so it is byte-identical to a re-run frame);
     *   4. launches the consumer-half: R5 {@see ConnectCoordinator::onConnect()}
     *      populates tier-1 (cross-worker row) + tier-2 (worker-local
     *      {@see ReRunContext}) and drives R3's subscribe-on-first;
     *   5. enters {@see runHeldOpenLoop()} — the SAME loop as kiss, where R4's
     *      {@see handleControlFrame()} catches a `{__ctrl:rerun}` and writes a
     *      fresh re-queried frame on THIS held-open fd (live update, not reconnect);
     *   6. on teardown (disconnect / age cap / socket death), R5
     *      {@see ConnectCoordinator::onDisconnect()} reaps every tier (no zombie),
     *      then {@see closeSession()} ends the fd.
     *
     * `$record` + `$context` are the consumer-half inputs the owning handler builds
     * (they share `streaming_id`, the linkage R4 follows from a cross-worker control
     * back to the worker-local re-run state). When either is null, or no coordinator
     * is wired, the stream still holds open and is serviced — it just has no live
     * re-run source (the safe degenerate used by the held-open transport test).
     *
     * @param array<array-key, mixed> $initialFrameData the first frame's payload
     *                                                   (already carries its `_type`).
     * @param string $serverStreamId Stream Lifecycle · Axis 1(b) Phase 2 — the
     *        server-authoritative id to ANNOUNCE as the first `ui.stream.id` SSE
     *        event (for forward adoption by a Phase-3 client). This is a SEPARATE
     *        coordinate from `$sessionId`, the ADDRESSING key the stream is keyed
     *        on. The transition rule (back-compat):
     *          - if the caller resolved `$sessionId` from a shape-valid CLIENT id,
     *            THAT remains the addressing key this phase, and `$serverStreamId`
     *            is the distinct server-minted id emitted for adoption (today's
     *            client ignores it, so it is inert until Phase 3);
     *          - if the client sent no id, the caller passes the server-minted id
     *            as BOTH `$sessionId` and `$serverStreamId`, so announced == key.
     *        Either way the announced id never becomes a SECOND live addressing
     *        coordinate this phase: in the only case a client actually adopts it
     *        (no client id sent), it already EQUALS the key. When empty (a caller
     *        that did not opt in), the addressing key is announced as a sane
     *        default. No client/data-frame change — the id rides its own event.
     */
    public static function serveResourceStream(
        Request $request,
        Response $response,
        string $sessionId,
        array $initialFrameData,
        ?SubscriptionRecord $record = null,
        ?ReRunContext $context = null,
        string $serverStreamId = '',
    ): void {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            $sessionId = self::mintStreamId();
        }

        // Per-IP + global connection caps (same bound as the kiss admit path) —
        // a held-open resource stream consumes a worker coroutine + fd just like
        // kiss, so it is accounted the same way, through the same limiter.
        $denial = self::connectionLimiter()->tryAcquire(
            self::resolveClientIp($request),
            $sessionId,
            $response,
        );
        if ($denial !== null) {
            self::rejectTooManyRequests($response, $denial);
            return;
        }

        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        // Capture the tenant THIS connection resolved, in its own coroutine where
        // TenantContext is authoritative (TenancyPhase ran before route dispatch),
        // so a later multiplex subscribe scopes its record from the connecting
        // tenant rather than the draining coroutine's ambient one.
        self::sessionRegistry()->open($sessionId, $response, self::currentTenantId(), self::currentTenantBlob());
        self::sessionRegistry()->ensureQueue($sessionId);
        self::workerTables()->recordOwnership($sessionId);
        self::touchActiveSession($sessionId);

        // Stream Lifecycle · Axis 1(b) Phase 2 — the server-authoritative stream
        // id as a DEDICATED first SSE event, written one line BEFORE the initial
        // data frame. It travels its own one-shot `ui.stream.id` channel (NOT a
        // field on the data frame) precisely so the data frame below stays
        // byte-identical to every re-run frame — the synchrony-pin invariant. A
        // Phase-3 client adopts it; today's client ignores the unknown event
        // (back-compat). Announce the server-minted id when supplied, else fall
        // back to the addressing key so every resource stream still announces one.
        $announcedStreamId = trim($serverStreamId) !== '' ? trim($serverStreamId) : $sessionId;
        self::writeSse($response, [
            '_type' => \Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType::UiStreamId->value,
            'stream_id' => $announcedStreamId,
        ]);

        // The initial rows frame, immediately — through the typed chokepoint so it
        // matches the re-run frame shape byte-for-byte. Stamp the subscription's
        // streaming_id so a multiplexed connection can demux the frame client-side;
        // the SAME stamp lands on every re-run frame (see dispatchReRun), so the
        // synchrony-pin byte-identity between initial and re-run frames holds.
        $streamingId = $record?->streamingId ?? $sessionId;
        self::writeSse($response, SseFrameFactory::stampSubscriptionId($initialFrameData, $streamingId));

        // Consumer-half launch (R5 · first production caller). Populates both tiers
        // and drives R3 subscribe-on-first; the tier-2 ReRunContext is keyed by
        // streaming_id, the key R4 resolves a cross-worker control back to.
        $coordinator = self::runtime()->connectCoordinator;
        if ($coordinator !== null && $record !== null && $context !== null) {
            try {
                $coordinator->onConnect($record, $context);
            } catch (\Throwable $e) {
                \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'track_r_onconnect_failed', [
                    'streaming_id' => $streamingId,
                    'session_id' => $sessionId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        try {
            self::runHeldOpenLoop($sessionId, $request, $response, '');
        } finally {
            if ($coordinator !== null && $record !== null && $context !== null) {
                try {
                    $coordinator->onDisconnect($streamingId);
                } catch (\Throwable $e) {
                    \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'track_r_ondisconnect_failed', [
                        'streaming_id' => $streamingId,
                        'session_id' => $sessionId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
            self::closeSession($sessionId, $response);
        }
    }

    private static function triggerDeferredBlocks(
        string $sessionId,
        string $deferredRequestId,
        ?string $lastEventId,
        bool $allowPersistentDeferredSse,
        bool $keepChannelOpen,
    ): void
    {
        $registry = \Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry::consume($deferredRequestId);

        $debugLog = static function (string $msg, array $data = []): void {
            /** @var array<string, mixed> $data */
            \Semitexa\Core\Log\StaticLoggerBridge::debug('ssr', $msg, $data);
        };

        if ($registry === null) {
            $debugLog('registry_null', ['deferred_request_id' => $deferredRequestId]);
            self::deliver($sessionId, [
                'type' => 'done',
                'live' => false,
                'close' => true,
                'reconnect' => false,
            ]);
            return;
        }

        $locale = $registry['locale'];

        $debugLog('registry_found', [
            'deferred_request_id' => $deferredRequestId,
            'page_handle' => $registry['page_handle'],
            'slots' => $registry['slots'],
            'locale' => $locale,
        ]);

        // Use coroutine to resolve deferred blocks concurrently
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            self::createSessionCoroutine(static function () use ($sessionId, $registry, $lastEventId, $deferredRequestId, $debugLog, $allowPersistentDeferredSse, $keepChannelOpen, $locale): void {
                try {
                    $orchestrator = self::deferredBlockOrchestrator();
                    $debugLog('orchestrator_resolved', ['session_id' => $sessionId]);
                    $orchestrator->streamDeferredBlocks(
                        sessionId: $sessionId,
                        pageHandle: $registry['page_handle'],
                        pageContext: $registry['page_context'],
                        lastEventId: $lastEventId,
                        deferredRequestId: $deferredRequestId,
                        locale: $locale !== '' ? $locale : null,
                        startLiveLoop: $allowPersistentDeferredSse,
                        keepChannelOpen: $keepChannelOpen,
                    );
                } catch (\Throwable $e) {
                    if (SseSessionCoroutines::isCancellation($e)) {
                        $debugLog('streaming_cancelled', ['session_id' => $sessionId]);
                        return;
                    }

                    $debugLog('streaming_failed', ['error' => $e->getMessage(), 'trace' => substr($e->getTraceAsString(), 0, 500)]);
                    \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Deferred block streaming failed', [
                        'session_id' => $sessionId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                    self::deliver($sessionId, [
                        'type' => 'done',
                        'live' => false,
                        'close' => true,
                        'reconnect' => false,
                    ]);
                }
            }, $sessionId);
        } else {
            try {
                $orchestrator = self::deferredBlockOrchestrator();
                $debugLog('orchestrator_resolved_sync', ['session_id' => $sessionId]);
                $orchestrator->streamDeferredBlocks(
                    sessionId: $sessionId,
                    pageHandle: $registry['page_handle'],
                    pageContext: $registry['page_context'],
                    lastEventId: $lastEventId,
                    deferredRequestId: $deferredRequestId,
                    locale: $locale !== '' ? $locale : null,
                    startLiveLoop: $allowPersistentDeferredSse,
                    keepChannelOpen: $keepChannelOpen,
                );
            } catch (\Throwable $e) {
                $debugLog('streaming_failed_sync', ['error' => $e->getMessage()]);
                \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Deferred block streaming failed (sync)', [
                    'session_id' => $sessionId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                self::deliver($sessionId, [
                    'type' => 'done',
                    'live' => false,
                    'close' => true,
                    'reconnect' => false,
                ]);
            }
        }
    }

    private static function deferredBlockOrchestrator(): DeferredBlockOrchestrator
    {
        if (self::runtime()->deferredBlockOrchestrator === null) {
            throw new \RuntimeException('DeferredBlockOrchestrator is not wired for AsyncResourceSseServer.');
        }

        return self::runtime()->deferredBlockOrchestrator;
    }

    private static function drainRedisQueueForSession(string $sessionId, Response $response): bool
    {
        $queue = self::redisSessionQueue();
        if (!$queue->isAvailable()) {
            return false;
        }

        while (true) {
            $popped = $queue->pop($sessionId);
            if (!$popped['ok']) {
                return false;
            }

            $raw = $popped['raw'];
            if (!is_string($raw)) {
                break;
            }

            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                continue;
            }

            // Track R · R4 — catch a control marker on the session-addressed Redis
            // queue: the canonical X→W seam (§C.4). A non-owner worker X RPUSHed
            // the `{__ctrl:rerun}` here; the OWNING worker W drains it on its tick
            // and resolves its worker-local tier-2 context. A miss (drained on a
            // worker without the tier-2 record) is the safe no-op edge.
            $ctrl = self::handleControlFrame($sessionId, $response, $data);
            if ($ctrl === self::CTRL_HANDLED_CLOSE) {
                return true;
            }
            if ($ctrl === self::CTRL_HANDLED_CONTINUE) {
                continue;
            }

            if (!self::writeSse($response, $data)) {
                // The socket rejected a frame we had already popped — put it back
                // so a reconnecting subscriber still gets it.
                $queue->pushRaw($sessionId, $raw);

                return true;
            }

            if (self::shouldCloseAfterPayload($data)) {
                return true;
            }
        }

        return false;
    }

    private static function writeSse(Response $response, array $data): bool
    {
        return self::transport()->writeFrame($response, self::buildFrame($data));
    }

    /**
     * Write an inert SSE keepalive comment. Per the SSE spec a line that
     * begins with ":" is a comment — EventSource ignores it entirely, so
     * no client-side handling is required. Returns false when the socket
     * is gone; the caller treats that as a closed connection.
     */
    private static function writeSseComment(Response $response): bool
    {
        return self::transport()->writeComment($response);
    }

    /**
     * The SSE write port. Binds the Swoole adapter lazily as a soft runtime
     * dependency (mirroring the other Swoole runtime adapters); the transport
     * is stateless, so a single shared instance is sufficient per worker.
     */
    private static function transport(): SseTransportInterface
    {
        return self::runtime()->transport ??= new SwooleSseTransport();
    }

    /**
     * Pure heartbeat decision: should the loop emit a keepalive comment,
     * given the current time, the last outbound-write time, and the
     * configured interval? Extracted so the cadence is unit-testable
     * without a Swoole Response / coroutine runtime. A non-positive
     * interval disables the heartbeat.
     */
    private static function shouldSendHeartbeat(int $now, int $lastWriteAt, int $intervalSeconds): bool
    {
        return self::transportModePolicy()->shouldSendHeartbeat($now, $lastWriteAt, $intervalSeconds);
    }

    /**
     * Pure helper: JSON-encode a session queue into the wire payloads the
     * Redis durability path pushes — preserving order and silently
     * dropping any entry that is not an array or cannot be encoded.
     * Extracted so the close/requeue encoding is unit-testable without a
     * Redis pool or Swoole runtime.
     *
     * @param list<mixed> $queue
     * @return list<string>
     */
    /**
     * Durability hook: push undelivered in-memory payloads onto the
     * existing Redis session queue so a reconnecting subscriber (possibly
     * on another worker) drains them via drainRedisQueueForSession().
     * Mirrors the enqueue path in deliver(). No-op without a Redis pool —
     * in the single-server / in-memory fallback the payloads are dropped,
     * matching the pre-existing best-effort guarantee.
     *
     * @param list<mixed> $payloads
     */
    private static function requeueToRedis(string $sessionId, array $payloads): void
    {
        self::redisSessionQueue()->push($sessionId, $payloads);
    }

    /**
     * Build the SSE wire frame for one payload as a portable {@see SseFrame}.
     *
     * This is the single chokepoint where the canonical `_type` field is
     * resolved (and allow-list-validated) into an SSE `event:` line. The
     * SSR/UI-domain enforcement stays here, on this consumer's own boundary,
     * BEFORE the frame is handed to the transport; the resulting `SseFrame`
     * carries an already-resolved event name and `core` renders it
     * mechanically (no allow-list, only CR/LF hygiene) in {@see SseFrame::toWire()}.
     * Behaviour of the resolution step:
     *
     *   - {@see SsePassthroughEvent::KEY} present (opt-in passthrough mode) →
     *     emit `event: <value>` for a value in the closed graphql-sse
     *     vocabulary and STRIP the key so the body renders bare; an
     *     out-of-vocabulary value is dropped (no `event:` line, key stripped).
     *     This is the ONLY path that produces an `event:` line without an
     *     in-body discriminator. No existing frame sets this key, so all
     *     pre-existing behaviour below is byte-identical (the key is absent and
     *     this branch is skipped).
     *   - `_type` absent → byte-identical to the pre-Phase-2 wire shape:
     *     the existing `event` field (if any, e.g. demo producer's
     *     `event: notification`) is honoured, and no other change is
     *     made.
     *   - `_type` present and on the {@see UiSseEventType} allow-list →
     *     emit `event: <_type>` (the canonical typed mapping overrides
     *     any client-supplied `event`; arbitrary strings MUST NOT escape
     *     the allow-list). The `_type` key remains in the JSON body so
     *     the wire envelope is self-describing.
     *   - `_type` present but unknown → log a warning, strip `_type`
     *     from the body, and fall back to default-message emission. We
     *     do not lose the payload; we only refuse to surface an
     *     unauthorised event name (matches the existing CR/LF-strip
     *     defensive normalise pattern).
     *
     * CR/LF injection on the `event:` line is prevented twice — first by
     * the allow-list (typed `_type` only emits values from a closed
     * enum), then by the `str_replace` on the rendered `event` line.
     * Defence in depth.
     *
     * @param array<array-key, mixed> $data
     */
    private static function buildFrame(array $data): SseFrame
    {
        return self::frameFactory()->build($data);
    }

    /**
     * Resolve the SSE event name from the payload, applying the typed-
     * `_type` allow-list. Returns `[event_name|null, normalised_data]`.
     * The normalised data may have `_type` removed when it was unknown
     * (so unauthorised strings never reach the wire).
     *
     * @param array<array-key, mixed> $data
     * @return array{0: string|null, 1: array<array-key, mixed>}
     */
    private static function startDemoStreamProducer(string $sessionId, string $demoStream): void
    {
        if ($demoStream !== 'showcase') {
            return;
        }

        if (!self::sessionRegistry()->tryStartDemoProducer($sessionId)) {
            return;
        }

        $producer = static function () use ($sessionId): void {
            \Swoole\Coroutine::sleep(0.35);

            if (!self::sessionRegistry()->isOpen($sessionId)) {
                self::sessionRegistry()->stopDemoProducer($sessionId);
                return;
            }

            $utcNow = static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            self::deliver($sessionId, [
                'id' => 'demo_attached_' . substr(md5($sessionId), 0, 8),
                'event' => 'notification',
                'level' => 'info',
                'title' => 'Stream attached',
                'message' => 'The backend SSE stream is open. A new server-side minute tick will arrive every 60 seconds.',
                'source' => 'swoole-worker',
                'sent_at' => $utcNow()->format(DATE_ATOM),
            ]);

            $tick = 0;

            while (self::sessionRegistry()->isOpen($sessionId)) {
                $now = microtime(true);
                $sleepSeconds = 60 - fmod($now, 60.0);

                if ($sleepSeconds < 0.05) {
                    $sleepSeconds += 60.0;
                }

                \Swoole\Coroutine::sleep($sleepSeconds);

                if (!self::sessionRegistry()->isOpen($sessionId)) {
                    self::sessionRegistry()->stopDemoProducer($sessionId);
                    return;
                }

                $tick++;
                $sentAt = $utcNow();

                self::deliver($sessionId, [
                    'id' => 'demo_minute_' . $tick . '_' . substr(md5($sessionId), 0, 8),
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

            self::sessionRegistry()->stopDemoProducer($sessionId);
        };

        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            self::createSessionCoroutine($producer, $sessionId);
            return;
        }

        self::sessionRegistry()->stopDemoProducer($sessionId);
    }

    private static function shouldCloseAfterPayload(array $data): bool
    {
        return self::transportModePolicy()->shouldCloseAfterPayload($data);
    }

    /**
     * Deliver payload to session.
     * Paths: same-worker queue -> Redis queue (cross-worker/server) -> Swoole Tables fallback -> pendingTable -> buffer.
     */
    public static function deliver(string $sessionId, array $data): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        // Same worker has the SSE connection: add to local queue
        if (self::sessionRegistry()->isOpen($sessionId)) {
            self::sessionRegistry()->enqueue($sessionId, $data);
            return;
        }

        // Cross-worker / cross-server: use the Redis queue when available. Any
        // failure falls through to the Swoole-table path below rather than
        // dropping the frame.
        if (self::redisSessionQueue()->tryPush($sessionId, $data)) {
            return;
        }

        // Fallback: Swoole tables (single server only). Hand the frame to the
        // worker that owns the socket — unless that is this worker, in which case
        // the session is gone and the frame belongs in the pending queue.
        $tables = self::workerTables();
        if ($tables->canRouteCrossWorker()) {
            $targetWorkerId = $tables->ownerWorkerId($sessionId);
            if (
                $targetWorkerId !== null
                && $targetWorkerId !== $tables->currentWorkerId()
                && $tables->queueForWorker($sessionId, $targetWorkerId, $data)
            ) {
                return;
            }
        }

        if ($tables->queuePending($sessionId, $data)) {
            return;
        }

        self::sessionRegistry()->buffer($sessionId, $data);
    }

    public static function broadcast(string $sessionId, string $handlerKey, object $resource): void
    {
        $html = self::renderResource($resource);
        $data = [
            'handler' => $handlerKey,
            'resource' => (array) $resource,
            'html' => $html,
        ];
        self::deliver($sessionId, $data);
    }

    public static function renderResource(object $resource): string
    {
        if (!method_exists($resource, 'getRenderHandle')) {
            return '';
        }

        $handle = $resource->getRenderHandle();
        if (!$handle) {
            return '';
        }

        $context = method_exists($resource, 'getRenderContext') ? $resource->getRenderContext() : [];
        $context = array_merge($context, (array) $resource);

        try {
            return \Semitexa\Ssr\Application\Service\Template\ModuleTemplateRegistry::getTwig()->render(
                $handle . '.html.twig',
                $context
            );
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Reads mutable session state from in-process tables and Redis. The return value
     * can flip between calls within the same request (sessions can end mid-coroutine),
     * so PHPStan must not narrow subsequent calls based on a prior true result.
     *
     * @phpstan-impure
     */
    public static function isSessionActive(string $sessionId): bool
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return false;
        }

        if (self::sessionRegistry()->isOpen($sessionId)) {
            return true;
        }

        if (self::workerTables()->isOwnedSomewhere($sessionId)) {
            return true;
        }

        $pool = self::getRedisPool();
        if ($pool !== null) {
            try {
                $isActive = $pool->withConnection(static function ($redis) use ($sessionId): bool {
                    /** @var Client $redis */
                    return (string) ($redis->get(SseAuthSessionMap::activeSessionKey($sessionId)) ?? '') === '1';
                });
                if ($isActive) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    public static function createSessionCoroutine(callable $callback, string $sessionId): int|false
    {
        return self::sessionCoroutines()->create($callback, $sessionId);
    }

    public static function setServer(\Swoole\Http\Server $server): void
    {
        self::workerTables()->setServer($server);
    }

    /**
     * Track R · R8a — register the paths served by the SSE intercept.
     *
     * Called once per worker by {@see WireSseServedPathsListener} with every
     * discovered `transport: Sse` route path. Stored as a `path => true` map so
     * {@see shouldServeAsSse()} is an O(1) lookup. Replaces (verbatim values are
     * supplied, not derived here): the listener owns the transport filter, this
     * setter owns nothing but the index shape.
     *
     * @param list<string> $paths
     */
    public static function setSseServedPaths(array $paths): void
    {
        self::runtime()->setServedPaths($paths);
    }

    public static function setTables(
        \Swoole\Table $sessionWorkerTable,
        \Swoole\Table $deliverTable,
        ?\Swoole\Table $pendingDeliverTable = null,
    ): void
    {
        self::workerTables()->setTables($sessionWorkerTable, $deliverTable, $pendingDeliverTable);
    }

    public static function setDeferredBlockOrchestrator(?DeferredBlockOrchestrator $orchestrator): void
    {
        self::runtime()->deferredBlockOrchestrator = $orchestrator;
    }

    /**
     * Track R · R4 — wire the core re-run unit (R2) the loop branch calls when it
     * catches a `{__ctrl:rerun}` control. Live binding is the dispatcher-wiring
     * brick / R8; until then it stays null and a control is a safe no-op.
     */
    public static function setReRunner(?ReRunnerInterface $reRunner): void
    {
        self::runtime()->reRunner = $reRunner;
    }

    /**
     * SSE transport unification · Phase 1 — wire the subscription factory the
     * subscribe-control branch uses to attach a feed to a live KISS connection.
     * Set by {@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\WireTrackRConsumerListener};
     * unwired, a subscribe control is a safe no-op.
     */
    public static function setSubscriptionFactory(?SubscriptionFactoryInterface $factory): void
    {
        self::runtime()->subscriptionFactory = $factory;
    }

    /**
     * The tenant discriminator for THIS coroutine, captured at connect admit and
     * later handed to {@see SubscriptionFactoryInterface::build()} so a multiplex
     * record is scoped to the connection's tenant, not the draining coroutine's.
     * Mirrors {@see \Semitexa\Ssr\Application\Service\Async\PipelineSubscriptionFactory}
     * and the standalone handler — defensive, '' / 'default' when no tenancy.
     */
    private static function currentTenantId(): string
    {
        $tenant = self::resolveTenantContext();
        if (is_object($tenant) && method_exists($tenant, 'getTenantId')) {
            $id = trim((string) $tenant->getTenantId());
            if ($id !== '') {
                return $id;
            }
        }

        return 'default';
    }

    /** The opaque serialized tenant context paired with {@see currentTenantId()}. */
    private static function currentTenantBlob(): string
    {
        $tenant = self::resolveTenantContext();
        $blob = null;
        if (is_object($tenant) && method_exists($tenant, 'forSerialization')) {
            $blob = $tenant->forSerialization();
        }

        try {
            return json_encode(is_array($blob) ? $blob : [], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '[]';
        }
    }

    private static function resolveTenantContext(): ?object
    {
        $ctx = '\Semitexa\Tenancy\Context\TenantContext';
        if (class_exists($ctx) && method_exists($ctx, 'get')) {
            $tenant = $ctx::get();

            return is_object($tenant) ? $tenant : null;
        }

        return null;
    }

    /**
     * SSE transport unification · Phase 1 — register one subscription's two tiers
     * on an ALREADY-OPEN connection (the multiplex attach). The loop-free
     * counterpart of {@see serveResourceStream()}'s consumer-half launch: it only
     * runs the R5 {@see ConnectCoordinator::onConnect()} (tier-1 row + tier-2
     * context + subscribe-on-first). The initial frame is written by the caller
     * (the subscribe-control branch) onto the same fd. Distinct `streamingId`s on
     * one `sessionId` coexist natively — the {@see SubscriptionTable} is keyed by
     * `streamingId`.
     */
    public static function attachSubscription(SubscriptionRecord $record, ReRunContext $context): void
    {
        self::controlRouter()->attach($record, $context);
    }

    /**
     * SSE transport unification · Phase 1 — reap ONE subscription (the unsubscribe
     * branch / DOM teardown), leaving any siblings on the same connection intact.
     * Wraps R5 {@see ConnectCoordinator::onDisconnect()} (reap tier-1 + tier-2 +
     * unsubscribe-on-last for the scope channel).
     */
    public static function detachSubscription(string $streamingId): void
    {
        self::controlRouter()->detach($streamingId);
    }

    /**
     * Track R · R4 — wire the cross-worker re-run coalescer (R3) whose pending
     * mark the loop branch CLEARS after handling a control, re-arming the next
     * mutation's signal (the bounded-coalescing window). The shared table is
     * created pre-fork by R5's {@see CreateTrackRTablesListener}.
     */
    public static function setRerunCoalescer(?RerunCoalescer $coalescer): void
    {
        self::runtime()->rerunCoalescer = $coalescer;
    }

    /**
     * Track R · Intended Grid Model · Phase 2 (C2) — wire the cross-worker
     * view-change coalescer the command intake ({@see submitViewChange()}) and the
     * loop branch ({@see handleControlFrame()}) share. The shared table is created
     * pre-fork by R5's {@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\CreateTrackRTablesListener}.
     */
    public static function setViewChangeCoalescer(?ViewChangeCoalescer $coalescer): void
    {
        self::runtime()->viewChangeCoalescer = $coalescer;
    }

    /**
     * Track R · Intended Grid Model · Phase 2 (C2) — the inbound view-change
     * command intake (the browser PRODUCER side).
     *
     * A view-change command (page / limit / sort / filter change) arrives as a
     * SEPARATE request and enqueues a `{__ctrl:viewchange}` control onto the held
     * stream's session-addressed queue — the SAME X→W queue a mutation `{__ctrl:rerun}`
     * rides ({@see deliver()}), reaching the owning worker which re-runs and pushes a
     * fresh frame on the OPEN fd. This NEVER returns rows; the caller (the app's
     * command endpoint) returns only an ack.
     *
     * Coalescing (R3 discipline, latest-view-wins): the coalescer stores the latest
     * params and admits only the 0→1 enqueue, so a rapid burst collapses to one
     * re-run that re-queries the FINAL view. Without a wired coalescer (e.g. before
     * boot wiring) the params ride inline in the control as a best-effort,
     * uncoalesced fallback.
     *
     * @param array<string, mixed> $params the new view params (filter-only is
     *        enforced downstream by the re-run's marker-gated override — see
     *        {@see \Semitexa\Core\Pipeline\ReRun\LiveFilterParamOverride})
     * @return bool whether the command was accepted onto the queue (false only when
     *        the session id is missing/malformed — never a delivery guarantee)
     */
    public static function submitViewChange(string $sessionId, array $params, ?string $streamingId = null): bool
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || preg_match(self::SAFE_BEARER_SESSION_ID_PATTERN, $sessionId) !== 1) {
            return false;
        }

        // The tier-2 re-run state is keyed by streaming_id. For a standalone
        // held-open own-route stream streaming_id == session_id (one stream per
        // connection), so a caller that only knows the session id addresses it
        // directly (back-compat default). A MULTIPLEXED connection (SSE transport
        // unification) hosts many subscriptions on one session and MUST name the
        // target streaming_id explicitly so the view-change re-runs the right one.
        $streamingId = trim((string) ($streamingId ?? $sessionId));
        if ($streamingId === '') {
            $streamingId = $sessionId;
        }

        if (self::runtime()->viewChangeCoalescer === null) {
            // Fallback: no coalescer wired — carry params inline, uncoalesced.
            self::deliver($sessionId, SseControlFrame::viewChange($streamingId, $params));

            return true;
        }

        // Store the latest view + gate the enqueue. Only the 0→1 command enqueues a
        // (param-less) control; the owner reads the LATEST params from the coalescer
        // when it drains, so a coalesced burst re-queries the final view. Coalescing
        // is per-streaming_id, so distinct subscriptions on one session never
        // collide.
        if (self::runtime()->viewChangeCoalescer->submit($streamingId, $params)) {
            self::deliver($sessionId, SseControlFrame::viewChange($streamingId));
        }

        return true;
    }

    /**
     * SSE transport unification · Phase 1 — submit a SUBSCRIBE command: attach a
     * feed subscription to the caller's live KISS connection. Mirrors
     * {@see submitViewChange()} — the auth-bearing request snapshot rides the
     * control TRANSIENTLY to the fd-owning worker (consumed once, never stored in
     * the cross-worker index, so the tier-separation security boundary holds), and
     * that worker builds the subscription locally (so tier-2 lands where the
     * re-run runs). Returns false only when an id is missing/malformed.
     *
     * @param array<string, mixed> $requestSnapshot the auth-bearing request snapshot
     */
    public static function submitSubscribe(
        string $sessionId,
        string $streamingId,
        string $routePath,
        string $routeMethod,
        array $requestSnapshot,
    ): bool {
        $sessionId = trim($sessionId);
        $streamingId = trim($streamingId);
        if (
            $sessionId === '' || preg_match(self::SAFE_BEARER_SESSION_ID_PATTERN, $sessionId) !== 1
            || $streamingId === '' || preg_match(self::SAFE_BEARER_SESSION_ID_PATTERN, $streamingId) !== 1
            || $routePath === ''
        ) {
            return false;
        }

        self::deliver($sessionId, SseControlFrame::subscribe($streamingId, $routePath, $routeMethod, $requestSnapshot));

        return true;
    }

    /**
     * SSE transport unification · Phase 1 — submit an UNSUBSCRIBE command: detach
     * one subscription from the caller's KISS connection (siblings survive).
     */
    public static function submitUnsubscribe(string $sessionId, string $streamingId): bool
    {
        $sessionId = trim($sessionId);
        $streamingId = trim($streamingId);
        if (
            $sessionId === '' || preg_match(self::SAFE_BEARER_SESSION_ID_PATTERN, $sessionId) !== 1
            || $streamingId === '' || preg_match(self::SAFE_BEARER_SESSION_ID_PATTERN, $streamingId) !== 1
        ) {
            return false;
        }

        self::deliver($sessionId, SseControlFrame::unsubscribe($streamingId));

        return true;
    }

    /**
     * Track R · R8c (C2) — wire the per-worker connect coordinator (R5) the
     * held-open resource stream drives. Set live by
     * {@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\WireTrackRConsumerListener}.
     */
    public static function setConnectCoordinator(?ConnectCoordinator $coordinator): void
    {
        self::runtime()->connectCoordinator = $coordinator;
    }

    /**
     * Track R · R8c — is the current coroutine inside a re-run tick?
     *
     * True only while {@see handleControlFrame()} is re-running the chain on THIS
     * coroutine. An own-route held-open handler consults this to take its JSON-body
     * branch on a re-run (the loop frames the body) instead of grabbing the live
     * socket and entering a second held-open stream.
     */
    public static function isReRunInProgress(): bool
    {
        return self::reRunScope()->isInProgress();
    }

    /**
     * Kept although nothing inside this class calls them any more: they are the
     * seam the graphql streamer's test and the document-feed handler's test use
     * to open the FACADE's re-run scope. A test that built its own SseReRunScope
     * would be invisible to code that asks the facade, so this stays.
     */
    private static function beginReRunScope(): void
    {
        self::reRunScope()->begin();
    }

    private static function endReRunScope(): void
    {
        self::reRunScope()->end();
    }

    /**
     * Track R · R4 — the loop branch that catches a `{__ctrl:rerun}` control
     * before it can be written to the client and turns it into a full-chain
     * re-run, closing the push→re-run cycle.
     *
     * A control marker is a SIGNAL, never a data frame (§C.4): it carries
     * `{__ctrl:'rerun', streaming_id, scope_key}` and no row data. On such a
     * marker this:
     *   1. resolves the worker-local {@see ReRunContext} by `streaming_id`
     *      (R1 tier-2, {@see SubscriptionDtoRegistry});
     *   2. re-runs the full handler chain auth-first via R2's {@see ReRunnerInterface};
     *   3. on a fresh frame → writes it to this stream; on TERMINATE → returns
     *      HANDLED_CLOSE after emitting a close frame and NO data frame (the
     *      lost-access path, §B.3);
     *   4. clears the coalescer mark (R3's {@see RerunCoalescer::clearPending()})
     *      after EITHER outcome, so the next mutation's signal re-arms.
     *
     * CROSS-WORKER CORRECTNESS (§C3, the decisive edge): the control rides the
     * session-addressed queue, so the OWNING worker drains it and finds its
     * tier-2 context locally. If a control is drained where no tier-2 record
     * exists — a non-owner worker drained it, or the stream was already torn
     * down — the re-run is a SAFE no-op: no crash, no re-run, no frame. (The
     * tier-2 registry is worker-local; a miss there is exactly that edge.)
     *
     * @param mixed                $response the opaque transport handle (Swoole\Http\Response)
     * @param array<string, mixed> $data
     * @return int one of the CTRL_* outcomes
     */
    private static function handleControlFrame(string $sessionId, mixed $response, array $data): int
    {
        return self::controlRouter()->handle($sessionId, $response, $data);
    }

    /**
     * Fan-out to every active session of one user.
     *
     * @internal FENCED FAIL-CLOSED until Track R. This non-owner-request-scoped
     *           writer does zero content-vs-recipient authorization (it merely
     *           loops owner-scoped {@see self::deliver()} over a recipient list),
     *           so private content could ride it to non-entitled sessions. It is
     *           latent (zero callers); the throw fires BEFORE any deliver()/socket
     *           write so no frame can leak even partially. Track R replaces this
     *           throw with the per-recipient entitlement-gated implementation
     *           preserved below. Do NOT wire a caller before then.
     *
     * @param array<string, mixed> $data
     */
    public static function deliverToUser(string $userId, array $data): int
    {
        throw FanOutNotYetGatedException::forFanOut(__METHOD__);

        // Track R restores the entitlement-gated form of the original body:
        //
        //     $userId = trim($userId);
        //     if ($userId === '') {
        //         return 0;
        //     }
        //     $sessionIds = self::getAuthenticatedUserSessionIds($userId);
        //     $delivered = 0;
        //     foreach ($sessionIds as $sessionId) {
        //         // Track R: per-recipient entitlement check on ($sessionId, $data) here.
        //         self::deliver($sessionId, $data);
        //         $delivered++;
        //     }
        //     return $delivered;
    }

    /**
     * System-wide fan-out to every authenticated session.
     *
     * @internal FENCED FAIL-CLOSED until Track R. System-wide broadcast with zero
     *           content-vs-recipient authorization; latent (zero callers). The
     *           throw fires BEFORE any deliver()/socket write so no frame can leak.
     *           Track R replaces this throw with the per-recipient entitlement-gated
     *           implementation preserved below. Do NOT wire a caller before then.
     *
     * @param array<string, mixed> $data
     */
    public static function deliverToAuthenticatedUsers(array $data): int
    {
        throw FanOutNotYetGatedException::forFanOut(__METHOD__);

        // Track R restores the entitlement-gated form of the original body:
        //
        //     $sessionIds = self::getAllAuthenticatedSessionIds();
        //     $delivered = 0;
        //     foreach ($sessionIds as $sessionId) {
        //         // Track R: per-recipient entitlement check on ($sessionId, $data) here.
        //         self::deliver($sessionId, $data);
        //         $delivered++;
        //     }
        //     return $delivered;
    }

    /**
     * Typed wrapper around \Swoole\Coroutine::getCid(). The Swoole stub PHPStan
     * sees returns mixed, but the runtime contract is int (>=0 inside a coroutine,
     * negative otherwise). Wrapping it once keeps the rest of this class type-safe.
     */
    private static function closeSession(string $sessionId, Response $response): void
    {
        // This handler owns the response lifecycle end-to-end. SseKissHandler
        // marks the framework ResourceResponse as alreadySent so the emitter
        // does not also call status/header/end — that double-end pattern
        // SIGSEGV'd Swoole 6.2.1 workers under server-initiated close.
        // SSE transport unification · Phase 1.5 — reap every multiplex subscription
        // bound to this session (a KISS connection may host N). The standalone
        // own-route stream already onDisconnect'd its single streaming_id before
        // this; for those rows this is a no-op (idempotent).
        self::runtime()->connectCoordinator?->reapSession($sessionId);
        self::cancelSessionCoroutines($sessionId);
        self::removeSessionWorkerMapping($sessionId);
        self::unregisterAuthenticatedSession($sessionId);
        self::releaseIpConnection($sessionId, $response);
        // Durability: any payloads still queued for this connection (the
        // socket closed before the loop drained them) are flushed to the
        // Redis session queue so a reconnecting subscriber drains them via
        // drainRedisQueueForSession(). No-op when the queue is empty (the
        // normal drain / clean-close case) or when Redis is unavailable.
        if (self::sessionRegistry()->hasQueued($sessionId)) {
            self::requeueToRedis($sessionId, self::sessionRegistry()->queued($sessionId));
        }
        self::sessionRegistry()->close($sessionId);
        self::sessionCoroutines()->forget($sessionId);
        @$response->end();
    }

    private static function releaseIpConnection(string $sessionId, Response $response): void
    {
        self::connectionLimiter()->release($sessionId, $response);
    }

    private static function resolveClientIp(Request $request): string
    {
        return self::requestGuard()->resolveClientIp($request);
    }

    /**
     * @see SseTransportModePolicy::maxConnectionAgeSeconds()
     */
    public static function maxConnectionAgeSeconds(): int
    {
        return self::transportModePolicy()->maxConnectionAgeSeconds();
    }

    private static function rejectUnauthorized(Response $response, string $message): void
    {
        self::requestGuard()->rejectUnauthorized($response, $message);
    }

    private static function resolveSseAuthorizationError(
        bool $authenticated,
        bool $anonymousAllowed,
        string $demoStream,
        string $deferredRequestId,
        bool $safeBearerSessionId,
        bool $persistentRequested = false,
    ): ?string {
        return self::requestGuard()->resolveAuthorizationError(
            $authenticated,
            $anonymousAllowed,
            $demoStream,
            $deferredRequestId,
            $safeBearerSessionId,
            $persistentRequested,
        );
    }

    /**
     * @return array{status: int, message: string}|null
     */
    private static function resolveSseRequestRejection(bool $sameOrigin, ?string $authError): ?array
    {
        return self::requestGuard()->resolveRejection($sameOrigin, $authError);
    }

    private static function rejectTooManyRequests(Response $response, string $message): void
    {
        self::requestGuard()->rejectTooManyRequests($response, $message);
    }

    private static function rejectBadRequest(Response $response, string $message): void
    {
        self::requestGuard()->rejectBadRequest($response, $message);
    }

    /**
     * @see SseTransportModePolicy::resolveMode() for the full mode table.
     */
    private static function resolveTransportMode(
        string $rawMode,
        bool $authenticated,
        bool $anonymousAllowed,
        bool $safeBearerSessionId,
        string $deferredRequestId,
    ): ?string {
        return self::transportModePolicy()->resolveMode(
            $rawMode,
            $authenticated,
            $anonymousAllowed,
            $safeBearerSessionId,
            $deferredRequestId,
        );
    }

    private static function cancelSessionCoroutines(string $sessionId): void
    {
        self::sessionCoroutines()->cancelFor($sessionId);
    }

    private static function getSsrBindToken(Request $request): string
    {
        $cookieName = 'semitexa_ssr_bind';
        $cookie = is_array($request->cookie) ? $request->cookie : [];

        return trim((string) ($cookie[$cookieName] ?? ''));
    }

    private static function removeSessionWorkerMapping(string $sessionId): void
    {
        self::workerTables()->releaseOwnership($sessionId);
    }

    private static function registerAuthenticatedSession(string $sessionId, string $userId): void
    {
        self::authSessionMap()->register($sessionId, $userId);
    }

    private static function unregisterAuthenticatedSession(string $sessionId): void
    {
        self::authSessionMap()->unregister($sessionId);
    }

    private static function touchActiveSession(string $sessionId): void
    {
        self::authSessionMap()->touch($sessionId);
    }

    private static function refreshAuthenticatedSessionMapping(
        Request $request,
        string $sessionId,
        string $authenticatedUserId,
    ): string {
        return self::authSessionMap()->refresh($request, $sessionId, $authenticatedUserId);
    }

    private static function canUsePersistentDeferredSse(Request $request): bool
    {
        $config = IsomorphicConfig::fromEnvironment();
        if (!$config->persistentDeferredSse) {
            return false;
        }

        if (!$config->persistentDeferredSseRequireAuth) {
            return true;
        }

        return self::hasAuthenticatedSession($request);
    }

    private static function hasAuthenticatedSession(Request $request): bool
    {
        return self::authSessionMap()->isAuthenticated($request);
    }

    private static function isSafeBearerSessionId(mixed $rawSessionId): bool
    {
        return self::requestGuard()->isSafeBearerSessionId($rawSessionId);
    }

    private static function resolveAuthenticatedUserId(Request $request): string
    {
        return self::authSessionMap()->resolveUserId($request);
    }

    /**
     * Publish a DATA-LESS scope-invalidation signal on the SSE Redis bus
     * (Track R · P3 — the cross-instance push origin). The channel name
     * (`ui.invalidate.{tenant}.{scopeKey}`) carries the full routing key;
     * the message body is intentionally empty — the subscriber (R3) re-runs
     * the recipient's own chain, it does not consume row data here.
     *
     * Reuses the existing size-1 SSE pool deliberately: a PUBLISH is a
     * non-blocking request/reply command, so — unlike the subscriber's
     * blocking `pubSubLoop`, which MUST own a dedicated connection — it is
     * safe to borrow the shared pooled connection (design §C.3). No-op
     * without a Redis pool (single-server / in-memory mode): cross-instance
     * fan-out has no non-Redis path, and a dropped signal is repaired by the
     * next mutation's signal (idempotent / lossy-tolerant, design §C.3).
     */
    /**
     * @see SseRedisSessionQueue::publishInvalidation()
     */
    public static function publishScopeInvalidation(string $channel): void
    {
        self::redisSessionQueue()->publishInvalidation($channel);
    }

    private static function getRedisPool(): ?RedisConnectionPool
    {
        return self::redisPool()->get();
    }

    /** @return list<string> */
    private static function getAuthenticatedUserSessionIds(string $userId): array
    {
        return self::authSessionMap()->sessionIdsForUser($userId);
    }

    /** @return list<string> */
    private static function getAllAuthenticatedSessionIds(): array
    {
        return self::authSessionMap()->allSessionIds();
    }

    /**
     * @param list<mixed> $sessionIds
     * @return list<string>
     */
    private static function isSameOriginRequest(Request $request): bool
    {
        return self::requestGuard()->isSameOriginRequest($request);
    }

    /**
     * The worker's admission-control collaborator.
     *
     * Lazily created rather than wired by a `Wire*Listener` because the guard is
     * stateless — there is nothing to seed, and nothing for two coroutines to
     * race over. `tk-sse-wire-di` replaces this accessor with a container
     * binding once every collaborator is extracted.
     */
    private static function requestGuard(): SseRequestGuard
    {
        return self::$requestGuard ??= new SseRequestGuard();
    }

    private static function transportModePolicy(): SseTransportModePolicy
    {
        return self::$transportModePolicy ??= new SseTransportModePolicy();
    }

    private static function frameFactory(): SseFrameFactory
    {
        return self::$frameFactory ??= new SseFrameFactory();
    }

    private static function connectionLimiter(): SseConnectionLimiter
    {
        return self::$connectionLimiter ??= new SseConnectionLimiter();
    }

    private static function redisPool(): SseRedisPool
    {
        return self::$redisPoolResolver ??= new SseRedisPool();
    }

    private static function redisSessionQueue(): SseRedisSessionQueue
    {
        return self::$redisSessionQueue ??= new SseRedisSessionQueue(self::redisPool());
    }

    private static function authSessionMap(): SseAuthSessionMap
    {
        return self::$authSessionMap ??= new SseAuthSessionMap(self::redisPool());
    }

    private static function reRunScope(): SseReRunScope
    {
        return self::$reRunScope ??= new SseReRunScope();
    }

    private static function sessionCoroutines(): SseSessionCoroutines
    {
        return self::$sessionCoroutines ??= new SseSessionCoroutines();
    }

    private static function sessionRegistry(): SseSessionRegistry
    {
        return self::$sessionRegistry ??= new SseSessionRegistry();
    }

    private static function workerTables(): SseWorkerTables
    {
        return self::$workerTables ??= new SseWorkerTables();
    }

    private static function runtime(): SseRuntime
    {
        return self::$runtime ??= new SseRuntime();
    }

    /**
     * Built fresh rather than memoized: the runtime's collaborators are wired
     * late, and a router captured before the lifecycle listeners ran would hold
     * the same holder anyway — but rebuilding keeps the dependency explicit and
     * costs one small object per control frame, which is not a hot path.
     */
    private static function controlRouter(): SseControlRouter
    {
        return new SseControlRouter(
            self::runtime(),
            self::frameFactory(),
            self::sessionRegistry(),
            self::reRunScope(),
        );
    }
}
