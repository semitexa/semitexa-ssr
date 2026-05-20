<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Ssr\Attribute\WithTransport;

final class WithTransportTest extends TestCase
{
    public function testDefaultsToHttpAndNotDeferred(): void
    {
        $attr = new WithTransport();
        self::assertSame(TransportType::Http, $attr->mode);
        self::assertFalse($attr->deferred);
    }

    public function testAcceptsSseDeferred(): void
    {
        $attr = new WithTransport(mode: TransportType::Sse, deferred: true);
        self::assertSame(TransportType::Sse, $attr->mode);
        self::assertTrue($attr->deferred);
    }

    public function testAttributeAllowsHttpDeferredAtAttributeLevel(): void
    {
        // Attribute itself is permissive — the registry enforces deferred:true requires Sse.
        $attr = new WithTransport(mode: TransportType::Http, deferred: true);
        self::assertSame(TransportType::Http, $attr->mode);
        self::assertTrue($attr->deferred);
    }

    public function testTargetsClassOnly(): void
    {
        $reflection = new \ReflectionClass(WithTransport::class);
        $attributes = $reflection->getAttributes(\Attribute::class);
        self::assertNotEmpty($attributes);

        /** @var \Attribute $meta */
        $meta = $attributes[0]->newInstance();
        self::assertSame(\Attribute::TARGET_CLASS, $meta->flags);
    }

    public function testPropertiesAreReadonly(): void
    {
        $reflection = new \ReflectionClass(WithTransport::class);
        self::assertTrue($reflection->getProperty('mode')->isReadOnly());
        self::assertTrue($reflection->getProperty('deferred')->isReadOnly());
    }
}
