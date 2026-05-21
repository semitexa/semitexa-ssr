<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Domain\Model\DataProviderContext;

final class DataProviderContextTest extends TestCase
{
    public function testStoresAllFields(): void
    {
        $request = new \stdClass();
        $ctx = new DataProviderContext(
            request: $request,
            instanceId: 'cmp_abc123',
            subscriberId: 'sub_xyz',
            slotId: 'sidebar',
            pageHandle: 'demo.home',
        );

        self::assertSame($request, $ctx->request);
        self::assertSame('cmp_abc123', $ctx->instanceId);
        self::assertSame('sub_xyz', $ctx->subscriberId);
        self::assertSame('sidebar', $ctx->slotId);
        self::assertSame('demo.home', $ctx->pageHandle);
    }

    public function testAcceptsNullFields(): void
    {
        $ctx = new DataProviderContext();
        self::assertNull($ctx->request);
        self::assertNull($ctx->instanceId);
        self::assertNull($ctx->subscriberId);
        self::assertNull($ctx->slotId);
        self::assertNull($ctx->pageHandle);
    }

    public function testAcceptsRequestSnapshotArray(): void
    {
        $snapshot = [
            'query'  => ['page' => '2'],
            'route'  => ['slug' => 'hello'],
            'method' => 'GET',
        ];
        $ctx = new DataProviderContext(request: $snapshot);

        self::assertSame($snapshot, $ctx->request);
    }

    public function testClassIsReadonly(): void
    {
        $reflection = new \ReflectionClass(DataProviderContext::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
