<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Isomorphic;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionProperty;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Ssr\Application\Service\Isomorphic\DeferredRequestRegistry;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;

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

    #[Test]
    public function a_context_nested_past_the_store_limit_does_not_fit(): void
    {
        $nested = 'leaf';
        for ($i = 0; $i < 34; $i++) {
            $nested = [$nested];
        }

        self::assertFalse(DeferredRequestRegistry::fits(['page' => $nested], []), 'store() would refuse it at depth 32');
    }

    #[Test]
    public function without_a_snapshot_it_measures_the_request_being_served(): void
    {
        // The renderers pass no snapshot: fits() reads the Swoole request
        // bound to the coroutine, as storeRequestSnapshot() later stores it.
        $request = $this->createStub(SwooleRequest::class);
        $request->get = ['q' => str_repeat('a', 1500)];
        $request->server = ['request_method' => 'GET', 'request_uri' => '/shop'];
        $bound = [$request, $this->createStub(SwooleResponse::class), $this->createStub(Server::class)];
        $small = ['page' => ['iri' => '/shop']];
        $fits = null;

        Coroutine\run(static function () use ($bound, $small, &$fits): void {
            $key = (new ReflectionClassConstant(SwooleBootstrap::class, 'COROUTINE_CONTEXT_KEY'))->getValue();
            Coroutine::getContext()[$key] = $bound;
            $fits = DeferredRequestRegistry::fits($small);
        });

        self::assertFalse($fits, 'a long query on the request being served');
        self::assertTrue(DeferredRequestRegistry::fits($small), 'outside a request there is no snapshot to measure');
    }
}
