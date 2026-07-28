<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseConnectionLimiter;

/**
 * The cap protocol, now that it exists exactly once.
 *
 * Before `tk-sse-connection-limiter` this sequence was written twice — once for
 * the KISS admit path, once for the held-open resource stream — and neither copy
 * had a direct test. These cases pin the parts that a second copy would have
 * been free to get subtly wrong: which cap is reported when both are breached,
 * that the caps are `>=` and not `>`, and that an unattributable connection gets
 * no per-IP entry yet still occupies a global slot.
 */
final class SseConnectionLimiterTest extends TestCase
{
    /** @var list<string> */
    private array $touchedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $key) {
            putenv($key);
        }
        $this->touchedEnv = [];
    }

    #[Test]
    public function a_connection_under_both_caps_is_admitted_and_counted(): void
    {
        $limiter = new SseConnectionLimiter();

        self::assertNull($limiter->tryAcquire('10.0.0.1', 'sess-1', new \stdClass()));
        self::assertSame(1, $limiter->openConnectionsFor('10.0.0.1'));
        self::assertSame(1, $limiter->totalOpenConnections());
    }

    #[Test]
    public function the_per_ip_cap_is_inclusive(): void
    {
        $this->setEnv('SSE_MAX_CONN_PER_IP', '2');
        $limiter = new SseConnectionLimiter();

        self::assertNull($limiter->tryAcquire('10.0.0.1', 's1', new \stdClass()));
        self::assertNull($limiter->tryAcquire('10.0.0.1', 's2', new \stdClass()));

        self::assertSame(
            SseConnectionLimiter::DENIED_PER_IP,
            $limiter->tryAcquire('10.0.0.1', 's3', new \stdClass()),
            'the cap is a ceiling — the Nth+1 connection is refused, not the Nth',
        );
        self::assertSame(2, $limiter->openConnectionsFor('10.0.0.1'), 'a refused connection is not counted');
    }

    #[Test]
    public function a_different_ip_is_unaffected_by_another_ip_hitting_its_cap(): void
    {
        $this->setEnv('SSE_MAX_CONN_PER_IP', '1');
        $limiter = new SseConnectionLimiter();

        $limiter->tryAcquire('10.0.0.1', 's1', new \stdClass());

        self::assertSame(SseConnectionLimiter::DENIED_PER_IP, $limiter->tryAcquire('10.0.0.1', 's2', new \stdClass()));
        self::assertNull($limiter->tryAcquire('10.0.0.2', 's3', new \stdClass()));
    }

    #[Test]
    public function the_global_cap_outranks_the_per_ip_cap(): void
    {
        // A worker at capacity reports the worker-level reason rather than
        // blaming the caller's IP, even when that IP is also at its own cap.
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '1');
        $this->setEnv('SSE_MAX_CONN_PER_IP', '1');
        $limiter = new SseConnectionLimiter();

        $limiter->tryAcquire('10.0.0.1', 's1', new \stdClass());

        self::assertSame(SseConnectionLimiter::DENIED_GLOBAL, $limiter->tryAcquire('10.0.0.1', 's2', new \stdClass()));
    }

    #[Test]
    public function the_global_cap_sums_across_ips(): void
    {
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '2');
        $limiter = new SseConnectionLimiter();

        $limiter->tryAcquire('10.0.0.1', 's1', new \stdClass());
        $limiter->tryAcquire('10.0.0.2', 's2', new \stdClass());

        self::assertSame(SseConnectionLimiter::DENIED_GLOBAL, $limiter->tryAcquire('10.0.0.3', 's3', new \stdClass()));
    }

    #[Test]
    public function an_unresolvable_client_ip_is_admitted_without_a_per_ip_entry(): void
    {
        $limiter = new SseConnectionLimiter();

        self::assertNull($limiter->tryAcquire('', 'sess-1', new \stdClass()));
        self::assertSame(0, $limiter->attributedOpenConnections(), 'there is nobody to attribute it to');
        self::assertSame(1, $limiter->totalOpenConnections(), 'but it still occupies a global slot');
    }

    #[Test]
    public function an_unattributable_connection_still_faces_the_global_cap(): void
    {
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '1');
        $limiter = new SseConnectionLimiter();

        $limiter->tryAcquire('10.0.0.1', 's1', new \stdClass());

        self::assertSame(
            SseConnectionLimiter::DENIED_GLOBAL,
            $limiter->tryAcquire('', 's2', new \stdClass()),
            'an unattributable connection still consumes a worker coroutine and fd',
        );
    }

    #[Test]
    public function a_flood_of_unattributable_connections_cannot_bypass_the_global_cap(): void
    {
        // Review caught that the previous test passed only because a COUNTED
        // connection was admitted first. Deriving the global total by summing the
        // per-IP map missed unattributable connections entirely, so a flood of
        // them raised nothing and every single one passed the cap.
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '2');
        $limiter = new SseConnectionLimiter();

        self::assertNull($limiter->tryAcquire('', 's1', new \stdClass()));
        self::assertNull($limiter->tryAcquire('', 's2', new \stdClass()));

        self::assertSame(
            SseConnectionLimiter::DENIED_GLOBAL,
            $limiter->tryAcquire('', 's3', new \stdClass()),
            'with no attributable connection present at all, the cap must still bite',
        );
    }

    #[Test]
    public function releasing_an_unattributable_connection_frees_its_global_slot(): void
    {
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '1');
        $limiter = new SseConnectionLimiter();
        $first = new \stdClass();

        $limiter->tryAcquire('', 's1', $first);
        self::assertSame(SseConnectionLimiter::DENIED_GLOBAL, $limiter->tryAcquire('', 's2', new \stdClass()));

        $limiter->release('s1', $first);

        self::assertNull($limiter->tryAcquire('', 's3', new \stdClass()), 'the freed slot is reusable');
        self::assertSame(0, $limiter->attributedOpenConnections(), 'and it was never attributed to an IP');
    }

    #[Test]
    public function the_two_counters_report_different_things(): void
    {
        $limiter = new SseConnectionLimiter();
        $limiter->tryAcquire('10.0.0.1', 's1', new \stdClass());
        $limiter->tryAcquire('', 's2', new \stdClass());

        self::assertSame(2, $limiter->totalOpenConnections(), 'every open connection');
        self::assertSame(1, $limiter->attributedOpenConnections(), 'only the ones with an IP');
    }

    #[Test]
    public function releasing_an_uncounted_connection_is_a_no_op(): void
    {
        $limiter = new SseConnectionLimiter();
        $response = new \stdClass();

        $limiter->tryAcquire('', 'sess-1', $response);
        $limiter->release('sess-1', $response);

        self::assertSame(0, $limiter->totalOpenConnections());
        self::assertSame(0, $limiter->attributedOpenConnections());
    }

    #[Test]
    public function release_frees_the_slot_for_a_new_connection(): void
    {
        $this->setEnv('SSE_MAX_CONN_PER_IP', '1');
        $limiter = new SseConnectionLimiter();
        $first = new \stdClass();

        $limiter->tryAcquire('10.0.0.1', 's1', $first);
        self::assertSame(SseConnectionLimiter::DENIED_PER_IP, $limiter->tryAcquire('10.0.0.1', 's2', new \stdClass()));

        $limiter->release('s1', $first);

        self::assertNull($limiter->tryAcquire('10.0.0.1', 's3', new \stdClass()), 'the freed slot is reusable');
    }

    #[Test]
    public function a_malformed_cap_falls_back_to_the_shipped_default(): void
    {
        // A typo in the knob must not turn the caps off or take SSE down; it
        // falls back to the default of 5 per IP.
        $this->setEnv('SSE_MAX_CONN_PER_IP', 'lots');
        $limiter = new SseConnectionLimiter();

        for ($i = 0; $i < 5; $i++) {
            self::assertNull($limiter->tryAcquire('10.0.0.1', 's' . $i, new \stdClass()));
        }

        self::assertSame(SseConnectionLimiter::DENIED_PER_IP, $limiter->tryAcquire('10.0.0.1', 's5', new \stdClass()));
    }

    #[Test]
    public function reset_drops_all_accounting(): void
    {
        $limiter = new SseConnectionLimiter();
        $limiter->tryAcquire('10.0.0.1', 's1', new \stdClass());

        $limiter->reset();

        self::assertSame(0, $limiter->totalOpenConnections());
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $this->touchedEnv[] = $key;
    }
}
