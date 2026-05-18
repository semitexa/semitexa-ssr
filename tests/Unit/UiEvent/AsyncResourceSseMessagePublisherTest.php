<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
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
    protected function setUp(): void
    {
        $this->resetTransportState();
    }

    protected function tearDown(): void
    {
        $this->resetTransportState();
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
        $arrayProperties = ['sessions', 'queues', 'buffer'];
        foreach ($arrayProperties as $name) {
            $property = new \ReflectionProperty(AsyncResourceSseServer::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, []);
        }

        // Neutralise cross-worker transports so deliver() lands in the in-process
        // buffer (the test's verification seam). The redis pool is the trickiest
        // — getRedisPool() rebuilds it from REDIS_HOST env every call, so we both
        // clear the static and clear the env var for the duration of the test.
        $nullableProperties = ['httpServer', 'sessionWorkerTable', 'deliverTable', 'pendingDeliverTable', 'redisPool'];
        foreach ($nullableProperties as $name) {
            $property = new \ReflectionProperty(AsyncResourceSseServer::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }

        \putenv('REDIS_HOST=');
        unset($_ENV['REDIS_HOST'], $_SERVER['REDIS_HOST']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bufferedFor(string $sessionId): array
    {
        $property = new \ReflectionProperty(AsyncResourceSseServer::class, 'buffer');
        $property->setAccessible(true);
        /** @var array<string, list<array<string, mixed>>> $buffer */
        $buffer = $property->getValue();
        return $buffer[$sessionId] ?? [];
    }
}
