<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Domain\Exception\DeferredRenderingException;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseSessionState;

final class DeferredRequestRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Swoole\Table::class, false)) {
            self::markTestSkipped('Swoole extension not loaded.');
        }
        DeferredRequestRegistry::reset();
    }

    protected function tearDown(): void
    {
        DeferredRequestRegistry::reset();
    }

    private function bootRegistry(int $snapshotSize = 4096): void
    {
        DeferredRequestRegistry::initialize(new IsomorphicConfig(
            enabled: true,
            deferredContextSize: 8192,
            requestSnapshotSize: $snapshotSize,
        ));
    }

    public function testConsumedEntryHasNullSnapshotWhenNoneStored(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_a', 'demo.home', ['k' => 'v'], ['slot-a']);

        $entry = DeferredRequestRegistry::consume('dr_a');

        self::assertNotNull($entry);
        self::assertNull($entry->requestSnapshot);
    }

    public function testStoreCapturesUiSseSessionIntoPageContext(): void
    {
        // Deferred-SSR session propagation (capture half): when the page
        // minted a UI SSE session during the main render, store() folds it into
        // the persisted page context as `__ui_sse_session` so the orchestrator
        // can restore it before the deferred component renders. Without this the
        // component mints a fresh id no live EventSource subscribes to.
        UiSseSessionState::reset();
        UiSseSessionState::setForTesting('sse_live_page_session_01');

        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_sse', 'demo.home', ['k' => 'v'], ['slot-a']);

        $entry = DeferredRequestRegistry::consume('dr_sse');
        UiSseSessionState::reset();

        self::assertNotNull($entry);
        self::assertSame('sse_live_page_session_01', $entry->pageContext['__ui_sse_session'] ?? null);
        // The original context is preserved alongside the injected key.
        self::assertSame('v', $entry->pageContext['k'] ?? null);
    }

    public function testStoreDoesNotInjectSessionKeyWhenNoSessionMinted(): void
    {
        // Pages that never minted a UI SSE session (no SSE opt-in) must NOT get
        // a spurious `__ui_sse_session` key — preserves the pre-canonical-SSE
        // behaviour for non-SSE deferred pages.
        UiSseSessionState::reset();

        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_nosse', 'demo.home', ['k' => 'v'], ['slot-a']);

        $entry = DeferredRequestRegistry::consume('dr_nosse');

        self::assertNotNull($entry);
        self::assertArrayNotHasKey('__ui_sse_session', $entry->pageContext);
    }

    public function testStoreRequestSnapshotRoundTrip(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_b', 'demo.home', [], ['slot-a']);

        $snapshot = [
            'query'  => ['page' => '2', 'filter' => 'open'],
            'route'  => ['slug' => 'hello'],
            'method' => 'GET',
            'path'   => '/demo/list',
        ];
        DeferredRequestRegistry::storeRequestSnapshot('dr_b', $snapshot);

        self::assertSame($snapshot, DeferredRequestRegistry::getRequestSnapshot('dr_b'));

        $entry = DeferredRequestRegistry::consume('dr_b');
        self::assertNotNull($entry);
        self::assertSame($snapshot, $entry->requestSnapshot);
    }

    public function testSnapshotSurvivesUpdateSlotsAndMarkDelivered(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_c', 'demo.home', [], ['slot-a']);

        $snapshot = ['query' => ['sort' => 'asc'], 'route' => [], 'method' => 'GET', 'path' => '/x'];
        DeferredRequestRegistry::storeRequestSnapshot('dr_c', $snapshot);

        DeferredRequestRegistry::updateSlots('dr_c', ['slot-a', 'slot-b']);
        DeferredRequestRegistry::markDelivered('dr_c', 'slot-a');

        self::assertSame($snapshot, DeferredRequestRegistry::getRequestSnapshot('dr_c'));
    }

    /**
     * A partial write to a row that is no longer there must create nothing.
     *
     * `Table::set()` creates a row on a key it does not have, and every writer
     * here checks the row and writes as two steps — so a remove(), or the
     * expired branch of consume(), landing between them turns the write into a
     * resurrection: a row of whichever columns that writer was changing and
     * defaults for the rest. No lock prevents it; the one that exists is held
     * by a single writer out of four and by neither deletion path.
     *
     * The window cannot be opened from a test, so the guard is exercised
     * directly. A resurrected fragment carries `created_at` of 0 — only
     * store() ever writes that column, and it writes time() — which is both the
     * tell and the damage: 0 is instantly expired, so the row can never be
     * delivered, and it would hold a slot in a fixed-size table until something
     * happened to read that id again.
     */
    public function testAWriteToAVanishedRowResurrectsNothing(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_gone', 'demo.home', [], ['slot-a']);
        DeferredRequestRegistry::remove('dr_gone');

        $write = new \ReflectionMethod(DeferredRequestRegistry::class, 'updateExistingRow');
        $key = (new \ReflectionMethod(DeferredRequestRegistry::class, 'tableKey'))
            ->invoke(null, 'dr_gone');

        self::assertFalse(
            $write->invoke(null, $key, ['delivered' => '["slot-a"]']),
            'the update did not happen and cannot — that is not the same as a failed write',
        );

        $table = (new \ReflectionProperty(DeferredRequestRegistry::class, 'table'))->getValue();
        self::assertNotNull($table);
        self::assertFalse($table->exist($key), 'the row must be gone, exactly as the removal intended');
        self::assertNull(DeferredRequestRegistry::consume('dr_gone'));
    }

    public function testStoreRequestSnapshotForUnknownRequestIdIsNoop(): void
    {
        $this->bootRegistry();

        DeferredRequestRegistry::storeRequestSnapshot('dr_missing', ['query' => []]);

        self::assertNull(DeferredRequestRegistry::getRequestSnapshot('dr_missing'));
    }

    public function testStoreComponentInstancesRoundTripsThroughConsume(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_components', 'demo.home', [], ['slot-a']);

        $instances = [
            ['instance_id' => 'cmp_1', 'name' => 'ui-playground.leads-grid', 'props' => ['limit' => 5]],
            ['instance_id' => 'cmp_2', 'name' => 'demo.chart',               'props' => []],
        ];
        DeferredRequestRegistry::storeComponentInstances('dr_components', $instances);

        $entry = DeferredRequestRegistry::consume('dr_components');

        self::assertNotNull($entry);
        self::assertCount(2, $entry->components);
        self::assertSame('cmp_1', $entry->components[0]['instance_id']);
        self::assertSame('ui-playground.leads-grid', $entry->components[0]['name']);
        self::assertSame(['limit' => 5], $entry->components[0]['props']);
        self::assertSame(['slot-a'], $entry->slots);
    }

    public function testStoreComponentInstancesForUnknownRequestIdIsNoop(): void
    {
        $this->bootRegistry();

        DeferredRequestRegistry::storeComponentInstances('dr_unknown', [
            ['instance_id' => 'cmp_x', 'name' => 'whatever', 'props' => []],
        ]);

        self::assertNull(DeferredRequestRegistry::consume('dr_unknown'));
    }

    public function testMarkDeliveredWithComponentInstanceId(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_mixed', 'demo.home', [], ['slot-a']);
        DeferredRequestRegistry::storeComponentInstances('dr_mixed', [
            ['instance_id' => 'cmp_42', 'name' => 'leads-grid', 'props' => []],
        ]);

        DeferredRequestRegistry::markDelivered('dr_mixed', 'slot-a');
        DeferredRequestRegistry::markDelivered('dr_mixed', 'cmp_42');

        $entry = DeferredRequestRegistry::consume('dr_mixed');
        self::assertNotNull($entry);
        self::assertContains('slot-a', $entry->delivered);
        self::assertContains('cmp_42', $entry->delivered);
    }

    /**
     * A write must leave alone every column it did not come to change.
     *
     * ⚠️ This is NOT a reproduction of the cross-worker race, and it passed
     * before the change that motivated it. Sequentially it cannot fail: each
     * write method does its own get() immediately before its set(), with no
     * yield point between them, so within one worker the pair is effectively
     * atomic and the second writer always reads what the first just wrote.
     *
     * What it does pin is the property the fix relies on — that changing the
     * components leaves `delivered`, `slots` and `page_handle` exactly as they
     * were. A writer that goes back to rewriting all nine columns from its own
     * read would still pass this; one that writes the wrong column, or blanks
     * a column it defaulted, would not. That is worth having, and claiming
     * more for it would be claiming a test that does not exist.
     *
     * The race itself lives across WORKERS: the table is shared by mmap between
     * OS processes, which are genuinely parallel. markDelivered takes a
     * Swoole\Lock for exactly that reason — and a lock only helps when every
     * writer takes it, which storeComponentInstances and storeRequestSnapshot
     * never did while they were rewriting `delivered` from a stale read.
     * Writing only the column you came for removes the question.
     */
    public function testAWriteLeavesAloneTheColumnsItDidNotComeToChange(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_race', 'demo.home', ['k' => 'v'], ['slot-a', 'slot-b']);

        DeferredRequestRegistry::markDelivered('dr_race', 'slot-a');

        $components = [['instance_id' => 'c1', 'name' => 'Card', 'props' => []]];
        DeferredRequestRegistry::storeComponentInstances('dr_race', $components);

        $entry = DeferredRequestRegistry::consume('dr_race');
        self::assertNotNull($entry);
        self::assertSame(['slot-a'], $entry->delivered, 'the delivered slot must survive an unrelated write');
        self::assertSame($components, $entry->components);
        self::assertSame(['slot-a', 'slot-b'], $entry->slots, 'and so must the slots');
        self::assertSame('demo.home', $entry->pageHandle);
        self::assertSame(['k' => 'v'], $entry->pageContext);
    }

    /** The same property from the snapshot writer's side. */
    public function testStoringASnapshotLeavesTheDeliveredSlotsAlone(): void
    {
        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_race2', 'demo.home', [], ['slot-a']);

        DeferredRequestRegistry::markDelivered('dr_race2', 'slot-a');
        DeferredRequestRegistry::storeRequestSnapshot('dr_race2', ['method' => 'GET', 'path' => '/x']);

        $entry = DeferredRequestRegistry::consume('dr_race2');
        self::assertNotNull($entry);
        self::assertSame(['slot-a'], $entry->delivered);
    }

    public function testStoreRequestSnapshotThrowsWhenSerializedSizeExceedsBudget(): void
    {
        $this->bootRegistry(snapshotSize: 64);
        DeferredRequestRegistry::store('dr_big', 'demo.home', [], ['slot-a']);

        $this->expectException(DeferredRenderingException::class);
        DeferredRequestRegistry::storeRequestSnapshot('dr_big', [
            'query' => ['payload' => str_repeat('x', 256)],
        ]);
    }
}
