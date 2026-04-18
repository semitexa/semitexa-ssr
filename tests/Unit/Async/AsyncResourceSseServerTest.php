<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Async\AsyncResourceSseServer;
use Swoole\Coroutine\Channel;

final class AsyncResourceSseServerTest extends TestCase
{
    #[Test]
    public function session_coroutine_cancellation_clears_registry_and_stops_worker(): void
    {
        if (!extension_loaded('swoole') || !function_exists('Co\\run') || !class_exists(Channel::class)) {
            self::markTestSkipped('Swoole coroutine runtime is required for this test.');
        }

        $sessionId = 'test-session';
        $started = new Channel(1);
        $finished = new Channel(1);
        $property = new \ReflectionProperty(AsyncResourceSseServer::class, 'sessionCoroutines');
        $property->setAccessible(true);
        $cancelMethod = new \ReflectionMethod(AsyncResourceSseServer::class, 'cancelSessionCoroutines');
        $cancelMethod->setAccessible(true);

        try {
            \Co\run(function () use ($sessionId, $started, $finished, $property, $cancelMethod): void {
                $cid = AsyncResourceSseServer::createSessionCoroutine(function () use ($started, $finished): void {
                    $started->push(true);
                    try {
                        while (true) {
                            \Swoole\Coroutine::sleep(0.01);
                        }
                    } finally {
                        $finished->push(true);
                    }
                }, $sessionId);

                self::assertIsInt($cid);
                self::assertTrue($started->pop(1.0));

                $registered = $property->getValue();
                self::assertArrayHasKey($sessionId, $registered);
                self::assertArrayHasKey($cid, $registered[$sessionId]);

                $cancelMethod->invoke(null, $sessionId);

                self::assertTrue($finished->pop(1.0));

                \Swoole\Coroutine::sleep(0.02);
                self::assertSame([], $property->getValue());
            });
        } finally {
            $property->setValue(null, []);
        }
    }
}
