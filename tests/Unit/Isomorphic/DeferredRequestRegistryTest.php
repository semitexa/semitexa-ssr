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
