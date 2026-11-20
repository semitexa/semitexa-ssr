<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;
use Semitexa\Ssr\Application\Service\Async\SseSessionRegistry;
use Semitexa\Ssr\Application\Service\Async\SseWakeSubscriber;

/**
 * The wake receiver's decisions, driven through its seams with no connection:
 * what health means, what a (re)subscribe owes the sessions, and that teardown
 * is final. tk-idle-sse-stream-polls-redis.
 */
final class SseWakeSubscriberTest extends TestCase
{
    #[Test]
    public function it_is_unhealthy_until_a_subscription_is_confirmed(): void
    {
        // Health is what lets a loop park for seconds. Before a subscription
        // nothing would wake it, so the answer has to be no.
        [$subscriber] = $this->subscriber();

        self::assertFalse($subscriber->isHealthy());

        $subscriber->onSubscribed();

        self::assertTrue($subscriber->isHealthy());
    }

    #[Test]
    public function every_subscribe_wakes_every_session(): void
    {
        // Race 2: a wake published while nobody listened is gone, and nobody
        // knows for whom. So each session asks Redis once — on the first
        // subscribe and on every one after a drop.
        [$subscriber, $registry] = $this->subscriber();
        $registry->open('sess-1', new \stdClass(), '', '');
        $registry->open('sess-2', new \stdClass(), '', '');
        $registry->takeRedisDirty('sess-1');
        $registry->takeRedisDirty('sess-2');

        $subscriber->onSubscribed();

        self::assertTrue($registry->takeRedisDirty('sess-1'));
        self::assertTrue($registry->takeRedisDirty('sess-2'));

        $subscriber->onDropped(new \RuntimeException('Connection reset'), 12.0);
        self::assertFalse($subscriber->isHealthy(), 'a dropped subscription must send the loops back to their tick');

        $subscriber->onSubscribed();

        self::assertTrue($subscriber->isHealthy());
        self::assertTrue($registry->takeRedisDirty('sess-1'), 'the resubscribe did not wake the sessions again');
        self::assertTrue($registry->takeRedisDirty('sess-2'));
    }

    #[Test]
    public function a_message_wakes_only_the_session_it_names(): void
    {
        [$subscriber, $registry] = $this->subscriber();
        $registry->open('sess-1', new \stdClass(), '', '');
        $registry->open('sess-2', new \stdClass(), '', '');
        $registry->takeRedisDirty('sess-1');
        $registry->takeRedisDirty('sess-2');

        self::assertTrue($subscriber->onMessage(" sess-2\n"));
        self::assertFalse($subscriber->onMessage('held-by-another-worker'));
        self::assertFalse($subscriber->onMessage(''));

        self::assertFalse($registry->takeRedisDirty('sess-1'));
        self::assertTrue($registry->takeRedisDirty('sess-2'));
    }

    #[Test]
    public function teardown_is_final(): void
    {
        [$subscriber] = $this->subscriber();
        $subscriber->onSubscribed();

        $subscriber->stop();

        self::assertTrue($subscriber->isStopping());
        self::assertFalse($subscriber->isHealthy());

        // A confirmation still in flight when the worker leaves changes nothing,
        // and a leaving worker starts no new loop.
        $subscriber->onSubscribed();
        $subscriber->ensureRunning();

        self::assertFalse($subscriber->isHealthy());
        self::assertFalse($subscriber->isRunning());
    }

    #[Test]
    public function a_cancelled_subscription_is_the_worker_leaving(): void
    {
        [$subscriber] = $this->subscriber();
        $subscriber->onSubscribed();

        $subscriber->onDropped(new \RuntimeException('Coroutine canceled'), 0.5, cancelled: true);

        self::assertTrue($subscriber->isStopping());
        self::assertFalse($subscriber->isHealthy());
    }

    #[Test]
    public function it_names_no_pool(): void
    {
        // The blocking SUBSCRIBE lives on a dedicated connection. On the size-1
        // SSE pool it would starve every other Redis user on the worker.
        $code = (string) file_get_contents((string) (new \ReflectionClass(SseWakeSubscriber::class))->getFileName());
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $code);

        self::assertStringNotContainsString('RedisConnectionPool', $code);
        self::assertStringNotContainsString('SseRedisPool', $code);
        self::assertStringContainsString('$this->connectionFactory->create()', $code);
    }

    /** @return array{SseWakeSubscriber, SseSessionRegistry} */
    private function subscriber(): array
    {
        $registry = new SseSessionRegistry();

        // Never asked for a connection here.
        return [new SseWakeSubscriber($registry, new RedisSubscribeConnectionFactory(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1, 'password' => ''])), $registry];
    }
}
