<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Semitexa\Ssr\Application\Service\UiEvent\AsyncResourceSseMessagePublisher;
use Semitexa\Ssr\Application\Service\UiEvent\CanonicalUiMessagePublisherInterface;
use Semitexa\Ssr\Application\Service\UiEvent\UiErrorMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiPatchMessage;

/**
 * The default {@see CanonicalUiMessagePublisherInterface} binding forwards
 * to {@see AsyncResourceSseServer::deliver()}. In a unit-test context
 * with no Swoole tables and no Redis, `deliver()` falls through to the
 * in-process `$buffer` static — which we read via Reflection to assert
 * what would have hit the canonical transport (no new transport, queue,
 * or endpoint per ADR-0001 §6).
 */
final class AsyncResourceSseMessagePublisherTest extends TestCase
{
    private ?string $previousRedisHost = null;

    protected function setUp(): void
    {
        $raw = \getenv('REDIS_HOST');
        $this->previousRedisHost = $raw === false ? null : $raw;
        $this->resetTransportState();
    }

    protected function tearDown(): void
    {
        $this->resetTransportState();

        if ($this->previousRedisHost === null) {
            \putenv('REDIS_HOST');
            unset($_ENV['REDIS_HOST'], $_SERVER['REDIS_HOST']);
            return;
        }

        \putenv('REDIS_HOST=' . $this->previousRedisHost);
        $_ENV['REDIS_HOST']    = $this->previousRedisHost;
        $_SERVER['REDIS_HOST'] = $this->previousRedisHost;
    }

    #[Test]
    public function publisher_implements_canonical_contract(): void
    {
        self::assertInstanceOf(
            CanonicalUiMessagePublisherInterface::class,
            new AsyncResourceSseMessagePublisher(),
        );
    }

    #[Test]
    public function publish_forwards_typed_payload_to_async_resource_sse_server(): void
    {
        $publisher = new AsyncResourceSseMessagePublisher();
        $publisher->publish('sess-1', new UiPatchMessage('cmp-1', ['v' => 1], 'corr-x'));

        $bufferedForSession = $this->bufferedFor('sess-1');
        self::assertCount(1, $bufferedForSession);
        self::assertSame(
            [
                '_type'               => 'ui.patch',
                'componentInstanceId' => 'cmp-1',
                'patch'               => ['v' => 1],
                'correlationId'       => 'corr-x',
            ],
            $bufferedForSession[0],
        );
    }

    #[Test]
    public function publisher_only_emits_allow_listed_types(): void
    {
        $publisher = new AsyncResourceSseMessagePublisher();
        $publisher->publish('sess-1', new UiErrorMessage('reason_x', 'Operator-safe message.'));

        $bufferedForSession = $this->bufferedFor('sess-1');
        self::assertCount(1, $bufferedForSession);
        self::assertSame('ui.error', $bufferedForSession[0]['_type']);
        // The publisher does NOT inject arbitrary keys.
        self::assertSame(
            ['_type', 'reason', 'message'],
            array_keys($bufferedForSession[0]),
        );
    }

    private function resetTransportState(): void
    {
        // Reset same-worker state so deliver() does not pick the local-queue path.
        self::resetServerSessionRegistry();

        // Neutralise cross-worker transports so deliver() lands in the in-process
        // buffer (the test's verification seam). The redis side is the trickiest:
        // since ep-slay-sse-god-class the pool lives in SseRedisPool, memoized by
        // the collaborators that hold it, so clearing REDIS_HOST is not enough on
        // its own — the collaborator slots must be dropped too, forcing them to be
        // rebuilt against the now-empty env.
        // The Swoole tables and the server handle moved into SseWorkerTables, so
        // dropping that one slot neutralises the whole shared-memory path at once.
        $nullableProperties = [
            'workerTables',
            'redisPoolResolver',
            'redisSessionQueue',
            'authSessionMap',
        ];
        foreach ($nullableProperties as $name) {
            $property = new \ReflectionProperty(AsyncResourceSseServer::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }

        \putenv('REDIS_HOST=');
        unset($_ENV['REDIS_HOST'], $_SERVER['REDIS_HOST']);
    }

    /**
     * `ep-slay-sse-god-class` · tk-sse-session-state — the sessions/queues/buffer
     * maps moved into {@see SseSessionRegistry}. Tests reach the FACADE's
     * instance: the code under test goes through AsyncResourceSseServer, so a
     * separately constructed registry would be invisible to it.
     */
    private static function serverSessionRegistry(): SseSessionRegistry
    {
        $slot = new \ReflectionProperty(AsyncResourceSseServer::class, 'sessionRegistry');
        $slot->setAccessible(true);
        $registry = $slot->getValue();
        if (!$registry instanceof SseSessionRegistry) {
            $registry = new SseSessionRegistry();
            $slot->setValue(null, $registry);
        }

        return $registry;
    }

    private static function resetServerSessionRegistry(): void
    {
        $slot = new \ReflectionProperty(AsyncResourceSseServer::class, 'sessionRegistry');
        $slot->setAccessible(true);
        $slot->setValue(null, null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bufferedFor(string $sessionId): array
    {
        return self::serverSessionRegistry()->buffered($sessionId);
    }
}
