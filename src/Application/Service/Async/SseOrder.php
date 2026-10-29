<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Async;

use Swoole\Atomic\Long;

/**
 * The order in which a session's connections, subscriptions and coroutines
 * came to be, across every worker of the host.
 *
 * A reconnect reuses the page's session id, so the old connection's late
 * teardown tells its own work from the new connection's by asking what came
 * BEFORE the new connection claimed the session: subscription rows
 * ({@see SubscriptionTable}), the ownership claim ({@see SseWorkerTables}) and
 * the session's coroutines ({@see SseSessionCoroutines}) all take a number
 * from here.
 *
 * A COUNTER, NOT A CLOCK. These used to be monotonic milliseconds, and work
 * the old connection started in the same millisecond as the claim tied with
 * it: "started before the claim" was false for it, so the teardown neither
 * cancelled nor forgot it (CodeRabbit on ssr#149). Every number handed out
 * here is unique, so no two of them tie.
 */
final class SseOrder
{
    /**
     * @worker-scoped The static-state lint's word; the counter itself is
     * host-wide. Created by the master before the workers fork
     * ({@see \Semitexa\Ssr\Application\Service\Server\Lifecycle\CreateAsyncResourceSseTablesListener}).
     * An Atomic lives in shared memory, so every worker counts on the same one,
     * as it must: the tables these numbers are compared in are shared too.
     */
    private static ?Long $shared = null;

    /** Without a server (a console process, a test) there is one process, and a plain counter orders it. */
    private static int $local = 0;

    public static function share(Long $counter): void
    {
        // Never backwards: a number this process already handed out, from
        // its own counter or a counter shared before this one, must stay
        // smaller than every number handed out after it.
        $floor = max(self::$local, self::$shared?->get() ?? 0);
        if ($counter->get() < $floor) {
            $counter->set($floor);
        }
        self::$shared = $counter;
    }

    public static function next(): int
    {
        return self::$shared !== null ? self::$shared->add(1) : ++self::$local;
    }
}
