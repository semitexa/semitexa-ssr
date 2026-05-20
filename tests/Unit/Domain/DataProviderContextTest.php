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
        );

        self::assertSame($request, $ctx->request);
        self::assertSame('cmp_abc123', $ctx->instanceId);
        self::assertSame('sub_xyz', $ctx->subscriberId);
    }

    public function testAcceptsNullFields(): void
    {
        $ctx = new DataProviderContext(request: null, instanceId: null, subscriberId: null);
        self::assertNull($ctx->request);
        self::assertNull($ctx->instanceId);
        self::assertNull($ctx->subscriberId);
    }

    public function testClassIsReadonly(): void
    {
        $reflection = new \ReflectionClass(DataProviderContext::class);
        self::assertTrue($reflection->isReadOnly());
    }
}
