<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Ssr\Application\Service\Async\ScanningSubscriberIndex;
use Semitexa\Ssr\Application\Service\Async\SubscriptionDtoRegistry;
use Semitexa\Ssr\Application\Service\Async\SubscriptionTable;
use Semitexa\Ssr\Domain\Contract\SubscriberIndexInterface;
use Semitexa\Ssr\Domain\Model\SubscriberRef;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * Track R · R1 — the three-tier subscription store, proven in ISOLATION over a
 * REAL {@see \Swoole\Table}. No connect, no subscriber, no loop, no re-run call:
 * rows are inserted directly; the store's data structures + operations are the
 * unit under test.
 *
 *  Tier 1 — {@see SubscriptionTable} (cross-worker, serialized Swoole\Table)
 *  Tier 2 — {@see SubscriptionDtoRegistry} (worker-local, never serialized)
 *  Tier 3 — {@see ScanningSubscriberIndex} behind {@see SubscriberIndexInterface}
 */
final class SubscriptionStoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class, false)) {
            self::markTestSkipped('Swoole extension not loaded.');
        }
        SubscriptionDtoRegistry::clear();
    }

    protected function tearDown(): void
    {
        SubscriptionDtoRegistry::clear();
    }

    // -----------------------------------------------------------------------
    // Proof 1 — subscription round-trip over a real Swoole\Table
    // -----------------------------------------------------------------------

    #[Test]
    public function subscriptionRoundTripInsertFindRemove(): void
    {
        $table = SubscriptionTable::create(64);
        $index = new ScanningSubscriberIndex($table);

        $table->insert($this->record('str_a', 'sse_session_a', 'default', ['lead_submission']));

        // find by scope key resolves the inserted subscription
        $hits = $index->find('default', 'lead_submission');
        self::assertCount(1, $hits);
        self::assertInstanceOf(SubscriberRef::class, $hits[0]);
        self::assertSame('str_a', $hits[0]->streamingId);
        self::assertSame('sse_session_a', $hits[0]->sessionId);

        // get() returns the full serialized record
        $record = $table->get('str_a');
        self::assertNotNull($record);
        self::assertSame(['lead_submission'], $record->scopeKeys);

        // remove drops it — find and get both go empty
        $table->remove('str_a');
        self::assertSame([], $index->find('default', 'lead_submission'));
        self::assertNull($table->get('str_a'));
        self::assertFalse($table->has('str_a'));
    }

    #[Test]
    public function multipleSubscribersOnSameScopeKeyAllResolvedAndTenantIsolated(): void
    {
        $table = SubscriptionTable::create(64);
        $index = new ScanningSubscriberIndex($table);

        // two subscribers in tenant 'default' watching 'products'…
        $table->insert($this->record('str_1', 'sess_1', 'default', ['products', 'orders']));
        $table->insert($this->record('str_2', 'sess_2', 'default', ['products']));
        // …one subscriber in a DIFFERENT tenant watching the same scope key.
        $table->insert($this->record('str_3', 'sess_3', 'tenant_b', ['products']));

        $hits = $index->find('default', 'products');
        $ids = array_map(static fn (SubscriberRef $r): string => $r->streamingId, $hits);
        sort($ids);
        self::assertSame(['str_1', 'str_2'], $ids, 'both same-tenant subscribers resolve');

        // tenant isolation (the security boundary): the other tenant's subscriber
        // is NEVER resolved by a push scoped to 'default'.
        self::assertSame(['str_3'], array_map(
            static fn (SubscriberRef $r): string => $r->streamingId,
            $index->find('tenant_b', 'products'),
        ));

        // a scope no one watches resolves to nothing
        self::assertSame([], $index->find('default', 'unwatched_table'));
    }

    // -----------------------------------------------------------------------
    // Proof 2 — worker-static DTO registry round-trip (a plain in-worker map)
    // -----------------------------------------------------------------------

    #[Test]
    public function workerStaticDtoRegistryRoundTrip(): void
    {
        $context = $this->reRunContext('sse_session_a');

        self::assertFalse(SubscriptionDtoRegistry::has('str_a'));

        SubscriptionDtoRegistry::set('str_a', $context);
        self::assertTrue(SubscriptionDtoRegistry::has('str_a'));
        self::assertSame($context, SubscriptionDtoRegistry::get('str_a'), 'same live object handed back');
        self::assertSame(1, SubscriptionDtoRegistry::count());

        SubscriptionDtoRegistry::remove('str_a');
        self::assertFalse(SubscriptionDtoRegistry::has('str_a'));
        self::assertNull(SubscriptionDtoRegistry::get('str_a'));
        self::assertSame(0, SubscriptionDtoRegistry::count());
    }

    // -----------------------------------------------------------------------
    // Proof 3 — the tier-separation invariant (the security boundary)
    // -----------------------------------------------------------------------

    #[Test]
    public function tierSeparationInvariantNoLiveObjectInTheCrossWorkerTable(): void
    {
        $table = SubscriptionTable::create(64);

        // The decisive STRUCTURAL proof: the cross-worker table schema declares
        // ONLY serializable string columns. There is no DTO / object / live-state
        // column, so an identity-bearing object cannot enter this tier at all.
        $columns = SubscriptionTable::schemaColumns();
        self::assertSame(
            ['streaming_id', 'session_id', 'tenant_id', 'scope_keys', 'tenant_blob'],
            $columns,
        );
        foreach ($columns as $column) {
            self::assertDoesNotMatchRegularExpression(
                '/dto|object|payload|rerun|context/i',
                $column,
                "column {$column} must not be able to hold live re-run state",
            );
        }

        // The serialized record that the table stores/returns carries only scalars
        // and a list of scalars — never a live object.
        $reflection = new \ReflectionClass(SubscriptionRecord::class);
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertContains(
                $type->getName(),
                ['string', 'array'],
                "SubscriptionRecord::\${$param->getName()} must be a serializable scalar/list, not an object",
            );
        }

        // Now the live half: the SAME streaming_id carries a live ReRunContext, but
        // ONLY in the worker-local registry. The table tier has no path to it.
        $context = $this->reRunContext('sse_session_a');
        $table->insert($this->record('str_a', 'sse_session_a', 'default', ['lead_submission'], 'blob-bytes'));
        SubscriptionDtoRegistry::set('str_a', $context);

        $row = $table->get('str_a');
        self::assertNotNull($row);
        self::assertSame('blob-bytes', $row->tenantBlob, 'tenant blob is the only tenant carrier in the table — opaque bytes, not a live object');
        // The table round-trip yields a plain record, not the live object.
        self::assertNotInstanceOf(ReRunContext::class, $row);
        // The live object is reachable ONLY through the worker-local registry.
        self::assertSame($context, SubscriptionDtoRegistry::get('str_a'));
    }

    // -----------------------------------------------------------------------
    // Proof 4 — the reverse-index seam: swappable behind the interface
    // -----------------------------------------------------------------------

    #[Test]
    public function reverseIndexSeamIsSwappableBehindTheInterface(): void
    {
        $table = SubscriptionTable::create(64);
        $table->insert($this->record('str_a', 'sess_a', 'default', ['lead_submission']));
        $table->insert($this->record('str_b', 'sess_b', 'default', ['lead_submission']));

        // The production scan implementation…
        $scan = new ScanningSubscriberIndex($table);
        // …and a second, trivial in-memory implementation of the SAME contract.
        $alt = $this->inMemoryIndex([
            new SubscriberRef('str_a', 'sess_a'),
            new SubscriberRef('str_b', 'sess_b'),
        ]);

        self::assertInstanceOf(SubscriberIndexInterface::class, $scan);
        self::assertInstanceOf(SubscriberIndexInterface::class, $alt);

        // Callers depend only on the interface: both impls answer the same query
        // identically, so the scan can later be replaced by a keyed-Table impl
        // (design §C.5) without touching a single caller.
        $idsOf = static function (SubscriberIndexInterface $i): array {
            $ids = array_map(
                static fn (SubscriberRef $r): string => $r->streamingId,
                $i->find('default', 'lead_submission'),
            );
            sort($ids);
            return $ids;
        };
        self::assertSame(['str_a', 'str_b'], $idsOf($scan));
        self::assertSame($idsOf($scan), $idsOf($alt));
    }

    // -----------------------------------------------------------------------
    // Proof 5 — scope keys are P1 resourceKeys, matching what P2's event carries
    // -----------------------------------------------------------------------

    #[Test]
    public function scopeKeyMatchesTheResourceKeyAP2EventCarries(): void
    {
        $eventClass = \Semitexa\Orm\Domain\Event\ResourceChangedEvent::class;
        $opEnum = \Semitexa\Orm\Domain\Enum\ResourceChangeOperation::class;
        if (!class_exists($eventClass) || !enum_exists($opEnum)) {
            self::markTestSkipped('semitexa-orm not installed in this test environment.');
        }

        // A push originates as a P2 ResourceChangedEvent carrying a P1 resourceKey
        // (default = table name). The store keys subscriptions on that SAME string,
        // so producer and reverse-index agree by construction.
        $event = new $eventClass('lead_submission', $opEnum::Insert);

        $table = SubscriptionTable::create(64);
        $index = new ScanningSubscriberIndex($table);
        $table->insert($this->record('str_a', 'sess_a', 'default', [$event->resourceKey]));

        $hits = $index->find('default', $event->resourceKey);
        self::assertCount(1, $hits);
        self::assertSame('str_a', $hits[0]->streamingId);
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * @param list<string> $scopeKeys
     */
    private function record(
        string $streamingId,
        string $sessionId,
        string $tenantId,
        array $scopeKeys,
        string $tenantBlob = '',
    ): SubscriptionRecord {
        return new SubscriptionRecord($streamingId, $sessionId, $tenantId, $scopeKeys, $tenantBlob);
    }

    /** A real, minimal R2 ReRunContext — the live, never-serialized re-run state. */
    private function reRunContext(string $sessionId): ReRunContext
    {
        return new ReRunContext(
            cachedDto: new \stdClass(),
            route: new DiscoveredRoute(
                path: '/leads',
                methods: ['GET'],
                name: 'leads.live',
                requestClass: \stdClass::class,
                responseClass: \stdClass::class,
                handlers: [],
                type: 'http_request',
                transport: 'sse',
                produces: null,
                consumes: null,
                module: 'core',
            ),
            requestSnapshot: ['method' => 'GET', 'uri' => '/leads', 'cookies' => ['sid' => $sessionId]],
            sessionId: $sessionId,
            subjectRef: 'alice',
        );
    }

    /**
     * @param list<SubscriberRef> $refs
     */
    private function inMemoryIndex(array $refs): SubscriberIndexInterface
    {
        return new class($refs) implements SubscriberIndexInterface {
            /** @param list<SubscriberRef> $refs */
            public function __construct(private readonly array $refs) {}

            public function find(string $tenant, string $scopeKey): array
            {
                return $this->refs;
            }
        };
    }
}
