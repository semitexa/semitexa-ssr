<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service;

use Semitexa\Core\Support\Row;
use Semitexa\Ssr\Application\Service\Async\SseServer;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Runs the data resolution of the deferred slots one HUG fallback request asks
 * for (`GET /__semitexa_hug?slots=…`) at once, so the request waits for its
 * slowest slot, not for their sum: three slots of 300/600/900 ms answered in
 * 1800 ms, one after another (tk-hug-fallback-renders-slots-in-sequence).
 *
 * Each runs in a coroutine made as the stream's own are
 * ({@see SseServer::createSessionCoroutine()}), which carries the request's
 * context into it: what the container injects, and the tenant, signed-in user
 * and locale the stores keep beside it. At most {@see MAX_AT_ONCE} run at a
 * time for one request; the rest wait their turn.
 *
 * Only the resolving is spread out. What is done with the answers (the Twig
 * render, in the slots' order) stays with the caller, in its own coroutine.
 * A plain helper the orchestrator builds, not a container service.
 */
final class DeferredSlotDataFanOut
{
    /** How many of one request's slots resolve at the same time. */
    public const MAX_AT_ONCE = 8;

    /**
     * Coroutines left free for what the resolving itself starts (a provider's
     * own fan-out, a pooled connection's reader) and for the worker's other requests.
     */
    public const HEADROOM = 64;

    /** What Swoole allows when nothing set max_coroutine. */
    private const SWOOLE_DEFAULT_MAX_COROUTINE = 100000;

    public function __construct(private readonly SseServer $sseServer)
    {
    }

    /**
     * Whether $count resolves may run at once here: inside a coroutine, more
     * than one of them, and room for their coroutines under Swoole's limit.
     *
     * Asked before the first coroutine, not found out from the one that is
     * refused: a refused Coroutine::create() ends the process with a segfault
     * once a coroutine HTTP server has run in it (Swoole 6.2.3), and nothing in
     * PHP can catch that. Near the limit the caller resolves one after another.
     */
    public static function fits(int $count): bool
    {
        if ($count < 2 || !class_exists(Channel::class, false) || !class_exists(Coroutine::class, false) || Coroutine::getCid() <= 0) {
            return false;
        }
        $stats = Coroutine::stats();
        $running = Row::asInt(is_array($stats) ? ($stats['coroutine_num'] ?? 0) : 0);

        return self::coroutineLimit() - $running >= min($count, self::MAX_AT_ONCE) + self::HEADROOM;
    }

    /**
     * Each resolve's answer under its own index, in the resolves' order; null
     * for one that did not finish (its coroutine was cancelled). A resolve
     * answers for its own failure: nothing it throws is caught here.
     *
     * Where they {@see fits()}, all have resolved before the first answer is
     * handed back. Where they do not (no coroutine, a single one, no room under
     * Swoole's limit) each resolves as its answer is asked for, in the caller's
     * own coroutine: one after another, as the fallback always did.
     *
     * @param list<\Closure(): mixed> $resolves
     * @return \Generator<int, mixed>
     */
    public function answers(array $resolves): \Generator
    {
        if (!self::fits(count($resolves))) {
            foreach ($resolves as $index => $resolve) {
                yield $index => $resolve();
            }

            return;
        }

        $answers = $this->resolved($resolves);
        foreach (array_keys($resolves) as $index) {
            yield $index => $answers[$index] ?? null;
        }
    }

    /**
     * @param list<\Closure(): mixed> $resolves
     * @return array<int, mixed>
     */
    private function resolved(array $resolves): array
    {
        /** @var list<int> $waiting */
        $waiting = array_keys($resolves);
        $answers = [];
        $workers = min(count($resolves), self::MAX_AT_ONCE);
        $finished = new Channel($workers);
        // Tracked like a stream's coroutines, under a name no stream has.
        $owner = 'hug-slots-' . SseSessionCoroutines::currentCid();

        $started = 0;
        for ($i = 0; $i < $workers; $i++) {
            $spawned = $this->sseServer->createSessionCoroutine(static function () use (&$waiting, &$answers, $resolves, $finished): void {
                try {
                    while (($index = array_shift($waiting)) !== null) {
                        $answers[$index] = $resolves[$index]();
                    }
                } finally {
                    $finished->push(true); // always: the caller waits for one per worker
                }
            }, $owner);
            if ($spawned !== false) {
                $started++;
            }
        }
        for ($i = 0; $i < $started; $i++) {
            $finished->pop();
        }
        // What no worker took: none could start, or one was cancelled mid-way.
        while (($index = array_shift($waiting)) !== null) {
            $answers[$index] = $resolves[$index]();
        }

        return $answers;
    }

    /**
     * Read the way Swoole reads it: both spellings, 0 meaning the default, and
     * the server's own setting, which Coroutine::getOptions() does not show.
     * The smallest is taken: resolving in turn when there was room costs time,
     * the other way costs the worker.
     */
    private static function coroutineLimit(): int
    {
        $options = Coroutine::getOptions();
        $options = is_array($options) ? $options : [];
        $env = getenv('SWOOLE_MAX_COROUTINE');
        $set = array_filter([
            Row::asInt($options['max_coroutine'] ?? 0),
            Row::asInt($options['max_coro_num'] ?? 0),
            is_string($env) && ctype_digit(trim($env)) ? (int) trim($env) : 0,
        ], static fn (int $limit): bool => $limit > 0);

        return $set === [] ? self::SWOOLE_DEFAULT_MAX_COROUTINE : min($set);
    }
}
