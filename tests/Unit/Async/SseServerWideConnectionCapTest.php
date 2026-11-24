<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\Lifecycle\ServerBootstrapState;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Ssr\Application\Service\Async\SseConnectionGauge;
use Semitexa\Ssr\Application\Service\Async\SseConnectionLimiter;
use Semitexa\Ssr\Application\Service\Async\SseOrder;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\AsyncResourceSseTables;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\CreateAsyncResourceSseTablesListener;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\SsrBootstrapStateKey;

/**
 * `SSE_MAX_CONN_GLOBAL` is enforced for the server, not for each worker.
 *
 * The stand (semitexa-bench ssr2, 1900 visitors on 4 workers) saw 275 refusals
 * at about 900 open streams of a nominal 2000: dispatch_mode 2 puts a
 * connection on `fd % workers`, one worker took most of them and hit its own
 * 500. The split used here, 41/99/250/504, is the shape measured there.
 *
 * One limiter stands for one worker; they share one {@see SseConnectionGauge},
 * as the forked workers share the one created before the fork.
 */
final class SseServerWideConnectionCapTest extends TestCase
{
    private const WORKERS = 4;

    /** @var list<string> */
    private array $touchedEnv = [];

    /** @var list<object> Held so no two connections share a `spl_object_id`. */
    private array $responses = [];

