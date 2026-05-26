<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Isomorphic;

use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Domain\Exception\DeferredRenderingException;
use Swoole\Table;
use Swoole\Timer;

final class DeferredRequestRegistry
{
    private static ?Table $table = null;
    private static int $gcTimerId = 0;
    private static int $contextColumnSize = 0;
    private static int $snapshotColumnSize = 0;
    private static ?\Swoole\Lock $deliveredLock = null;

    private const TTL_SECONDS = 120;
    private const GC_INTERVAL_SECONDS = 60;
    private const MAX_ENTRIES = 4096;

    /**
     * Creates and returns a shared Swoole Table for cross-worker use.
     *
     * This must be called BEFORE Swoole forks workers (i.e., before Server::start()).
     * The returned table is shared across all workers via mmap. Pass it to each worker
     * via setTable() inside the WorkerStart callback.
     */
    public static function createSharedTable(IsomorphicConfig $config): Table
    {
        $table = new Table(self::MAX_ENTRIES);
        $table->column('page_handle', Table::TYPE_STRING, 128);
        $table->column('page_context', Table::TYPE_STRING, $config->deferredContextSize);
        $table->column('bind_token', Table::TYPE_STRING, 64);
        $table->column('locale', Table::TYPE_STRING, 16);
        $table->column('slots', Table::TYPE_STRING, 2048);
        $table->column('components', Table::TYPE_STRING, $config->deferredContextSize);
        $table->column('delivered', Table::TYPE_STRING, 2048);
        $table->column('request_snapshot', Table::TYPE_STRING, $config->requestSnapshotSize);
        $table->column('created_at', Table::TYPE_INT);
        $table->create();
        return $table;
    }

    /**
     * Injects an externally-created (shared) Swoole Table.
     *
     * Call this in WorkerStart after the table was created pre-fork via createSharedTable().
     */
    public static function setTable(Table $table): void
    {
        self::$table = $table;
    }

    public static function initialize(?IsomorphicConfig $config = null): void
    {
        $config ??= IsomorphicConfig::fromEnvironment();
        $hasInjectedSharedTable = self::$table !== null;

        self::$contextColumnSize = $config->deferredContextSize;
        self::$snapshotColumnSize = $config->requestSnapshotSize;

        // If the table was pre-created and injected via setTable() (Swoole multi-worker path),
        // skip table creation — use the already-shared table.
        if (self::$table === null) {
            self::$table = self::createSharedTable($config);
        }

        if (self::$deliveredLock === null && class_exists(\Swoole\Lock::class, false)) {
            $lockType = \defined('SWOOLE_MUTEX') ? SWOOLE_MUTEX : 2;
            self::$deliveredLock = new \Swoole\Lock($lockType);
        }

        // Only schedule the GC timer when there is an event loop to run it.
        // In CLI (queue worker, console, PHPUnit) registering a Timer leaves
        // the Swoole reactor with pending events at PHP shutdown, which fires
        // the `swoole_event_rshutdown(): Event::wait() in shutdown function
        // is deprecated` notice. GC still runs lazily on table writes via the
        // TTL check inside consume(), so we lose nothing here.
        if (($hasInjectedSharedTable || PHP_SAPI !== 'cli')
            && self::$gcTimerId === 0
            && class_exists(Timer::class, false)
        ) {
            self::$gcTimerId = Timer::tick(self::GC_INTERVAL_SECONDS * 1000, static function (): void {
                self::gc();
            });
        }
    }

