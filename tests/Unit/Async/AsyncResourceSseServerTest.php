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
    public function authorization_gate_allows_guest_deferred_requests(): void
    {
        self::assertNull($this->resolveSseAuthorizationError(
            authenticated: false,
            anonymousAllowed: false,
            demoStream: '',
            deferredRequestId: 'req-123',
        ));
    }

    #[Test]
    public function authorization_gate_rejects_guest_persistent_streams_without_opt_in(): void
    {
        self::assertSame(
            'Authorization is required for persistent SSE streams. Set SSE_PUBLIC_ANONYMOUS=true to opt in to anonymous persistent streams.',
            $this->resolveSseAuthorizationError(
                authenticated: false,
                anonymousAllowed: false,
                demoStream: '',
                deferredRequestId: '',
            ),
        );
    }

    #[Test]
    public function authorization_gate_rejects_guest_demo_streams(): void
    {
        self::assertSame(
            'Authorization is required for this SSE demo stream.',
            $this->resolveSseAuthorizationError(
                authenticated: false,
                anonymousAllowed: true,
                demoStream: 'clock',
                deferredRequestId: 'req-123',
            ),
        );
    }

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

    private function resolveSseAuthorizationError(
        bool $authenticated,
        bool $anonymousAllowed,
        string $demoStream,
        string $deferredRequestId,
    ): ?string {
        $method = new \ReflectionMethod(AsyncResourceSseServer::class, 'resolveSseAuthorizationError');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            $authenticated,
            $anonymousAllowed,
            $demoStream,
            $deferredRequestId,
        );

        return is_string($result) ? $result : null;
    }
}