    private int $opened = 0;

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $key) {
            putenv($key);
        }
        $this->touchedEnv = [];
        $this->responses = [];
        SseConnectionLimiter::shareAcrossWorkers(null);
    }

    #[Test]
    public function a_skewed_split_is_admitted_up_to_the_server_wide_cap_and_refused_past_it(): void
    {
        // Default SSE_MAX_CONN_GLOBAL = 500: the server holds 4 x 500 = 2000.
        $gauge = SseConnectionGauge::create(self::WORKERS);
        $workers = $this->workers($gauge);

        // The measured split. The fourth worker is past 500, where it refused
        // while the server held 894.
        foreach ([41, 99, 250, 504] as $workerId => $count) {
            self::assertSame($count, $this->open($workers[$workerId], $count), "worker {$workerId} refused inside the server-wide cap");
        }
        self::assertSame(894, $gauge->total());

        // Up to the cap: 41 + 99 + 860 + 1000 = 2000.
        self::assertSame(610, $this->open($workers[2], 610));
        self::assertSame(496, $this->open($workers[3], 496));
        self::assertSame(2000, $gauge->total());

        // Past it: refused on a worker that holds 41, far below its own ceiling.
        self::assertSame(SseConnectionLimiter::DENIED_SERVER, $workers[0]->tryAcquire('', 'over', new \stdClass()));
        self::assertSame('SSE connection cap reached for this server.', SseConnectionLimiter::DENIED_SERVER);
        self::assertSame(41, $workers[0]->totalOpenConnections(), 'a refused connection is not counted');
        self::assertSame(2000, $gauge->total());
    }

    #[Test]
    public function one_worker_is_refused_at_twice_the_cap_while_the_server_has_room(): void
    {
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '10');
        $gauge = SseConnectionGauge::create(self::WORKERS);
        $workers = $this->workers($gauge);

        // The server holds 40; one worker holds at most 2 x 10 = 20.
        self::assertSame(20, $this->open($workers[1], 20));

        self::assertSame(SseConnectionLimiter::DENIED_WORKER, $workers[1]->tryAcquire('', 'over', new \stdClass()));
        self::assertSame('SSE connection cap reached for this worker.', SseConnectionLimiter::DENIED_WORKER);
        self::assertSame(20, $gauge->total());
        self::assertNull($workers[0]->tryAcquire('', 'elsewhere', new \stdClass()), 'another worker still has room');
    }

    #[Test]
    public function release_gives_the_slot_back_to_the_whole_server(): void
    {
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '2');
        $gauge = SseConnectionGauge::create(self::WORKERS);
        $workers = $this->workers($gauge);

        // 4 + 4 = 8, the server-wide cap; both workers are at their ceiling of 4.
        $held = new \stdClass();
        self::assertNull($workers[0]->tryAcquire('10.0.0.1', 'held', $held));
        self::assertSame(3, $this->open($workers[0], 3));
        self::assertSame(4, $this->open($workers[1], 4));
        self::assertSame(8, $gauge->total());
        self::assertSame(SseConnectionLimiter::DENIED_SERVER, $workers[2]->tryAcquire('', 'over', new \stdClass()));

        $workers[0]->release('held', $held);

        self::assertSame(7, $gauge->total());
        self::assertSame(3, $gauge->openOn(0));
        self::assertNull($workers[2]->tryAcquire('', 'after', new \stdClass()), 'a slot released on one worker admits on another');
        self::assertSame(8, $gauge->total());

        $workers[0]->release('held', $held);
        self::assertSame(8, $gauge->total(), 'a second release of the same connection takes nothing off');
    }

    /**
     * Swoole rounds a table up to a power of two and keeps 20% of it for
     * collisions by default: with the keys "0".."N-1" some claims failed from
     * 48 workers up, and a worker whose claim failed never published (review of
     * this fix).
     */
    #[Test]
    public function every_one_of_sixty_four_workers_gets_a_row_that_counts(): void
    {
        $gauge = SseConnectionGauge::create(64);
        for ($workerId = 0; $workerId < 64; $workerId++) {
            $gauge->claim($workerId, 10_000 + $workerId);
            $gauge->publish($workerId, 10_000 + $workerId, 1);
        }

        self::assertSame(64, $gauge->total());
        self::assertSame(1, $gauge->openOn(63));
    }

    #[Test]
    public function a_worker_start_resets_its_own_row_and_only_its_own(): void
    {
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '10');
        $gauge = SseConnectionGauge::create(self::WORKERS);
        $workers = $this->workers($gauge);

        self::assertSame(7, $this->open($workers[0], 7));
        self::assertSame(5, $this->open($workers[1], 5));
        self::assertSame(12, $gauge->total());

        // Worker 1 crashed: its 5 releases never ran. Swoole starts a new
        // process under the same worker id.
        $respawned = new SseConnectionLimiter();
        $respawned->shareWith($gauge, 1, 2001);

        self::assertSame(0, $gauge->openOn(1));
        self::assertSame(7, $gauge->openOn(0));
        self::assertSame(7, $gauge->total());

        self::assertNull($respawned->tryAcquire('', 'new', new \stdClass()));
        self::assertSame(1, $gauge->openOn(1));
    }

    #[Test]
    public function the_worker_start_binding_reaches_a_limiter_created_before_or_after_it(): void
    {
        // What BindAsyncResourceSseServerListener does: the facade's limiter
        // is created lazily, so the worker's place is set for the process.
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '2');
        $gauge = SseConnectionGauge::create(self::WORKERS);
        $before = new SseConnectionLimiter();

        SseConnectionLimiter::shareAcrossWorkers($gauge, 3, 1003);
        $after = new SseConnectionLimiter();

        self::assertSame(3, $this->open($before, 3), 'past 2, the per-worker cap without shared memory');
        self::assertSame(3, $gauge->openOn(3));
        self::assertSame(1, $this->open($after, 1));
        self::assertSame(1, $gauge->openOn(3), 'the row holds what the worker\'s limiter last counted');

        // Worker 3 is respawned: the start resets its row.
        SseConnectionLimiter::shareAcrossWorkers($gauge, 3, 2003);
        self::assertSame(0, $gauge->openOn(3));
        self::assertSame(0, $gauge->total());
    }

    #[Test]
    public function the_server_creates_the_shared_counts_before_the_fork_sized_by_its_worker_count(): void
    {
        $this->setEnv('SWOOLE_WORKER_NUM', '6');
        $state = new ServerBootstrapState();
        // The listener also shares SseOrder's counter; put this process's back.
        $order = new \ReflectionProperty(SseOrder::class, 'shared');
        $previousOrder = $order->getValue();

        try {
            (new CreateAsyncResourceSseTablesListener())->handle(new ServerLifecycleContext(
                server: null,
                workerId: null,
                environment: Environment::create(),
                bootstrapState: $state,
            ));
        } finally {
            $order->setValue(null, $previousOrder);
        }

        $tables = $state->get(SsrBootstrapStateKey::ASYNC_RESOURCE_SSE_TABLES);
        self::assertInstanceOf(AsyncResourceSseTables::class, $tables);
        self::assertSame(6, $tables->connectionGauge?->workerCount());
    }

    #[Test]
    public function a_replaced_worker_that_is_still_draining_cannot_write_into_its_successors_row(): void
    {
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '10');
        $gauge = SseConnectionGauge::create(self::WORKERS);
        $old = new SseConnectionLimiter();
        $old->shareWith($gauge, 0, 1000);
        $closing = new \stdClass();
        self::assertNull($old->tryAcquire('', 'closing', $closing));
        self::assertSame(3, $this->open($old, 3));

        // A reload: the new worker 0 starts while the old one closes its streams.
        $new = new SseConnectionLimiter();
        $new->shareWith($gauge, 0, 1001);
        self::assertSame(2, $this->open($new, 2));

        $old->release('closing', $closing);

        self::assertSame(2, $gauge->openOn(0), 'the old worker wrote its own count over the new worker\'s');
        self::assertSame(3, $old->totalOpenConnections(), 'the old worker still counts for itself');

        self::assertNull($new->tryAcquire('', 'next', new \stdClass()));
        self::assertSame(3, $gauge->openOn(0), 'the row no longer belongs to the worker that claimed it');
    }

    #[Test]
    public function without_shared_memory_the_cap_is_per_worker_as_before(): void
    {
        $this->setEnv('SSE_MAX_CONN_GLOBAL', '3');
        $limiter = new SseConnectionLimiter();

        self::assertSame(3, $this->open($limiter, 3));

        $denial = $limiter->tryAcquire('', 'over', new \stdClass());
        self::assertSame('SSE connection cap reached for this worker.', $denial);
        self::assertSame(SseConnectionLimiter::DENIED_WORKER, $denial);
        self::assertSame(3, $limiter->totalOpenConnections());
    }

    #[Test]
    public function the_per_ip_cap_is_still_counted_on_the_worker(): void
    {
        $this->setEnv('SSE_MAX_CONN_PER_IP', '1');
        $gauge = SseConnectionGauge::create(self::WORKERS);
        $workers = $this->workers($gauge);

        self::assertNull($workers[0]->tryAcquire('10.0.0.1', 's1', new \stdClass()));

        self::assertSame(SseConnectionLimiter::DENIED_PER_IP, $workers[0]->tryAcquire('10.0.0.1', 's2', new \stdClass()));
        self::assertSame(1, $gauge->total(), 'a connection refused for its IP is not counted for the server');
    }

    /** @return list<SseConnectionLimiter> One limiter per worker id, pids 1000.. */
    private function workers(SseConnectionGauge $gauge): array
    {
        $workers = [];
        for ($workerId = 0; $workerId < self::WORKERS; $workerId++) {
            $limiter = new SseConnectionLimiter();
            $limiter->shareWith($gauge, $workerId, 1000 + $workerId);
            $workers[] = $limiter;
        }

        return $workers;
    }

    /** Open up to $count unattributed connections; returns how many were admitted. */
    private function open(SseConnectionLimiter $limiter, int $count): int
    {
        $admitted = 0;
        for ($i = 0; $i < $count; $i++) {
            $response = new \stdClass();
            $this->responses[] = $response;
            if ($limiter->tryAcquire('', 'conn-' . ++$this->opened, $response) !== null) {
                break;
            }
            $admitted++;
        }

        return $admitted;
    }

    private function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $this->touchedEnv[] = $key;
    }
}