    public static function store(
        string $requestId,
        string $pageHandle,
        array $pageContext,
        array $slotIds,
        string $bindToken = '',
        string $locale = '',
    ): void {
        if (self::$table === null) {
            $config = IsomorphicConfig::fromEnvironment();
            if (!$config->enabled) {
                return;
            }
            self::initialize($config);
        }

        $pageContext = self::sanitizeContext($pageContext);
        self::validateContext($pageContext);

        // Capture the originating page's canonical platform-ui SSE session
        // id (the live `sub` channel minted by ui_page_sse_session_meta() /
        // the page resource) so the deferred-render pipeline can RESTORE it
        // before rendering deferred components. Without this, a deferred
        // component renders in a separate `/__semitexa_kiss` request where
        // PlatformUiSseSessionState was reset, mints a FRESH session id, and
        // bakes it into its `sub` claim — so the dispatcher publishes frames
        // to a channel no live EventSource subscribes to. Soft dependency
        // via class_exists, mirroring how the orchestrator applies locale.
        // Skipped when platform-ui is absent or the page minted no session.
        //
        // TODO(known-limitation): in the LayoutRenderer path store() runs
        // BEFORE the page template renders, so current() is only set here if
        // the session was minted earlier in the request (the standard
        // pattern: the page handler/resource calls mintIfAbsent()). A page
        // that mints its session ONLY via the in-template
        // ui_page_sse_session_meta() helper AND defers a component would not
        // be captured. If such a page appears, hoist the session mint into
        // its resource, or capture post-render alongside storeComponentInstances().
        if (!array_key_exists('__ui_sse_session', $pageContext)
            && class_exists(\Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState::class)
        ) {
            $uiSseSession = \Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState::current();
            if (is_string($uiSseSession) && $uiSseSession !== '') {
                $pageContext['__ui_sse_session'] = $uiSseSession;
            }
        }

        try {
            $serializedContext = json_encode(
                $pageContext,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new DeferredRenderingException(
                'Failed to serialize page context: ' . $e->getMessage()
            );
        }
        $contextColumnSize = self::$contextColumnSize > 0 ? self::$contextColumnSize : 8192;

        if (strlen($serializedContext) > $contextColumnSize) {
            throw new DeferredRenderingException(
                "Serialized page context exceeds configured SSR_DEFERRED_CONTEXT_SIZE ({$contextColumnSize} bytes). "
                . 'Increase SSR_DEFERRED_CONTEXT_SIZE or reduce context payload.'
            );
        }

        $key = self::tableKey($requestId);
        try {
            $slotsJson = json_encode($slotIds, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $componentsJson = json_encode([], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $deliveredJson = json_encode([], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DeferredRenderingException(
                'Failed to serialize deferred request slots: ' . $e->getMessage()
            );
        }

        $ok = self::$table->set($key, [
            'page_handle' => $pageHandle,
            'page_context' => $serializedContext,
            'bind_token' => $bindToken,
            'locale' => $locale,
            'slots' => $slotsJson,
            'components' => $componentsJson,
            'delivered' => $deliveredJson,
            'request_snapshot' => '',
            'created_at' => time(),
        ]);
        if ($ok === false) {
            throw new DeferredRenderingException('Failed to store deferred request entry.');
        }
    }

    /**
     * Persist deferred component instances rendered during Twig execution.
     * Called by LayoutRenderer / HtmlResponse after the page renders so the orchestrator
     * can resolve each instance against ComponentRenderer + the recorded props.
     *
     * @param array<int, array{instance_id: string, name: string, props: array<array-key, mixed>}> $components
     */
    public static function storeComponentInstances(string $requestId, array $components): void
    {
        if (self::$table === null) {
            return;
        }

        $key = self::tableKey($requestId);
        $row = self::$table->get($key);
        if ($row === false) {
            return;
        }

        try {
            $componentsJson = json_encode(
                array_values($components),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new DeferredRenderingException(
                'Failed to serialize deferred component instances: ' . $e->getMessage()
            );
        }

        $contextColumnSize = self::$contextColumnSize > 0 ? self::$contextColumnSize : 8192;
        if (strlen($componentsJson) > $contextColumnSize) {
            throw new DeferredRenderingException(
                "Serialized deferred component instances exceed configured SSR_DEFERRED_CONTEXT_SIZE ({$contextColumnSize} bytes). "
                . 'Increase SSR_DEFERRED_CONTEXT_SIZE or reduce per-component props.'
            );
        }

        $ok = self::$table->set($key, [
            'page_handle' => $row['page_handle'],
            'page_context' => self::backfillUiSseSession((string) $row['page_context']),
            'bind_token' => $row['bind_token'] ?? '',
            'locale' => $row['locale'] ?? '',
            'slots' => $row['slots'],
            'components' => $componentsJson,
            'delivered' => $row['delivered'],
            'request_snapshot' => $row['request_snapshot'] ?? '',
            'created_at' => $row['created_at'],
        ]);
        if ($ok === false) {
            throw new DeferredRenderingException('Failed to update deferred component instances.');
        }
    }

    /**
     * Post-render backfill of the originating page's canonical live SSE session
     * id into the stored deferred page context.
     *
     * {@see self::store()} captures `__ui_sse_session` pre-render, but a page
     * that mints its session ONLY via the in-template ui_page_sse_session_meta()
     * helper has no {@see PlatformUiSseSessionState::current()} id at that point
     * (see the known-limitation note in store()). By the time the page has
     * rendered — this method's caller — the id IS set, so we backfill it here
     * so the orchestrator can restore the live `sub` channel for deferred
     * components. Soft dependency on platform-ui via class_exists, mirroring
     * store(). Returns the input untouched when platform-ui is absent, no
     * session was minted, the context already carries the key, or the JSON
     * cannot be decoded/re-encoded.
     */
    private static function backfillUiSseSession(string $serializedContext): string
    {
        $stateClass = \Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState::class;
        if (!class_exists($stateClass)) {
            return $serializedContext;
        }

        $current = $stateClass::current();
        if (!is_string($current) || $current === '') {
            return $serializedContext;
        }

        $decoded = json_decode($serializedContext, true);
        if (!is_array($decoded) || array_key_exists('__ui_sse_session', $decoded)) {
            return $serializedContext;
        }

        $decoded['__ui_sse_session'] = $current;

        try {
            return json_encode(
                $decoded,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return $serializedContext;
        }
    }

    /**
     * Capture an HTTP request snapshot for a deferred request, so DataProviders
     * resolving inside a child SSE coroutine can still read query params, route
     * params, method, and path even though CoroutineLocal does not propagate.
     *
     * @param array{query?: array<string, mixed>, route?: array<string, mixed>, method?: string, path?: string} $snapshot
     */
    public static function storeRequestSnapshot(string $requestId, array $snapshot): void
    {
        if (self::$table === null) {
            return;
        }

        $key = self::tableKey($requestId);
        $row = self::$table->get($key);
        if ($row === false) {
            return;
        }

        try {
            $snapshotJson = json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new DeferredRenderingException(
                'Failed to serialize request snapshot: ' . $e->getMessage()
            );
        }

        $snapshotColumnSize = self::$snapshotColumnSize > 0 ? self::$snapshotColumnSize : 4096;
        if (strlen($snapshotJson) > $snapshotColumnSize) {
            throw new DeferredRenderingException(
                "Serialized request snapshot exceeds configured SSR_REQUEST_SNAPSHOT_SIZE ({$snapshotColumnSize} bytes). "
                . 'Increase SSR_REQUEST_SNAPSHOT_SIZE or trim the captured request data.'
            );
        }

        $ok = self::$table->set($key, [
            'page_handle' => $row['page_handle'],
            'page_context' => $row['page_context'],
            'bind_token' => $row['bind_token'] ?? '',
            'locale' => $row['locale'] ?? '',
            'slots' => $row['slots'],
            'components' => $row['components'] ?? '[]',
            'delivered' => $row['delivered'],
            'request_snapshot' => $snapshotJson,
            'created_at' => $row['created_at'],
        ]);
        if ($ok === false) {
            throw new DeferredRenderingException('Failed to store request snapshot.');
        }
    }

    /**
     * @return array{query?: array<string, mixed>, route?: array<string, mixed>, method?: string, path?: string}|null
     */
    public static function getRequestSnapshot(string $requestId): ?array
    {
        if (self::$table === null) {
            return null;
        }

        $key = self::tableKey($requestId);
        $row = self::$table->get($key);
        if ($row === false) {
            return null;
        }

        $snapshot = (string) ($row['request_snapshot'] ?? '');
        if ($snapshot === '') {
            return null;
        }

        $decoded = json_decode($snapshot, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Build a request snapshot from the current Swoole request, if available.
     * Returns null when no Swoole request is bound to the current coroutine
     * (CLI, queue worker, tests).
     *
     * @return array{query: array<string, mixed>, route: array<string, mixed>, method: string, path: string}|null
     */
    public static function snapshotFromCurrentSwooleRequest(): ?array
    {
        if (!class_exists(\Semitexa\Core\Server\SwooleBootstrap::class, false)) {
            return null;
        }

        $ctx = \Semitexa\Core\Server\SwooleBootstrap::getCurrentSwooleRequestResponse();
        if ($ctx === null) {
            return null;
        }

        $req = $ctx[0];
        $get = is_array($req->get ?? null) ? $req->get : [];
        $server = is_array($req->server ?? null) ? $req->server : [];

        return [
            'query'  => $get,
            'route'  => [],
            'method' => (string) ($server['request_method'] ?? 'GET'),
            'path'   => (string) ($server['request_uri'] ?? '/'),
        ];
    }

    /**
     * Consume a deferred request entry. Returns null if not found or expired.
     *
     * @return array{page_handle: string, page_context: array, bind_token: string, locale: string, slots: string[], delivered: string[]}|null
     */
    public static function consume(string $requestId): ?array
    {
        if (self::$table === null) {
            return null;
        }

        $key = self::tableKey($requestId);
        $row = self::$table->get($key);

        if ($row === false) {
            return null;
        }
        /** @var array{created_at:mixed,page_handle:mixed,page_context:mixed,bind_token?:mixed,locale?:mixed,slots:mixed,delivered:mixed} $row */

        if ((time() - (int) $row['created_at']) > self::TTL_SECONDS) {
            self::$table->del($key);
            return null;
        }

        $snapshotJson = (string) ($row['request_snapshot'] ?? '');
        $snapshot = $snapshotJson !== '' ? json_decode($snapshotJson, true) : null;

        $componentsRaw = (string) ($row['components'] ?? '');
        $componentsDecoded = $componentsRaw !== '' ? json_decode($componentsRaw, true) : [];

        return [
            'page_handle' => trim((string) $row['page_handle']),
            'page_context' => json_decode((string) $row['page_context'], true) ?: [],
            'bind_token' => trim((string) ($row['bind_token'] ?? '')),
            'locale' => trim((string) ($row['locale'] ?? '')),
            'slots' => json_decode((string) $row['slots'], true) ?: [],
            'components' => is_array($componentsDecoded) ? $componentsDecoded : [],
            'delivered' => json_decode((string) $row['delivered'], true) ?: [],
            'request_snapshot' => is_array($snapshot) ? $snapshot : null,
        ];
    }

    public static function markDelivered(string $requestId, string $slotId): void
    {
        if (self::$table === null) {
            return;
        }

        $lock = self::$deliveredLock;
        if ($lock !== null) {
            $lock->lock();
        }
        try {
            $key = self::tableKey($requestId);
            $row = self::$table->get($key);

            if ($row === false) {
                return;
            }

            $delivered = json_decode((string) $row['delivered'], true) ?: [];
            if (!in_array($slotId, $delivered, true)) {
                $delivered[] = $slotId;
            }

            try {
                $deliveredJson = json_encode($delivered, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new DeferredRenderingException(
                    'Failed to serialize delivered slots: ' . $e->getMessage()
                );
            }

            $ok = self::$table->set($key, [
                'page_handle' => $row['page_handle'],
                'page_context' => $row['page_context'],
                'bind_token' => $row['bind_token'] ?? '',
                'locale' => $row['locale'] ?? '',
                'slots' => $row['slots'],
                'components' => $row['components'] ?? '[]',
                'delivered' => $deliveredJson,
                'request_snapshot' => $row['request_snapshot'] ?? '',
                'created_at' => $row['created_at'],
            ]);
            if ($ok === false) {
                throw new DeferredRenderingException('Failed to update deferred request entry.');
            }
        } finally {
            if ($lock !== null) {
                $lock->unlock();
            }
        }
    }

    /**
     * @param string[] $slotIds
     */
    public static function updateSlots(string $requestId, array $slotIds): void
    {
        if (self::$table === null) {
            return;
        }

        $key = self::tableKey($requestId);
        $row = self::$table->get($key);
        if ($row === false) {
            return;
        }
        /** @var array{page_handle:mixed,page_context:mixed,bind_token?:mixed,locale?:mixed,delivered:mixed,created_at:mixed} $row */

        $slotIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $slotId): string => trim((string) $slotId), $slotIds),
            static fn (string $slotId): bool => $slotId !== ''
        )));

        try {
            $slotsJson = json_encode($slotIds, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DeferredRenderingException(
                'Failed to serialize deferred request slots: ' . $e->getMessage()
            );
        }

        $ok = self::$table->set($key, [
            'page_handle' => $row['page_handle'],
            'page_context' => $row['page_context'],
            'bind_token' => $row['bind_token'] ?? '',
            'locale' => $row['locale'] ?? '',
            'slots' => $slotsJson,
            'components' => $row['components'] ?? '[]',
            'delivered' => $row['delivered'],
            'request_snapshot' => $row['request_snapshot'] ?? '',
            'created_at' => $row['created_at'],
        ]);
        if ($ok === false) {
            throw new DeferredRenderingException('Failed to update deferred request slots.');
        }
    }

    public static function matchesBindToken(string $requestId, string $bindToken): bool
    {
        if ($bindToken === '') {
            return false;
        }

        $entry = self::consume($requestId);
        if ($entry === null) {
            return false;
        }

        return hash_equals($entry['bind_token'], $bindToken);
    }

    public static function remove(string $requestId): void
    {
        self::$table?->del(self::tableKey($requestId));
    }

    public static function getTable(): ?Table
    {
        return self::$table;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $normalized = self::sanitizeValue($value);
            if ($normalized === self::unsupportedMarker()) {
                continue;
            }

            $sanitized[$key] = $normalized;
        }

        return $sanitized;
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return self::unsupportedMarker();
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $normalized = self::sanitizeValue($item);
            if ($normalized === self::unsupportedMarker()) {
                continue;
            }

            $sanitized[$key] = $normalized;
        }

        return $sanitized;
    }

    private static function unsupportedMarker(): object
    {
        static $marker;
        return $marker ??= new \stdClass();
    }

    private static function gc(): void
    {
        if (self::$table === null) {
            return;
        }

        $now = time();
        $toDelete = [];

        foreach (self::$table as $key => $row) {
            /** @var array{created_at:mixed} $row */
            if (($now - (int) $row['created_at']) > self::TTL_SECONDS) {
                $toDelete[] = $key;
            }
        }

        foreach ($toDelete as $key) {
            self::$table->del($key);
        }
    }

    private static function tableKey(string $requestId): string
    {
        return strlen($requestId) > 63 ? md5($requestId) : $requestId;
    }

    private static function validateContext(mixed $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw new DeferredRenderingException('Page context exceeds maximum nesting depth.');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::validateContext($item, $depth + 1);
            }
            return;
        }

        if (is_null($value) || is_scalar($value)) {
            return;
        }

        throw new DeferredRenderingException('Page context contains non-serializable values.');
    }

    public static function reset(): void
    {
        if (self::$gcTimerId > 0 && class_exists(Timer::class, false)) {
            Timer::clear(self::$gcTimerId);
            self::$gcTimerId = 0;
        }
        self::$deliveredLock = null;
        self::$contextColumnSize = 0;
        self::$snapshotColumnSize = 0;
        self::$table = null;
    }
}
