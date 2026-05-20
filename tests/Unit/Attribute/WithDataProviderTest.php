<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Attribute\WithDataProvider;

final class WithDataProviderTest extends TestCase
{
    public function testStoresProviderClass(): void
    {
        $attr = new WithDataProvider(providerClass: 'App\\Foo\\BarProvider');
        self::assertSame('App\\Foo\\BarProvider', $attr->providerClass);
    }

    public function testTargetsClassOnly(): void
    {
        $reflection = new \ReflectionClass(WithDataProvider::class);
        $attributes = $reflection->getAttributes(\Attribute::class);
        self::assertNotEmpty($attributes);

        /** @var \Attribute $meta */
        $meta = $attributes[0]->newInstance();
        self::assertSame(\Attribute::TARGET_CLASS, $meta->flags);
    }

    public function testProviderClassFieldIsReadonly(): void
    {
        $reflection = new \ReflectionClass(WithDataProvider::class);
        $property = $reflection->getProperty('providerClass');
        self::assertTrue($property->isReadOnly());
    }
}
