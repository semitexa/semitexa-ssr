<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Component;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Component\ComponentInstanceStore;

final class ComponentInstanceStoreTest extends TestCase
{
    protected function setUp(): void
    {
        ComponentInstanceStore::reset();
    }

    protected function tearDown(): void
    {
        ComponentInstanceStore::reset();
    }

    public function testRecordAndAllReturnsRecordedInstances(): void
    {
        ComponentInstanceStore::record('cmp_1', 'leads-grid', ['limit' => 10]);
        ComponentInstanceStore::record('cmp_2', 'chart', ['x' => 'y']);

        $all = ComponentInstanceStore::all();

        self::assertCount(2, $all);
        self::assertSame('leads-grid', $all['cmp_1']['name']);
        self::assertSame(['limit' => 10], $all['cmp_1']['props']);
        self::assertSame('cmp_2', $all['cmp_2']['instance_id']);
    }

    public function testRecordSilentlyIgnoresEmptyIdentifiers(): void
    {
        ComponentInstanceStore::record('', 'leads-grid', []);
        ComponentInstanceStore::record('cmp_x', '', []);

        self::assertSame([], ComponentInstanceStore::all());
    }

    public function testResetClearsStore(): void
    {
        ComponentInstanceStore::record('cmp_1', 'leads-grid', []);
        ComponentInstanceStore::reset();

        self::assertSame([], ComponentInstanceStore::all());
    }

    public function testSanitizePropsDropsNonSerializableValues(): void
    {
        ComponentInstanceStore::record('cmp_1', 'leads-grid', [
            'scalar' => 'ok',
            'nested' => ['a' => 1, 'b' => 'two'],
            'resource' => fopen('php://memory', 'r'),
            'object' => new \stdClass(),
        ]);

        $props = ComponentInstanceStore::all()['cmp_1']['props'];

        self::assertSame('ok', $props['scalar']);
        self::assertSame(['a' => 1, 'b' => 'two'], $props['nested']);
        self::assertArrayNotHasKey('resource', $props);
        self::assertArrayNotHasKey('object', $props);
    }
}
