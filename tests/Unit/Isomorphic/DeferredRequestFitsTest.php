<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;

/**
 * A deferred request that cannot be stored is rendered inline, not answered
 * with a 500: found by an HTTP edge probe on 2026-11-03, a 7 000-character
 * query took every deferred page to a 500, since the context repeats the URL
 * and the request snapshot carries it.
 */
final class DeferredRequestFitsTest extends TestCase
{
    /** @var array<string, int> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['contextColumnSize' => 2048, 'snapshotColumnSize' => 1024] as $name => $size) {
            $property = new ReflectionProperty(DeferredRequestRegistry::class, $name);
            $this->saved[$name] = (int) $property->getValue();
            $property->setValue(null, $size);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $size) {
            (new ReflectionProperty(DeferredRequestRegistry::class, $name))->setValue(null, $size);
        }
    }

    #[Test]
    public function a_request_fits_only_when_both_its_context_and_its_snapshot_do(): void
    {
        $small = ['page' => ['iri' => '/shop?q=a']];
        $snapshot = ['uri' => '/shop', 'query' => ['q' => 'a']];
        self::assertTrue(DeferredRequestRegistry::fits($small, $snapshot));

        $longUrl = '/shop?q=' . str_repeat('a', 1500);
        self::assertFalse(DeferredRequestRegistry::fits(['page' => ['iri' => $longUrl, 'canonical' => $longUrl]], $snapshot), 'a context past its column');
        self::assertFalse(DeferredRequestRegistry::fits($small, ['uri' => $longUrl]), 'a snapshot past its column');
        self::assertTrue(DeferredRequestRegistry::fits(['page' => ['iri' => str_repeat('b', 1700)]], $snapshot), 'room left for the session id is kept: 1700 + headroom fits 2048');
        self::assertFalse(DeferredRequestRegistry::fits(['page' => ['iri' => str_repeat('b', 1900)]], $snapshot), 'but not past it');
    }
}
