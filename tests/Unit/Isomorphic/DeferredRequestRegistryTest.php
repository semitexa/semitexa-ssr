<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Semitexa\Ssr\Configuration\IsomorphicConfig;
use Semitexa\Ssr\Domain\Exception\DeferredRenderingException;

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
        self::assertNull($entry['request_snapshot']);
    }

    public function testStoreCapturesPlatformUiSseSessionIntoPageContext(): void
    {
        // Deferred-SSR session propagation (capture half): when the page
        // minted a platform-ui SSE session during the main render, store()
        // folds it into the persisted page context as `__ui_sse_session`
        // so the orchestrator can restore it before the deferred component
        // renders. Without this the component mints a fresh id no live
        // EventSource subscribes to.
        $sessionState = \Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState::class;
        if (!class_exists($sessionState)) {
            self::markTestSkipped('platform-ui not installed in this test environment.');
        }
        $sessionState::reset();
        $sessionState::setForTesting('sse_live_page_session_01');

        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_sse', 'demo.home', ['k' => 'v'], ['slot-a']);

        $entry = DeferredRequestRegistry::consume('dr_sse');
        $sessionState::reset();

        self::assertNotNull($entry);
        self::assertSame('sse_live_page_session_01', $entry['page_context']['__ui_sse_session'] ?? null);
        // The original context is preserved alongside the injected key.
        self::assertSame('v', $entry['page_context']['k'] ?? null);
    }

    public function testStoreDoesNotInjectSessionKeyWhenNoSessionMinted(): void
    {
        // Pages that never minted a platform-ui SSE session (no SSE opt-in)
        // must NOT get a spurious `__ui_sse_session` key — preserves the
        // pre-canonical-SSE behaviour for non-SSE deferred pages.
        $sessionState = \Semitexa\PlatformUi\Application\Service\Event\PlatformUiSseSessionState::class;
        if (class_exists($sessionState)) {
            $sessionState::reset();
        }

        $this->bootRegistry();
        DeferredRequestRegistry::store('dr_nosse', 'demo.home', ['k' => 'v'], ['slot-a']);

        $entry = DeferredRequestRegistry::consume('dr_nosse');

        self::assertNotNull($entry);
        self::assertArrayNotHasKey('__ui_sse_session', $entry['page_context']);
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
        self::assertSame($snapshot, $entry['request_snapshot']);
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
        self::assertCount(2, $entry['components']);
        self::assertSame('cmp_1', $entry['components'][0]['instance_id']);
        self::assertSame('ui-playground.leads-grid', $entry['components'][0]['name']);
        self::assertSame(['limit' => 5], $entry['components'][0]['props']);
        self::assertSame(['slot-a'], $entry['slots']);
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
        self::assertContains('slot-a', $entry['delivered']);
        self::assertContains('cmp_42', $entry['delivered']);
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
