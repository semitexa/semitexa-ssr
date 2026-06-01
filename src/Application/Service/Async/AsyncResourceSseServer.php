<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Semitexa\Core\Environment;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Pipeline\ReRun\ReRunnerInterface;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Session\RedisSessionHandler;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Server\SseFrame;
use Semitexa\Core\Server\SseTransportInterface;
use Semitexa\Ssr\Application\Service\DeferredBlockOrchestrator;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
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
     */
    public const SAFE_BEARER_SESSION_ID_PATTERN = '/\Asse_[a-f0-9]{32}\z/';

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
    public const TRANSPORT_MODE_DRAIN = 'drain';

    /**
     * Long-lived KISS transport: enter the existing while-loop and stay
     * open until max-age / done / disconnect. Reserved for authenticated
     * dashboards, admin/internal tools, monitoring, terminal-like
     * interfaces, and other explicitly trusted deployments.
     */
    public const TRANSPORT_MODE_LIVE = 'live';

    /**
     * No explicit mode supplied. Behaviour:
     *   - deferred_request_id present, authenticated session, or
     *     SSE_PUBLIC_ANONYMOUS=1 → preserve the existing long-lived loop
     *     (legacy callers, deferred SSR streams);
     *   - safe anonymous bearer (`sse_<32hex>`) only → upgrade to drain so
     *     a guest page that forgot the mode marker does NOT silently open
     *     a long-lived stream.
     */
    private const TRANSPORT_MODE_LEGACY = 'legacy';

    private const AUTH_SESSION_USER_KEY = '_auth_user_id';
    private const AUTH_SESSION_TTL_SECONDS = 7200;
    private const AUTH_SESSION_TOUCH_INTERVAL_SECONDS = 30;
    private const ACTIVE_SESSION_TTL_SECONDS = 45;
    private const REDIS_AUTH_USER_SESSIONS_PREFIX = 'semitexa_sse_auth_user:';
    private const REDIS_AUTH_SESSION_USER_PREFIX = 'semitexa_sse_auth_session:';
    private const REDIS_AUTH_ALL_SESSIONS_KEY = 'semitexa_sse_auth_sessions';
    private const REDIS_ACTIVE_SESSION_PREFIX = 'semitexa_sse_active_session:';
    private const REDIS_SESSION_QUEUE_PREFIX = 'semitexa_sse_queue:';
    private const REDIS_SESSION_QUEUE_TTL_SECONDS = 7200;

    // Connection hardening defaults (all env-overridable).
    private const DEFAULT_MAX_CONN_PER_IP = 5;
    private const DEFAULT_MAX_CONN_GLOBAL = 500;
    private const DEFAULT_MAX_CONNECTION_AGE_SECONDS = 600;

    /**
     * Keepalive cadence for persistent SSE loops. After this many seconds
     * with no outbound frame, the loop writes an inert SSE comment
     * (":\n\n") so an idle-but-healthy stream is not silently dropped by an
     * intermediary (nginx's default 60s proxy_read_timeout being the
     * canonical offender) and so a dead socket is detected promptly on the
     * next write. Comfortably under the 60s default proxy window. Drain
     * streams short-circuit before the loop, so the heartbeat only ever
     * applies to live / legacy / persistent-deferred streams.
     */
    private const HEARTBEAT_INTERVAL_SECONDS = 20;

    /** @var array<string, int> Per-worker IP → open-connection counter. */
    private static array $ipConnections = [];

    /** @var array<string, string> Connection key → client IP (for decrement on close). */
    private static array $sessionIps = [];

    private static array $sessions = [];

    /** @var array<string, list<array>> Pending messages per session when no connection yet */
    private static array $buffer = [];

    /** @var array<string, list<array>> In-memory queue per session for the loop to send */
    private static array $queues = [];

    /** @var array<string, bool> */
    private static array $demoProducers = [];

    /** @var array<string, array<int, true>> Session-scoped coroutine IDs for deferred/live SSE work */
    private static array $sessionCoroutines = [];

    private static ?\Swoole\Http\Server $httpServer = null;

    /** @var \Swoole\Table|null session_id -> worker_id (for cross-worker deliver) */
    private static ?\Swoole\Table $sessionWorkerTable = null;

    /** @var \Swoole\Table|null deliver queue: unique_key -> session_id, worker_id, payload */
    private static ?\Swoole\Table $deliverTable = null;

    /** @var \Swoole\Table|null pending messages when client not connected yet: key -> session_id, payload */
    private static ?\Swoole\Table $pendingDeliverTable = null;
    private static ?RedisConnectionPool $redisPool = null;
    private static ?DeferredBlockOrchestrator $deferredBlockOrchestrator = null;

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
    private static array $sseServedPaths = [];

    /**
     * Swoole-free SSE write port (core contract). The Swoole adapter binds
     * lazily as a soft runtime dependency, mirroring how the rest of the
     * Swoole runtime adapters are wired. Held here so the byte-writing path
     * goes through the {@see SseTransportInterface} contract rather than
     * touching `Swoole\Http\Response::write()` directly.
     */
    private static ?SseTransportInterface $transport = null;

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
    private static ?ReRunnerInterface $reRunner = null;
    private static ?RerunCoalescer $rerunCoalescer = null;

    /**
     * Track R · Intended Grid Model · Phase 2 (C2) — the view-change coalescer.
     *
     * The cross-worker "latest view wins, collapse pending" table for a
     * `{__ctrl:viewchange}` command (distinct from {@see $rerunCoalescer} so a
     * mutation re-run and a view-change re-run never suppress each other). Wired
     * alongside the rerun coalescer; null until then — while null a view-change is
     * carried inline in the control payload as a best-effort, uncoalesced fallback.
     */
    private static ?ViewChangeCoalescer $viewChangeCoalescer = null;

    /**
     * Track R · R8c (C2) — the per-worker connect coordinator (R5) that the
     * held-open resource stream ({@see serveResourceStream()}) drives on
     * connect/disconnect to populate / reap the three-tier subscription store and
     * subscribe-on-first / unsubscribe-on-last. Wired live by
     * {@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\WireTrackRConsumerListener}.
     * Null until wired (and on the kiss path, which has no resource subscription) —
     * a null coordinator makes the consumer-half a safe no-op.
     */
    private static ?ConnectCoordinator $connectCoordinator = null;

    /**
     * Track R · R8c — re-run reentrancy depth, per coroutine.
     *
     * {@see handleControlFrame()} re-runs the FULL handler chain
     * ({@see ReRunnerInterface::reRun()} → `RouteExecutor::reExecute()`), which
     * re-invokes the SAME own-route handler. That handler must produce the fresh
     * frame BODY (JSON) and must NOT grab the live socket and enter a second
     * held-open stream (which would break the fd it is already streaming on, or
     * recurse). The handler asks {@see isReRunInProgress()} to take its JSON branch
     * on a re-run tick. The depth is COROUTINE-LOCAL ({@see Coroutine::getContext()})
     * because a re-run may yield on I/O to another session's connect coroutine —
     * a per-worker static would cross-contaminate; only the re-running coroutine
     * itself must read the flag. A static fallback covers the CLI/test (no-coroutine)
     * path. @var int
     */
    private static int $reRunDepthFallback = 0;

    private const RERUN_SCOPE_CONTEXT_KEY = '__semitexa_track_r_rerun_depth';

    /**
     * {@see handleControlFrame()} outcomes. A control marker is a SIGNAL, never
     * bytes for the wire (§C.4): NOT_CONTROL → the caller writes the ordinary
     * data frame as before; HANDLED_CONTINUE → the control was consumed (re-run
     * frame written, or a safe no-op), the drain continues; HANDLED_CLOSE → the
     * re-run TERMINATEd (lost access) or the fresh-frame write failed, the stream
     * must close.
     */
    private const CTRL_NOT_CONTROL = 0;
    private const CTRL_HANDLED_CONTINUE = 1;
    private const CTRL_HANDLED_CLOSE = 2;

    /** The control kind key + the recognised control kinds on a session queue. */
    private const CTRL_KEY = '__ctrl';
    private const CTRL_RERUN = 'rerun';
    private const CTRL_VIEWCHANGE = 'viewchange';

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
    private static function shouldServeAsSse(string $path): bool
    {
        return isset(self::$sseServedPaths[$path]);
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
        $clientIp = self::resolveClientIp($request);
        $maxPerIp = self::envInt('SSE_MAX_CONN_PER_IP', self::DEFAULT_MAX_CONN_PER_IP);
        $maxGlobal = self::envInt('SSE_MAX_CONN_GLOBAL', self::DEFAULT_MAX_CONN_GLOBAL);

        $globalOpen = array_sum(self::$ipConnections);
        if ($globalOpen >= $maxGlobal) {
            self::rejectTooManyRequests($response, 'SSE connection cap reached for this worker.');
            return;
        }
        if ($clientIp !== '' && ((self::$ipConnections[$clientIp] ?? 0) >= $maxPerIp)) {
            self::rejectTooManyRequests($response, 'SSE connection cap reached for your IP.');
            return;
        }

        if ($clientIp !== '') {
            self::$ipConnections[$clientIp] = (self::$ipConnections[$clientIp] ?? 0) + 1;
            self::$sessionIps[self::sessionConnectionKey($sessionId, $response)] = $clientIp;
        }

        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        self::$sessions[$sessionId] = [
            'response' => $response,
            'connected_at' => time(),
        ];

        $authenticatedUserId = self::resolveAuthenticatedUserId($request);
        if ($authenticatedUserId !== '') {
            self::registerAuthenticatedSession($sessionId, $authenticatedUserId);
        }
        self::touchActiveSession($sessionId);

        if (self::$sessionWorkerTable !== null && self::$httpServer !== null) {
            $workerId = self::getCurrentWorkerId();
            $key = self::sessionTableKey($sessionId);
            self::$sessionWorkerTable->set($key, ['worker_id' => $workerId]);
        }

        if (!isset(self::$queues[$sessionId])) {
            self::$queues[$sessionId] = [];
        }

        // Flush local buffer for this session only
        $bufferCount = 0;
        if (isset(self::$buffer[$sessionId])) {
            foreach (self::$buffer[$sessionId] as $data) {
                self::writeSse($response, $data);
                $bufferCount++;
            }
            unset(self::$buffer[$sessionId]);
        }

        // Flush pending table for this session only
        $pendingCount = 0;
        if (self::$pendingDeliverTable !== null) {
            $toDel = [];
            foreach (self::$pendingDeliverTable as $pendingKey => $row) {
                if (trim((string) $row['session_id']) === $sessionId) {
                    $data = json_decode((string) $row['payload'], true);
                    if (is_array($data)) {
                        self::writeSse($response, $data);
                        $pendingCount++;
                    }
                    $toDel[] = $pendingKey;
                }
            }
            foreach ($toDel as $k) {
                self::$pendingDeliverTable->del($k);
            }
        }

        if (self::drainRedisQueueForSession($sessionId, $response)) {
            self::closeSession($sessionId, $response);
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
            foreach (self::$queues[$sessionId] as $data) {
                self::writeSse($response, $data);
            }
            unset(self::$queues[$sessionId]);
            self::writeSse($response, [
                'event'  => 'close',
                'type'   => 'done',
                'close'  => true,
                'live'   => false,
                'reason' => 'drain_complete',
            ]);
            self::closeSession($sessionId, $response);
            return;
        }

        $enableDemoStream = filter_var((string) (\getenv('APP_DEBUG') ?: ''), FILTER_VALIDATE_BOOLEAN);
        if ($demoStream !== '' && $enableDemoStream) {
            self::startDemoStreamProducer($sessionId, $demoStream);
        }

        // Trigger deferred block streaming if deferred_request_id is present
        $header = is_array($request->header) ? $request->header : [];
        $lastEventId = $header['last-event-id'] ?? null;
        if ($deferredRequestId !== '') {
            $bindToken = self::getSsrBindToken($request);
            if (!\Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry::matchesBindToken($deferredRequestId, $bindToken)) {
                self::writeSse($response, [
                    'type' => 'done',
                    'live' => false,
                    'close' => true,
                    'reconnect' => false,
                ]);
                self::closeSession($sessionId, $response);
                return;
            }
            self::triggerDeferredBlocks(
                $sessionId,
                $deferredRequestId,
                $lastEventId,
                self::canUsePersistentDeferredSse($request),
                $resolvedMode === self::TRANSPORT_MODE_LIVE,
            );
        }

        self::runHeldOpenLoop($sessionId, $request, $response, $authenticatedUserId);

        self::closeSession($sessionId, $response);
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
        while (!$closed && isset(self::$sessions[$sessionId])) {
            // Hard connection-age cap — bounds hanging-connection attacks.
            if ($maxAgeSeconds > 0 && (time() - $connectionStartedAt) >= $maxAgeSeconds) {
                self::writeSse($response, ['event' => 'close', 'reason' => 'max_age', 'close' => true]);
                break;
            }

            if ((time() - $lastAuthTouchAt) >= self::AUTH_SESSION_TOUCH_INTERVAL_SECONDS) {
                $authenticatedUserId = self::refreshAuthenticatedSessionMapping($request, $sessionId, $authenticatedUserId);
                self::touchActiveSession($sessionId);
                $lastAuthTouchAt = time();
            }

            while (isset(self::$queues[$sessionId]) && self::$queues[$sessionId] !== []) {
                $data = array_shift(self::$queues[$sessionId]);

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

            if (!$closed && self::$deliverTable !== null && self::$httpServer !== null) {
                $currentWorkerId = self::getCurrentWorkerId();
                $toDel = [];
                $deliverCount = 0;
                foreach (self::$deliverTable as $deliverKey => $row) {
                    if ((int) $row['worker_id'] === $currentWorkerId && trim((string) $row['session_id']) === $sessionId) {
                        $data = json_decode((string) $row['payload'], true);
                        if (!is_array($data)) {
                            continue;
                        }

                        // Track R · R4 — catch a control marker on the cross-worker
                        // Swoole-table fallback (no-Redis path). The owning worker
                        // W self-selects via the worker_id match above, so the
                        // tier-2 context resolves locally here.
                        $ctrl = self::handleControlFrame($sessionId, $response, $data);
                        if ($ctrl === self::CTRL_HANDLED_CLOSE) {
                            $toDel[] = $deliverKey;
                            $closed = true;
                            break;
                        }
                        if ($ctrl === self::CTRL_HANDLED_CONTINUE) {
                            $toDel[] = $deliverKey;
                            $deliverCount++;
                            $lastWriteAt = time();
                            continue;
                        }

                        if (self::writeSse($response, $data)) {
                            $toDel[] = $deliverKey;
                            $deliverCount++;
                            $lastWriteAt = time();
                            if (self::shouldCloseAfterPayload($data)) {
                                $closed = true;
                                break;
                            }
                        }
                    }
                }
                foreach ($toDel as $k) {
                    self::$deliverTable->del($k);
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
        // kiss, so it is accounted the same way.
        $clientIp = self::resolveClientIp($request);
        $maxPerIp = self::envInt('SSE_MAX_CONN_PER_IP', self::DEFAULT_MAX_CONN_PER_IP);
        $maxGlobal = self::envInt('SSE_MAX_CONN_GLOBAL', self::DEFAULT_MAX_CONN_GLOBAL);

        if (array_sum(self::$ipConnections) >= $maxGlobal) {
            self::rejectTooManyRequests($response, 'SSE connection cap reached for this worker.');
            return;
        }
        if ($clientIp !== '' && ((self::$ipConnections[$clientIp] ?? 0) >= $maxPerIp)) {
            self::rejectTooManyRequests($response, 'SSE connection cap reached for your IP.');
            return;
        }
        if ($clientIp !== '') {
            self::$ipConnections[$clientIp] = (self::$ipConnections[$clientIp] ?? 0) + 1;
            self::$sessionIps[self::sessionConnectionKey($sessionId, $response)] = $clientIp;
        }

        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        self::$sessions[$sessionId] = [
            'response' => $response,
            'connected_at' => time(),
        ];
        if (!isset(self::$queues[$sessionId])) {
            self::$queues[$sessionId] = [];
        }
        if (self::$sessionWorkerTable !== null && self::$httpServer !== null) {
            self::$sessionWorkerTable->set(
                self::sessionTableKey($sessionId),
                ['worker_id' => self::getCurrentWorkerId()],
            );
        }
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
        // matches the re-run frame shape byte-for-byte.
        self::writeSse($response, $initialFrameData);

        // Consumer-half launch (R5 · first production caller). Populates both tiers
        // and drives R3 subscribe-on-first; the tier-2 ReRunContext is keyed by
        // streaming_id, the key R4 resolves a cross-worker control back to.
        $coordinator = self::$connectCoordinator;
        $streamingId = $record?->streamingId ?? $sessionId;
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
                    if (self::isCoroutineCancellation($e)) {
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
        if (self::$deferredBlockOrchestrator === null) {
            throw new \RuntimeException('DeferredBlockOrchestrator is not wired for AsyncResourceSseServer.');
        }

        return self::$deferredBlockOrchestrator;
    }

    private static function drainRedisQueueForSession(string $sessionId, Response $response): bool
    {
        $pool = self::getRedisPool();
        if ($pool === null) {
            return false;
        }

        while (true) {
            try {
                $raw = $pool->withConnection(static function ($redis) use ($sessionId): ?string {
                    /** @var Client $redis */
                    $value = $redis->lpop(self::redisSessionQueueKey($sessionId));
                    return is_string($value) && $value !== '' ? $value : null;
                });
            } catch (\Throwable $e) {
                \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Redis SSE dequeue failed', [
                    'session_id' => $sessionId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                return false;
            }

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
                try {
                    $pool->withConnection(static function ($redis) use ($sessionId, $raw): void {
                        /** @var Client $redis */
                        $queueKey = self::redisSessionQueueKey($sessionId);
                        $redis->rpush($queueKey, [$raw]);
                        $redis->expire($queueKey, self::REDIS_SESSION_QUEUE_TTL_SECONDS);
                    });
                } catch (\Throwable $e) {
                    \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Redis SSE requeue failed', [
                        'session_id' => $sessionId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
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
        return self::$transport ??= new SwooleSseTransport();
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
        if ($intervalSeconds <= 0) {
            return false;
        }

        return ($now - $lastWriteAt) >= $intervalSeconds;
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
    private static function encodeSessionQueueForRedis(array $queue): array
    {
        $encoded = [];
        foreach ($queue as $data) {
            if (!is_array($data)) {
                continue;
            }
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($payload)) {
                $encoded[] = $payload;
            }
        }

        return $encoded;
    }

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
        $encoded = self::encodeSessionQueueForRedis($payloads);
        if ($encoded === []) {
            return;
        }

        $pool = self::getRedisPool();
        if ($pool === null) {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId, $encoded): void {
                /** @var Client $redis */
                $queueKey = self::redisSessionQueueKey($sessionId);
                $redis->rpush($queueKey, $encoded);
                $redis->expire($queueKey, self::REDIS_SESSION_QUEUE_TTL_SECONDS);
            });
        } catch (\Throwable $e) {
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Redis SSE durability requeue failed', [
                'session_id' => $sessionId,
                'count' => count($encoded),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
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
     * Behaviour of the resolution step (unchanged):
     *
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
        [$resolvedEventName, $data] = self::resolveSseEventName($data);

        return SseFrame::fromResolved(
            isset($data['id']) ? (string) $data['id'] : null,
            $resolvedEventName,
            $data,
        );
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
    private static function resolveSseEventName(array $data): array
    {
        $rawType = $data['_type'] ?? null;
        if (is_string($rawType) && $rawType !== '') {
            if (\Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType::isAllowed($rawType)) {
                return [$rawType, $data];
            }

            \Semitexa\Core\Log\StaticLoggerBridge::warning('ssr', 'ui_sse_unknown_type_dropped', [
                'type' => $rawType,
            ]);
            unset($data['_type']);
            return [null, $data];
        } elseif (array_key_exists('_type', $data)) {
            // `_type` was present but non-string or empty → treat as malformed.
            // Strip it so the wire shape stays clean; do not emit `event:`.
            unset($data['_type']);
            return [null, $data];
        }

        $legacyEvent = $data['event'] ?? null;
        if (is_string($legacyEvent) && $legacyEvent !== '') {
            return [$legacyEvent, $data];
        }

        return [null, $data];
    }

    private static function startDemoStreamProducer(string $sessionId, string $demoStream): void
    {
        if ($demoStream !== 'showcase') {
            return;
        }

        if (isset(self::$demoProducers[$sessionId])) {
            return;
        }

        self::$demoProducers[$sessionId] = true;

        $producer = static function () use ($sessionId): void {
            \Swoole\Coroutine::sleep(0.35);

            if (!isset(self::$sessions[$sessionId])) {
                unset(self::$demoProducers[$sessionId]);
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

            while (isset(self::$sessions[$sessionId])) {
                $now = microtime(true);
                $sleepSeconds = 60 - fmod($now, 60.0);

                if ($sleepSeconds < 0.05) {
                    $sleepSeconds += 60.0;
                }

                \Swoole\Coroutine::sleep($sleepSeconds);

                if (!isset(self::$sessions[$sessionId])) {
                    unset(self::$demoProducers[$sessionId]);
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

            unset(self::$demoProducers[$sessionId]);
        };

        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            self::createSessionCoroutine($producer, $sessionId);
            return;
        }

        unset(self::$demoProducers[$sessionId]);
    }

    private static function shouldCloseAfterPayload(array $data): bool
    {
        if (($data['type'] ?? null) !== 'done') {
            return false;
        }

        if (($data['close'] ?? false) === true) {
            return true;
        }

        return ($data['live'] ?? false) !== true;
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

        $currentWorkerId = self::getCurrentWorkerId();

        // Same worker has the SSE connection: add to local queue
        if (isset(self::$sessions[$sessionId])) {
            if (!isset(self::$queues[$sessionId])) {
                self::$queues[$sessionId] = [];
            }
            self::$queues[$sessionId][] = $data;
            return;
        }

        // Cross-worker / cross-server: use Redis queue when available.
        $pool = self::getRedisPool();
        if ($pool !== null) {
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($payload)) {
                try {
                    $pool->withConnection(static function ($redis) use ($sessionId, $payload): void {
                        /** @var Client $redis */
                        $queueKey = self::redisSessionQueueKey($sessionId);
                        $redis->rpush($queueKey, [$payload]);
                        $redis->expire($queueKey, self::REDIS_SESSION_QUEUE_TTL_SECONDS);
                    });
                    return;
                } catch (\Throwable $e) {
                    \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Redis SSE enqueue failed', [
                        'session_id' => $sessionId,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Fallback: Swoole Tables (single server only)
        if (self::$sessionWorkerTable !== null && self::$deliverTable !== null && self::$httpServer !== null) {
            $row = self::$sessionWorkerTable->get(self::sessionTableKey($sessionId));
            if ($row !== false) {
                $targetWorkerId = (int) $row['worker_id'];
                if ($targetWorkerId !== $currentWorkerId) {
                    $deliverKey = uniqid('d_', true);
                    self::$deliverTable->set($deliverKey, [
                        'session_id' => $sessionId,
                        'worker_id' => $targetWorkerId,
                        'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                    return;
                }
            }
        }
        if (self::$pendingDeliverTable !== null) {
            $key = uniqid('p_', true);
            self::$pendingDeliverTable->set($key, [
                'session_id' => $sessionId,
                'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return;
        }
        if (!isset(self::$buffer[$sessionId])) {
            self::$buffer[$sessionId] = [];
        }
        self::$buffer[$sessionId][] = $data;
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

        if (isset(self::$sessions[$sessionId])) {
            return true;
        }

        if (self::$sessionWorkerTable !== null && self::$sessionWorkerTable->get(self::sessionTableKey($sessionId)) !== false) {
            return true;
        }

        $pool = self::getRedisPool();
        if ($pool !== null) {
            try {
                $isActive = $pool->withConnection(static function ($redis) use ($sessionId): bool {
                    /** @var Client $redis */
                    return (string) ($redis->get(self::redisActiveSessionKey($sessionId)) ?? '') === '1';
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
        if (!class_exists(\Swoole\Coroutine::class, false) || \Swoole\Coroutine::getCid() < 0) {
            $callback();
            return false;
        }

        /** @var int|false $result */
        $result = \Swoole\Coroutine::create(static function () use ($callback, $sessionId): void {
            $cid = self::currentCid();
            if ($cid >= 0) {
                self::$sessionCoroutines[$sessionId][$cid] = true;
            }

            try {
                $callback();
            } catch (\Throwable $e) {
                if (!self::isCoroutineCancellation($e)) {
                    throw $e;
                }
            } finally {
                $cid = self::currentCid();
                if ($cid >= 0 && isset(self::$sessionCoroutines[$sessionId][$cid])) {
                    unset(self::$sessionCoroutines[$sessionId][$cid]);
                    if (self::$sessionCoroutines[$sessionId] === []) {
                        unset(self::$sessionCoroutines[$sessionId]);
                    }
                }
            }
        });

        return $result;
    }

    public static function setServer(\Swoole\Http\Server $server): void
    {
        self::$httpServer = $server;
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
        $index = [];
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $index[$path] = true;
            }
        }
        self::$sseServedPaths = $index;
    }

    public static function setTables(
        \Swoole\Table $sessionWorkerTable,
        \Swoole\Table $deliverTable,
        ?\Swoole\Table $pendingDeliverTable = null,
    ): void
    {
        self::$sessionWorkerTable = $sessionWorkerTable;
        self::$deliverTable = $deliverTable;
        self::$pendingDeliverTable = $pendingDeliverTable;
    }

    public static function setDeferredBlockOrchestrator(?DeferredBlockOrchestrator $orchestrator): void
    {
        self::$deferredBlockOrchestrator = $orchestrator;
    }

    /**
     * Track R · R4 — wire the core re-run unit (R2) the loop branch calls when it
     * catches a `{__ctrl:rerun}` control. Live binding is the dispatcher-wiring
     * brick / R8; until then it stays null and a control is a safe no-op.
     */
    public static function setReRunner(?ReRunnerInterface $reRunner): void
    {
        self::$reRunner = $reRunner;
    }

    /**
     * Track R · R4 — wire the cross-worker re-run coalescer (R3) whose pending
     * mark the loop branch CLEARS after handling a control, re-arming the next
     * mutation's signal (the bounded-coalescing window). The shared table is
     * created pre-fork by R5's {@see CreateTrackRTablesListener}.
     */
    public static function setRerunCoalescer(?RerunCoalescer $coalescer): void
    {
        self::$rerunCoalescer = $coalescer;
    }

    /**
     * Track R · Intended Grid Model · Phase 2 (C2) — wire the cross-worker
     * view-change coalescer the command intake ({@see submitViewChange()}) and the
     * loop branch ({@see handleControlFrame()}) share. The shared table is created
     * pre-fork by R5's {@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\CreateTrackRTablesListener}.
     */
    public static function setViewChangeCoalescer(?ViewChangeCoalescer $coalescer): void
    {
        self::$viewChangeCoalescer = $coalescer;
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
    public static function submitViewChange(string $sessionId, array $params): bool
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || preg_match(self::SAFE_BEARER_SESSION_ID_PATTERN, $sessionId) !== 1) {
            return false;
        }

        // streaming_id == session_id for the held-open own-route stream (one stream
        // per connection), so the command — which only knows the session id — can
        // address the tier-2 re-run state directly.
        if (self::$viewChangeCoalescer === null) {
            // Fallback: no coalescer wired — carry params inline, uncoalesced.
            self::deliver($sessionId, [
                self::CTRL_KEY => self::CTRL_VIEWCHANGE,
                'streaming_id' => $sessionId,
                'params' => $params,
            ]);

            return true;
        }

        // Store the latest view + gate the enqueue. Only the 0→1 command enqueues a
        // (param-less) control; the owner reads the LATEST params from the coalescer
        // when it drains, so a coalesced burst re-queries the final view.
        if (self::$viewChangeCoalescer->submit($sessionId, $params)) {
            self::deliver($sessionId, [
                self::CTRL_KEY => self::CTRL_VIEWCHANGE,
                'streaming_id' => $sessionId,
            ]);
        }

        return true;
    }

    /**
     * Track R · R8c (C2) — wire the per-worker connect coordinator (R5) the
     * held-open resource stream drives. Set live by
     * {@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\WireTrackRConsumerListener}.
     */
    public static function setConnectCoordinator(?ConnectCoordinator $coordinator): void
    {
        self::$connectCoordinator = $coordinator;
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
        if (self::currentCid() >= 0) {
            $ctx = \Swoole\Coroutine::getContext();
            if ($ctx !== null) {
                return ((int) ($ctx[self::RERUN_SCOPE_CONTEXT_KEY] ?? 0)) > 0;
            }
        }

        return self::$reRunDepthFallback > 0;
    }

    private static function beginReRunScope(): void
    {
        if (self::currentCid() >= 0) {
            $ctx = \Swoole\Coroutine::getContext();
            if ($ctx !== null) {
                $ctx[self::RERUN_SCOPE_CONTEXT_KEY] = ((int) ($ctx[self::RERUN_SCOPE_CONTEXT_KEY] ?? 0)) + 1;
                return;
            }
        }

        self::$reRunDepthFallback++;
    }

    private static function endReRunScope(): void
    {
        if (self::currentCid() >= 0) {
            $ctx = \Swoole\Coroutine::getContext();
            if ($ctx !== null) {
                $depth = ((int) ($ctx[self::RERUN_SCOPE_CONTEXT_KEY] ?? 0)) - 1;
                $ctx[self::RERUN_SCOPE_CONTEXT_KEY] = $depth > 0 ? $depth : 0;
                return;
            }
        }

        if (self::$reRunDepthFallback > 0) {
            self::$reRunDepthFallback--;
        }
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
        $kind = $data[self::CTRL_KEY] ?? null;

        // A mutation-driven re-run (R4): re-run the cached DTO verbatim (no override).
        if ($kind === self::CTRL_RERUN) {
            return self::handleReRunControl($sessionId, $response, $data);
        }

        // A view-change command (Phase 2): re-run with a FILTER-ONLY param override
        // (the new page / limit / sort / filter), pushing the fresh frame on the
        // SAME open fd. The override is applied marker-gated in core's reExecute —
        // identity is never overridable here (the R2 anti-poisoning invariant).
        if ($kind === self::CTRL_VIEWCHANGE) {
            return self::handleViewChangeControl($sessionId, $response, $data);
        }

        return self::CTRL_NOT_CONTROL;
    }

    /**
     * Track R · R4 — the `{__ctrl:rerun}` branch (mutation-driven re-run). Resolves
     * the worker-local re-run state and re-runs the cached DTO verbatim ({@see
     * dispatchReRun()} with an empty override), then clears the R3 coalescer mark so
     * the next mutation's signal re-arms. Unchanged in behaviour from before Phase 2.
     *
     * @param array<string, mixed> $data
     */
    private static function handleReRunControl(string $sessionId, mixed $response, array $data): int
    {
        $streamingId = trim((string) ($data['streaming_id'] ?? ''));
        if ($streamingId === '') {
            // Malformed control — nothing to resolve. Consume it (never written).
            return self::CTRL_HANDLED_CONTINUE;
        }

        // Tier-2 resolve (R1, worker-local). A miss = this worker does not own
        // the stream (a non-owner drained the control) or the stream was torn
        // down: a missing context is nothing to re-run. Clear the mark + drop.
        // No crash, no re-run, no frame — the decisive cross-worker edge (§C3).
        // The re-run unit is wired live by R8; until then a control is a safe no-op.
        $context = SubscriptionDtoRegistry::get($streamingId);
        if ($context === null || self::$reRunner === null) {
            self::clearRerunPending($streamingId);
            return self::CTRL_HANDLED_CONTINUE;
        }

        $outcome = self::dispatchReRun($streamingId, $sessionId, $response, $context, []);

        // Clear the coalescer mark after handling (either outcome) so a later
        // mutation's signal can enqueue a fresh re-run — the bounded-coalescing
        // window (R3 sets the mark; R4 clears it). Idempotent with onDisconnect.
        self::clearRerunPending($streamingId);

        return $outcome;
    }

    /**
     * Track R · Intended Grid Model · Phase 2 — the `{__ctrl:viewchange}` branch.
     *
     * Reads the LATEST view params (last-write-wins, from the view-change coalescer;
     * the inline `params` is the no-coalescer fallback), resolves the worker-local
     * re-run state, and re-runs with that param override — pushing a fresh frame for
     * the new view on the SAME open fd. The override is applied FILTER-ONLY and
     * marker-gated in core ({@see \Semitexa\Core\Pipeline\ReRun\LiveFilterParamOverride}),
     * so a param targeting a non-`#[LiveFilterParam]` field (e.g. `sessionId` or any
     * identity-bearing field) is structurally IGNORED and identity still resolves
     * from the live session — the same anti-poisoning guarantee as the mutation path.
     *
     * Same safe-no-op edges as the re-run branch: a tier-2 miss (non-owner drained /
     * torn down) or an unwired re-runner consumes the control without a re-run.
     * {@see ViewChangeCoalescer::consume()} already cleared the pending mark, so the
     * next burst re-arms.
     *
     * @param array<string, mixed> $data
     */
    private static function handleViewChangeControl(string $sessionId, mixed $response, array $data): int
    {
        $streamingId = trim((string) ($data['streaming_id'] ?? ''));
        if ($streamingId === '') {
            return self::CTRL_HANDLED_CONTINUE;
        }

        // Latest-view params: the coalescer is the source of truth when wired (it
        // also clears the pending mark here, re-arming the next burst); the inline
        // payload params are the uncoalesced fallback when it is not.
        $override = self::$viewChangeCoalescer?->consume($streamingId);
        if ($override === null) {
            $inline = $data['params'] ?? null;
            $override = is_array($inline) ? $inline : [];
        }

        $context = SubscriptionDtoRegistry::get($streamingId);
        if ($context === null || self::$reRunner === null) {
            // Non-owner drained / stream torn down / re-runner unwired — safe no-op.
            return self::CTRL_HANDLED_CONTINUE;
        }

        return self::dispatchReRun($streamingId, $sessionId, $response, $context, $override);
    }

    /**
     * The shared re-run + frame-write tail for BOTH control kinds (R4 mutation and
     * Phase-2 view-change). Marks the coroutine as re-running (so the re-invoked
     * own-route handler produces a JSON body the loop frames, instead of grabbing
     * the live socket), runs the chain auth-first via R2 with the given override,
     * and writes the fresh frame on the open fd — or a close frame on TERMINATE
     * (lost access, §B.3). Coalescer pending bookkeeping is the caller's concern.
     *
     * @param array<string, mixed> $filterOverride empty for a mutation re-run; the
     *        new view params for a view-change (applied filter-only in core)
     */
    private static function dispatchReRun(
        string $streamingId,
        string $sessionId,
        mixed $response,
        ReRunContext $context,
        array $filterOverride,
    ): int {
        try {
            self::beginReRunScope();
            try {
                $result = self::$reRunner->reRun($context, $filterOverride);
            } finally {
                self::endReRunScope();
            }
        } catch (\Throwable $e) {
            // A re-run failure must neither leak data nor kill the stream — log and
            // keep the stream alive for the next signal.
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'track_r_rerun_failed', [
                'streaming_id' => $streamingId,
                'session_id' => $sessionId,
                'override' => $filterOverride === [] ? 'none' : array_keys($filterOverride),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return self::CTRL_HANDLED_CONTINUE;
        }

        if ($result->isTerminated()) {
            // Lost-access path (§B.3): the subject no longer has access. Emit a
            // close frame, NO data frame, and signal the loop to end the stream.
            self::writeControlClose($response, $result->getReason() ?? 'unauthorized');
            return self::CTRL_HANDLED_CLOSE;
        }

        $frame = $result->getFrame();
        if ($frame === null) {
            // Defensive: a non-terminated result with no frame — nothing to
            // write. (R2 never produces this; treat as a benign no-op.)
            return self::CTRL_HANDLED_CONTINUE;
        }

        // Write the freshly re-queried frame. The re-run re-queried the data
        // under the recipient's CURRENT authorization (and, for a view-change, the
        // new view), so this is fresh, not the stale cached value (the §C4 delete
        // edge: a re-run over a now-absent resource yields the handler's
        // empty/"gone" frame, written as-is — no crash, no stale data).
        if (!self::transport()->writeFrame($response, self::buildFrame(self::reRunFrameData($frame)))) {
            // Socket died writing the fresh frame — close the stream.
            return self::CTRL_HANDLED_CLOSE;
        }

        return self::CTRL_HANDLED_CONTINUE;
    }

    /**
     * Clear the per-stream coalescer pending mark, if the coalescer is wired.
     * No-op until R8 wires {@see setRerunCoalescer()}.
     */
    private static function clearRerunPending(string $streamingId): void
    {
        self::$rerunCoalescer?->clearPending($streamingId);
    }

    /**
     * Emit the close frame for a TERMINATEd re-run (lost access). A close frame,
     * never a data frame — the §B.3 guarantee that no data leaks to a
     * de-authorized subject. Goes straight through the transport (mirroring
     * {@see writeSse()}) so the branch stays Swoole-free and unit-testable.
     */
    private static function writeControlClose(mixed $response, string $reason): bool
    {
        return self::transport()->writeFrame($response, self::buildFrame([
            'event' => 'close',
            'reason' => $reason,
            'close' => true,
        ]));
    }

    /**
     * Map a re-run {@see HttpResponse} into the SSE wire-frame array
     * {@see buildFrame()} consumes. The handler composes a JSON body; decode it
     * so the frame envelope (incl. any `_type`) round-trips through the existing
     * event-name resolution. A non-JSON/non-array body is wrapped so a frame is
     * still emitted rather than dropped.
     *
     * @return array<array-key, mixed>
     */
    private static function reRunFrameData(HttpResponse $frame): array
    {
        $decoded = json_decode($frame->getContent(), true);

        return is_array($decoded) ? $decoded : ['data' => $frame->getContent()];
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

    private static function getCurrentWorkerId(): int
    {
        if (self::$httpServer === null) {
            return -1;
        }
        if (method_exists(self::$httpServer, 'getWorkerId')) {
            return (int) self::$httpServer->getWorkerId();
        }
        $workerId = self::$httpServer->worker_id ?? -1;
        return is_numeric($workerId) ? (int) $workerId : -1;
    }

    private static function sessionTableKey(string $sessionId): string
    {
        return strlen($sessionId) > 63 ? md5($sessionId) : $sessionId;
    }

    /**
     * Typed wrapper around \Swoole\Coroutine::getCid(). The Swoole stub PHPStan
     * sees returns mixed, but the runtime contract is int (>=0 inside a coroutine,
     * negative otherwise). Wrapping it once keeps the rest of this class type-safe.
     */
    private static function currentCid(): int
    {
        if (!class_exists(\Swoole\Coroutine::class, false)) {
            return -1;
        }
        $cid = \Swoole\Coroutine::getCid();
        return is_int($cid) ? $cid : -1;
    }

    private static function closeSession(string $sessionId, Response $response): void
    {
        // This handler owns the response lifecycle end-to-end. SseKissHandler
        // marks the framework ResourceResponse as alreadySent so the emitter
        // does not also call status/header/end — that double-end pattern
        // SIGSEGV'd Swoole 6.2.1 workers under server-initiated close.
        self::cancelSessionCoroutines($sessionId);
        self::removeSessionWorkerMapping($sessionId);
        self::unregisterAuthenticatedSession($sessionId);
        self::releaseIpConnection($sessionId, $response);
        // Durability: any payloads still queued for this connection (the
        // socket closed before the loop drained them) are flushed to the
        // Redis session queue so a reconnecting subscriber drains them via
        // drainRedisQueueForSession(). No-op when the queue is empty (the
        // normal drain / clean-close case) or when Redis is unavailable.
        if (isset(self::$queues[$sessionId]) && self::$queues[$sessionId] !== []) {
            self::requeueToRedis($sessionId, self::$queues[$sessionId]);
        }
        unset(self::$sessions[$sessionId], self::$queues[$sessionId], self::$demoProducers[$sessionId], self::$sessionCoroutines[$sessionId]);
        @$response->end();
    }

    private static function releaseIpConnection(string $sessionId, Response $response): void
    {
        $connectionKey = self::sessionConnectionKey($sessionId, $response);
        $ip = self::$sessionIps[$connectionKey] ?? '';
        if ($ip === '') {
            return;
        }

        if (isset(self::$ipConnections[$ip])) {
            self::$ipConnections[$ip]--;
            if (self::$ipConnections[$ip] <= 0) {
                unset(self::$ipConnections[$ip]);
            }
        }
        unset(self::$sessionIps[$connectionKey]);
    }

    private static function resolveClientIp(Request $request): string
    {
        $server = is_array($request->server) ? $request->server : [];
        $ip = trim((string) ($server['remote_addr'] ?? ''));

        return $ip !== '' ? strtolower($ip) : '';
    }

    /**
     * The resolved hard connection-age cap (`SSE_MAX_CONNECTION_AGE_SECONDS`,
     * default {@see self::DEFAULT_MAX_CONNECTION_AGE_SECONDS}; `0` disables the
     * loop's own cap). The held-open loop reads this to force-close + reap a stream
     * at the cap; the crashed-worker orphan sweeper
     * ({@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\ReapStaleSubscriptionsListener})
     * derives its cap+grace staleness threshold from the SAME value, so there is
     * one source of truth for the cap.
     */
    public static function maxConnectionAgeSeconds(): int
    {
        return self::envInt('SSE_MAX_CONNECTION_AGE_SECONDS', self::DEFAULT_MAX_CONNECTION_AGE_SECONDS);
    }

    private static function envInt(string $key, int $default): int
    {
        $rawValue = \getenv($key);
        $raw = trim($rawValue === false ? '' : (string) $rawValue);
        if ($raw === '') {
            return $default;
        }
        $parsed = filter_var($raw, FILTER_VALIDATE_INT);
        return is_int($parsed) && $parsed >= 0 ? $parsed : $default;
    }

    private static function sessionConnectionKey(string $sessionId, Response $response): string
    {
        return $sessionId . '#' . spl_object_id($response);
    }

    private static function rejectUnauthorized(Response $response, string $message): void
    {
        $response->status(401);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'error' => 'Unauthorized',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Resolve the admit error (if any) for an SSE request.
     *
     * `deferred_request_id` is normally guest-permissive: a deferred stream
     * runs its delivery then sends done/close, so guests may receive the
     * one-shot deferred drain without auth. But an explicit `mode=live`
     * request ($persistentRequested) asks the server to HOLD THE CONNECTION
     * OPEN past the deferred drain (DeferredBlockOrchestrator keepChannelOpen),
     * turning the deferred door into a persistent stream. The bind-token that
     * gates the deferred door is a request-binding held by every client that
     * loaded the deferred page — NOT an auth credential — so a persistent
     * request must independently satisfy the persistent-stream credential
     * check (authenticated, SSE_PUBLIC_ANONYMOUS, or a safe bearer-channel id)
     * regardless of deferred_request_id. Otherwise an anonymous, non-bearer
     * caller could obtain a long-lived stream through the deferred door.
     */
    private static function resolveSseAuthorizationError(
        bool $authenticated,
        bool $anonymousAllowed,
        string $demoStream,
        string $deferredRequestId,
        bool $safeBearerSessionId,
        bool $persistentRequested = false,
    ): ?string {
        if ($demoStream !== '' && !$authenticated) {
            return 'Authorization is required for this SSE demo stream.';
        }

        // The deferred door is only a bypass for the NON-persistent (drain)
        // case. A persistent (mode=live) request never gets the bypass.
        $deferredBypassesPersistentCheck = $deferredRequestId !== '' && !$persistentRequested;

        if (
            $demoStream === ''
            && !$deferredBypassesPersistentCheck
            && !$authenticated
            && !$anonymousAllowed
            && !$safeBearerSessionId
        ) {
            return 'Authorization is required for persistent SSE streams. Set SSE_PUBLIC_ANONYMOUS=true to opt in to anonymous persistent streams, or supply a safe-shaped subscriber channel id.';
        }

        return null;
    }

    /**
     * @return array{status: int, message: string}|null
     */
    private static function resolveSseRequestRejection(bool $sameOrigin, ?string $authError): ?array
    {
        if ($authError !== null) {
            return [
                'status' => 401,
                'message' => $authError,
            ];
        }

        if (!$sameOrigin) {
            return [
                'status' => 403,
                'message' => '',
            ];
        }

        return null;
    }

    private static function rejectTooManyRequests(Response $response, string $message): void
    {
        $response->status(429);
        $response->header('Content-Type', 'application/json');
        $response->header('Retry-After', '30');
        $response->end(json_encode([
            'error' => 'Too Many Requests',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function rejectBadRequest(Response $response, string $message): void
    {
        $response->status(400);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'error' => 'Bad Request',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Resolve the requested KISS transport mode against the admit context.
     *
     * Called once per admit, AFTER {@see self::resolveSseAuthorizationError()}
     * has already approved the request. Inputs are scalar so the resolver
     * can be unit-tested through reflection without standing up Swoole /
     * Redis / Sessions.
     *
     * Mode policy (only callers admitted by the auth gate reach here):
     *
     *   | rawMode | deferred | authed | anonAllow | bearer | result        |
     *   | ------- | -------- | ------ | --------- | ------ | ------------- |
     *   | drain   | *        | *      | *         | *      | drain         |
     *   | live    | *        | *      | *         | *      | live          |
     *   | ''      | yes      | *      | *         | *      | legacy        |
     *   | ''      | no       | yes    | *         | *      | legacy        |
     *   | ''      | no       | no     | yes       | *      | legacy        |
     *   | ''      | no       | no     | no        | yes    | drain ← key   |
     *   | other   | *        | *      | *         | *      | null (400)    |
     *
     * The anonymous-bearer + missing-mode → drain rule prevents a guest
     * page that forgets the mode marker from silently opening a long-
     * lived stream. Explicit unknown values are rejected so a typo
     * never silently degrades to legacy behaviour.
     *
     * @return self::TRANSPORT_MODE_DRAIN|self::TRANSPORT_MODE_LIVE|self::TRANSPORT_MODE_LEGACY|null
     *         `null` ⇒ explicit unknown mode → caller emits 400.
     */
    private static function resolveTransportMode(
        string $rawMode,
        bool $authenticated,
        bool $anonymousAllowed,
        bool $safeBearerSessionId,
        string $deferredRequestId,
    ): ?string {
        if ($rawMode === self::TRANSPORT_MODE_DRAIN) {
            return self::TRANSPORT_MODE_DRAIN;
        }
        if ($rawMode === self::TRANSPORT_MODE_LIVE) {
            return self::TRANSPORT_MODE_LIVE;
        }
        if ($rawMode === '') {
            if ($deferredRequestId !== '' || $authenticated || $anonymousAllowed) {
                return self::TRANSPORT_MODE_LEGACY;
            }
            if ($safeBearerSessionId) {
                return self::TRANSPORT_MODE_DRAIN;
            }
            // Defensive: the auth gate would have rejected this combination
            // before mode resolution. Treat conservatively as legacy.
            return self::TRANSPORT_MODE_LEGACY;
        }
        return null;
    }

    private static function cancelSessionCoroutines(string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || !isset(self::$sessionCoroutines[$sessionId])) {
            return;
        }

        $currentCid = self::currentCid();
        /** @var array<int, true> $cids */
        $cids = self::$sessionCoroutines[$sessionId];
        foreach (array_keys($cids) as $cid) {
            if ($cid < 0 || $cid === $currentCid) {
                continue;
            }
            try {
                self::cancelCoroutine($cid);
            } catch (\Throwable) {
                // Best-effort cancellation only.
            }
        }
    }

    private static function cancelCoroutine(int $cid): void
    {
        if (self::supportsSynchronousCoroutineCancel()) {
            // Second arg forces a synchronous cancel that throws inside the target
            // coroutine — without it, Coroutine::sleep() returns false but a tight
            // loop keeps running. The Swoole stub PHPStan sees omits this parameter.
            /** @phpstan-ignore-next-line arguments.count */
            \Swoole\Coroutine::cancel($cid, true);
            return;
        }

        \Swoole\Coroutine::cancel($cid);
    }

    private static function supportsSynchronousCoroutineCancel(): bool
    {
        static $supportsSyncCancel;
        if (is_bool($supportsSyncCancel)) {
            return $supportsSyncCancel;
        }

        try {
            $method = new \ReflectionMethod(\Swoole\Coroutine::class, 'cancel');
            $supportsSyncCancel = $method->getNumberOfParameters() >= 2;
        } catch (\ReflectionException) {
            $supportsSyncCancel = false;
        }

        return $supportsSyncCancel;
    }

    private static function isCoroutineCancellation(\Throwable $e): bool
    {
        $class = strtolower($e::class);
        $message = strtolower($e->getMessage());

        return str_contains($class, 'cancel') || str_contains($message, 'cancel');
    }

    private static function getSsrBindToken(Request $request): string
    {
        $cookieName = 'semitexa_ssr_bind';
        $cookie = is_array($request->cookie) ? $request->cookie : [];

        return trim((string) ($cookie[$cookieName] ?? ''));
    }

    private static function removeSessionWorkerMapping(string $sessionId): void
    {
        if (self::$sessionWorkerTable === null) {
            return;
        }

        self::$sessionWorkerTable->del(self::sessionTableKey($sessionId));
    }

    private static function registerAuthenticatedSession(string $sessionId, string $userId): void
    {
        $pool = self::getRedisPool();
        if ($pool === null) {
            return;
        }

        $sessionId = trim($sessionId);
        $userId = trim($userId);
        if ($sessionId === '' || $userId === '') {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId, $userId): void {
                /** @var Client $redis */
                $redis->sadd(self::REDIS_AUTH_ALL_SESSIONS_KEY, [$sessionId]);
                $redis->sadd(self::redisUserSessionsKey($userId), [$sessionId]);
                $redis->setex(self::redisSessionUserKey($sessionId), self::AUTH_SESSION_TTL_SECONDS, $userId);
                $redis->expire(self::REDIS_AUTH_ALL_SESSIONS_KEY, self::AUTH_SESSION_TTL_SECONDS);
                $redis->expire(self::redisUserSessionsKey($userId), self::AUTH_SESSION_TTL_SECONDS);
            });
        } catch (\Throwable $e) {
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Failed to register authenticated SSE session', [
                'session_id' => $sessionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function unregisterAuthenticatedSession(string $sessionId): void
    {
        $pool = self::getRedisPool();
        if ($pool === null) {
            return;
        }

        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId): void {
                /** @var Client $redis */
                $userId = trim((string) ($redis->get(self::redisSessionUserKey($sessionId)) ?? ''));
                if ($userId !== '') {
                    $redis->srem(self::redisUserSessionsKey($userId), $sessionId);
                }
                $redis->srem(self::REDIS_AUTH_ALL_SESSIONS_KEY, $sessionId);
                $redis->del(self::redisSessionUserKey($sessionId));
                $redis->del(self::redisActiveSessionKey($sessionId));
            });
        } catch (\Throwable $e) {
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Failed to unregister authenticated SSE session', [
                'session_id' => $sessionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function touchActiveSession(string $sessionId): void
    {
        $pool = self::getRedisPool();
        if ($pool === null) {
            return;
        }

        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($sessionId): void {
                /** @var Client $redis */
                $redis->setex(self::redisActiveSessionKey($sessionId), self::ACTIVE_SESSION_TTL_SECONDS, '1');
            });
        } catch (\Throwable $e) {
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Failed to touch active SSE session', [
                'session_id' => $sessionId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function refreshAuthenticatedSessionMapping(
        Request $request,
        string $sessionId,
        string $authenticatedUserId,
    ): string {
        $currentUserId = self::resolveAuthenticatedUserId($request);
        if ($currentUserId === '') {
            if ($authenticatedUserId !== '') {
                self::unregisterAuthenticatedSession($sessionId);
            }
            return '';
        }

        if ($authenticatedUserId !== '' && $currentUserId !== $authenticatedUserId) {
            self::unregisterAuthenticatedSession($sessionId);
        }

        self::registerAuthenticatedSession($sessionId, $currentUserId);

        return $currentUserId;
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
        return self::resolveAuthenticatedUserId($request) !== '';
    }

    private static function isSafeBearerSessionId(mixed $rawSessionId): bool
    {
        if (!is_string($rawSessionId) || $rawSessionId === '') {
            return false;
        }

        return preg_match(self::SAFE_BEARER_SESSION_ID_PATTERN, $rawSessionId) === 1;
    }

    private static function resolveAuthenticatedUserId(Request $request): string
    {
        $cookieName = Environment::getEnvValue('SESSION_COOKIE_NAME') ?? 'semitexa_session';
        $cookie = is_array($request->cookie) ? $request->cookie : [];
        $sessionValue = $cookie[$cookieName] ?? null;
        $sessionId = is_string($sessionValue) ? trim($sessionValue) : '';
        if ($sessionId === '' || !preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            return '';
        }

        try {
            $handler = self::createSessionHandler();
            $data = $handler->read($sessionId);
        } catch (\Throwable) {
            return '';
        }

        $userId = $data[self::AUTH_SESSION_USER_KEY] ?? null;
        return is_string($userId) ? trim($userId) : '';
    }

    private static function createSessionHandler(): SessionHandlerInterface
    {
        $pool = self::getRedisPool();
        if ($pool !== null) {
            return new RedisSessionHandler($pool);
        }

        return new SwooleTableSessionHandler();
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
    public static function publishScopeInvalidation(string $channel): void
    {
        $channel = trim($channel);
        if ($channel === '') {
            return;
        }

        $pool = self::getRedisPool();
        if ($pool === null) {
            return;
        }

        try {
            $pool->withConnection(static function ($redis) use ($channel): void {
                /** @var Client $redis */
                $redis->publish($channel, '');
            });
        } catch (\Throwable $e) {
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Redis SSE scope-invalidation publish failed', [
                'channel' => $channel,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function getRedisPool(): ?RedisConnectionPool
    {
        if (self::$redisPool instanceof RedisConnectionPool) {
            return self::$redisPool;
        }

        $redisHost = Environment::getEnvValue('REDIS_HOST');
        if ($redisHost === null || $redisHost === '') {
            return null;
        }

        self::$redisPool = new RedisConnectionPool(1, [
            'scheme' => (string) (Environment::getEnvValue('REDIS_SCHEME', 'tcp') ?? 'tcp'),
            'host' => $redisHost,
            'port' => (int) (Environment::getEnvValue('REDIS_PORT', '6379') ?? '6379'),
            'password' => (string) (Environment::getEnvValue('REDIS_PASSWORD', '') ?? ''),
        ]);

        return self::$redisPool;
    }

    /** @return list<string> */
    private static function getAuthenticatedUserSessionIds(string $userId): array
    {
        $pool = self::getRedisPool();
        if ($pool === null) {
            return [];
        }

        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        try {
            return $pool->withConnection(static function ($redis) use ($userId): array {
                /** @var Client $redis */
                $members = $redis->smembers(self::redisUserSessionsKey($userId));
                return self::filterActiveSessionIds($redis, array_values($members), $userId);
            });
        } catch (\Throwable $e) {
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Failed to get authenticated user session IDs', [
                'user_id' => $userId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** @return list<string> */
    private static function getAllAuthenticatedSessionIds(): array
    {
        $pool = self::getRedisPool();
        if ($pool === null) {
            return [];
        }

        try {
            return $pool->withConnection(static function ($redis): array {
                /** @var Client $redis */
                $members = $redis->smembers(self::REDIS_AUTH_ALL_SESSIONS_KEY);
                return self::filterActiveSessionIds($redis, array_values($members));
            });
        } catch (\Throwable $e) {
            \Semitexa\Core\Log\StaticLoggerBridge::error('ssr', 'Failed to get all authenticated session IDs', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @param list<mixed> $sessionIds
     * @return list<string>
     */
    private static function filterActiveSessionIds(mixed $redis, array $sessionIds, ?string $expectedUserId = null): array
    {
        $active = [];
        if (!$redis instanceof Client) {
            return $active;
        }

        foreach ($sessionIds as $rawSessionId) {
            if (!is_scalar($rawSessionId) && !$rawSessionId instanceof \Stringable) {
                continue;
            }

            $sessionId = trim((string) $rawSessionId);
            if ($sessionId === '') {
                continue;
            }

            $mappedUserId = trim((string) ($redis->get(self::redisSessionUserKey($sessionId)) ?? ''));
            $isActive = (string) ($redis->get(self::redisActiveSessionKey($sessionId)) ?? '') === '1';
            if (
                $mappedUserId === ''
                || !$isActive
                || ($expectedUserId !== null && $mappedUserId !== $expectedUserId)
            ) {
                $redis->srem(self::REDIS_AUTH_ALL_SESSIONS_KEY, $sessionId);
                if ($expectedUserId !== null) {
                    $redis->srem(self::redisUserSessionsKey($expectedUserId), $sessionId);
                } elseif ($mappedUserId !== '') {
                    $redis->srem(self::redisUserSessionsKey($mappedUserId), $sessionId);
                }
                $redis->del(self::redisSessionUserKey($sessionId));
                $redis->del(self::redisActiveSessionKey($sessionId));
                continue;
            }

            $active[] = $sessionId;
        }

        return $active;
    }

    private static function redisUserSessionsKey(string $userId): string
    {
        return self::REDIS_AUTH_USER_SESSIONS_PREFIX . trim($userId);
    }

    private static function redisSessionUserKey(string $sessionId): string
    {
        return self::REDIS_AUTH_SESSION_USER_PREFIX . trim($sessionId);
    }

    private static function redisSessionQueueKey(string $sessionId): string
    {
        return self::REDIS_SESSION_QUEUE_PREFIX . trim($sessionId);
    }

    private static function redisActiveSessionKey(string $sessionId): string
    {
        return self::REDIS_ACTIVE_SESSION_PREFIX . trim($sessionId);
    }

    private static function isSameOriginRequest(Request $request): bool
    {
        $header = [];
        if (is_array($request->header)) {
            foreach ($request->header as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $header[strtolower($key)] = (string) $value;
                }
            }
        }

        // Fail closed: Host is required to compare against.
        $host = trim($header['host'] ?? '');
        if ($host === '') {
            return false;
        }

        // Fail closed: at least one of Origin/Referer must be present AND match.
        // Browser-originated EventSource always sends Origin; any request without
        // either header is treated as cross-origin/untrusted.
        $matched = false;
        foreach (['origin', 'referer'] as $headerName) {
            $value = trim($header[$headerName] ?? '');
            if ($value === '') {
                continue;
            }

            $requestHost = parse_url($value, PHP_URL_HOST);
            if (!is_string($requestHost) || $requestHost === '') {
                return false;
            }

            $requestPort = parse_url($value, PHP_URL_PORT);
            $normalizedHost = strtolower($host);
            $normalizedRequestHost = strtolower($requestHost . ($requestPort !== null ? ':' . $requestPort : ''));

            if ($normalizedRequestHost !== $normalizedHost && strtolower($requestHost) !== $normalizedHost) {
                return false;
            }

            $matched = true;
        }

        return $matched;
    }
}
