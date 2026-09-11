<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Isomorphic;

use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;
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
    /**
     * Apply a partial update to a row that must already exist.
     *
     * `Table::set()` CREATES a row on a key it does not have, and every writer
     * here checks the row first and writes second — so a `remove()`, or the
     * expired branch of `consume()`, landing between the two turns the write
     * into a resurrection: a row made of whichever columns this writer happened
     * to be changing and defaults for the other seven. Nobody takes a lock that
     * would prevent it; `$deliveredLock` is held by one writer out of four and
     * by neither deletion path.
     *
     * So the write is checked instead of guarded. A resurrected fragment has
     * `created_at` of 0 — the column is only ever written by {@see store()},
     * which writes `time()` — and that is both the tell and the damage: 0 makes
     * the row instantly expired, so it can never be delivered, and it would sit
     * in a fixed-size table holding a slot until something happened to read
     * that id again. Dropping it here costs one `get()` on a path that has just
     * written, and leaves the table exactly as the deletion intended.
     *
     * Returns false when the row is gone — the caller's update did not happen
     * and cannot, which is not the same as a failed write.
     *
     * @param array<string, mixed> $columns
     */
    private static function updateExistingRow(string $key, array $columns): bool
    {
        if (self::$table === null) {
            return false;
        }

        if (self::$table->set($key, $columns) === false) {
            return false;
        }

        $row = self::row($key);

        if ($row === null) {
            return false;
        }

        if ((int) ($row['created_at'] ?? 0) === 0) {
            self::$table->del($key);

            return false;
        }

        return true;
    }

    /**
     * One row, narrowed to the shape the rest of this class speaks.
     *
     * `Table::get()` is declared as returning `array|false`, which static
     * analysis reads as `array<mixed, mixed>` — so every call site passing the
     * result to {@see DeferredRequestRecord::fromRow()} or indexing it had to be
     * excused individually. A row's keys are the column names, strings by
     * construction, so the narrowing is stated once here instead of asserted at
     * four call sites.
     *
     * Null means "no such request" — consumed, expired, or never stored.
     *
     * @return array<string, mixed>|null
     */
    private static function row(string $key): ?array
    {
        $row = self::$table?->get($key);

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

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

        // Capture the originating page's canonical UI SSE session id (the live
        // `sub` channel minted by ui_page_sse_session_meta() / the page
        // resource) so the deferred-render pipeline can RESTORE it before
        // rendering deferred components. Without this, a deferred component
        // renders in a separate `/__semitexa_kiss` request where
        // UiSseSessionState was reset, mints a FRESH session id, and bakes it
        // into its `sub` claim — so the dispatcher publishes frames to a channel
        // no live EventSource subscribes to. Skipped when the page minted no
        // session.
        //
        // TODO(known-limitation): in the LayoutRenderer path store() runs
        // BEFORE the page template renders, so current() is only set here if
        // the session was minted earlier in the request (the standard
        // pattern: the page handler/resource calls mintIfAbsent()). A page
        // that mints its session ONLY via the in-template
        // ui_page_sse_session_meta() helper AND defers a component would not
        // be captured. If such a page appears, hoist the session mint into
        // its resource, or capture post-render alongside storeComponentInstances().
        if (!array_key_exists('__ui_sse_session', $pageContext)) {
            $uiSseSession = UiSseSessionState::current();
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
        $row = self::row($key);
        if ($row === null) {
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

        // Two columns, because two are what this changes. See the note on
        // markDelivered() for why the other seven are not listed here.
        if (!self::updateExistingRow($key, [
            'page_context' => self::backfillUiSseSession((string) $row['page_context']),
            'components' => $componentsJson,
        ])) {
            throw new DeferredRenderingException('Failed to update deferred component instances.');
        }
    }

    /**
     * Post-render backfill of the originating page's canonical live SSE session
     * id into the stored deferred page context.
     *
     * {@see self::store()} captures `__ui_sse_session` pre-render, but a page
     * that mints its session ONLY via the in-template ui_page_sse_session_meta()
     * helper has no {@see UiSseSessionState::current()} id at that point (see
     * the known-limitation note in store()). By the time the page has
     * rendered — this method's caller — the id IS set, so we backfill it here
     * so the orchestrator can restore the live `sub` channel for deferred
     * components. Returns the input untouched when no session was minted, the
     * context already carries the key, or the JSON cannot be decoded/re-encoded.
     */
    private static function backfillUiSseSession(string $serializedContext): string
    {
        $current = UiSseSessionState::current();
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

        // Existence check only — a set() on an unknown key would CREATE the row,
        // resurrecting a request that was consumed or expired.
        $key = self::tableKey($requestId);
        if (self::row($key) === null) {
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

        if (!self::updateExistingRow($key, ['request_snapshot' => $snapshotJson])) {
            throw new DeferredRenderingException('Failed to store request snapshot.');
        }
    }

    /**
     * The snapshot {@see storeRequestSnapshot()} put there, or null.
     *
     * Typed as a plain map, not as the `{query?, route?, method?, path?}` shape
     * the writer accepts: the value makes a round trip through JSON in a table
     * column and nothing on the way back checks it, so the narrower declaration
     * was a promise this method had no way to keep. A caller that needs a field
     * checks for it — which it had to do anyway, every key being optional.
     *
     * @return array<string, mixed>|null
     */
    public static function getRequestSnapshot(string $requestId): ?array
    {
        if (self::$table === null) {
            return null;
        }

        $key = self::tableKey($requestId);
        $row = self::row($key);

        return $row === null ? null : DeferredRequestRecord::fromRow($row)->requestSnapshot;
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
     * Returns a {@see DeferredRequestRecord} rather than an array: the row is nine columns
     * the table hands back as `mixed`, four of them JSON, and every caller used to redo that
     * decoding itself. The shape was additionally declared by hand in two places that had
     * already drifted apart from the table. The record states it once.
     */
    public static function consume(string $requestId): ?DeferredRequestRecord
    {
        if (self::$table === null) {
            return null;
        }

        $key = self::tableKey($requestId);
        $row = self::row($key);

        if ($row === null) {
            return null;
        }

        $record = DeferredRequestRecord::fromRow($row);

        if ($record->isExpired(time(), self::TTL_SECONDS)) {
            self::$table->del($key);

            return null;
        }

        return $record;
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
            $row = self::row($key);

            if ($row === null) {
                return;
            }

            // Read through the record rather than decoding inline: `delivered`
            // is a JSON list of strings and DeferredRequestRecord is where that
            // is stated. `?: []` also swallowed a legitimately-empty decode.
            $delivered = DeferredRequestRecord::fromRow($row)->delivered;
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

            // ONE column, because one is what this changes.
            //
            // `Table::set()` is a partial update — measured, not assumed: a set
            // naming one column leaves every other column of that row exactly
            // as it was. Every writer here used to name all nine anyway, which
            // made each one a read-modify-write over the whole row: eight
            // columns read back as `mixed`, cast, defaulted (`?? ''`, `?? '[]'`)
            // and written again, so a transient partial read could blank a
            // column nobody was changing, and two writers touching different
            // columns still overwrote each other with their own stale copy of
            // the rest. The lock below cannot fix that, because it only helps
            // when EVERY writer takes it and the other three never did.
            //
            // The lock stays: appending to `delivered` is still a genuine
            // read-modify-write, and the table is shared across worker
            // PROCESSES by mmap, which are parallel for real.
            if (!self::updateExistingRow($key, ['delivered' => $deliveredJson])) {
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

        // The row is fetched only to answer "does this request still exist" —
        // creating one here would resurrect a consumed or expired request. None
        // of its values are read, which is why nothing declares their shape any
        // more: the hand-written @var that used to sit here listed six of the
        // nine columns and existed solely to quiet the casts below it.
        $key = self::tableKey($requestId);
        if (self::row($key) === null) {
            return;
        }

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

        if (!self::updateExistingRow($key, ['slots' => $slotsJson])) {
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

        return hash_equals($entry->bindToken, $bindToken);
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
