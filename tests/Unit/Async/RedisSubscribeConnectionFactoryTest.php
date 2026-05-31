<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\RedisSubscribeConnectionFactory;

/**
 * Track R · Gap C regression — the dedicated subscribe connection must DISABLE the
 * idle read/write timeout.
 *
 * The held-open live-update silently stopped after ~60s because the R3 subscriber's
 * dedicated Predis `pubSubLoop` inherited PHP's default_socket_timeout: an idle
 * SUBSCRIBE threw a ConnectionException and the loop died with no reconnect. The fix
 * sets `read_write_timeout: -1` (block indefinitely — the correct lifetime for a
 * parked SUBSCRIBE). This asserts the connection the factory vends carries it, so a
 * regression that drops the setting fails here rather than silently in production.
 *
 * (This is a pure construction guard — no Redis connection is opened. The REAL
 * blocking-loop + real-publish path is proven live; a self-contained loop test is
 * blocked by a Swoole/Predis testability limit: `Coroutine::cancel` does not
 * reliably interrupt Predis's blocking pubSubLoop read, so the subscriber would need
 * an injectable/closable connection or a stop signal to be loop-testable. Noted as
 * the recommended follow-up.)
 */
final class RedisSubscribeConnectionFactoryTest extends TestCase
{
    #[Test]
    public function the_dedicated_connection_disables_the_idle_read_write_timeout(): void
    {
        $factory = new RedisSubscribeConnectionFactory([
            'scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '',
        ]);

        // create() builds a lazy Predis client (no socket opened); the parameters
        // are readable without connecting.
        $client = $factory->create();
        $params = $client->getConnection()->getParameters();

        self::assertSame(
            -1,
            (int) $params->read_write_timeout,
            'Gap C: the dedicated subscribe connection must set read_write_timeout: -1 '
            . 'so a parked SUBSCRIBE never idle-times-out and kills the R3 loop.',
        );
    }

    #[Test]
    public function the_password_is_only_set_when_present(): void
    {
        $noPass = (new RedisSubscribeConnectionFactory([
            'scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => '',
        ]))->create();
        self::assertNull($noPass->getConnection()->getParameters()->password);

        $withPass = (new RedisSubscribeConnectionFactory([
            'scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'password' => 's3cret',
        ]))->create();
        self::assertSame('s3cret', $withPass->getConnection()->getParameters()->password);
    }
}
