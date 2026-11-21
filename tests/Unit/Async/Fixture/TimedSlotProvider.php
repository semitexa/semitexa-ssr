<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async\Fixture;

use Semitexa\Ssr\Domain\Contract\DataProviderInterface;
use Semitexa\Ssr\Domain\Model\DataProviderContext;
use Semitexa\Ssr\Application\Service\Async\SseSessionCoroutines;
use Swoole\Coroutine;

/**
 * A slot's data provider that waits as long as its slot is told to, and writes
 * down where it ran: the coroutine, how many ran beside it, and whatever the
 * test asks it to look at from inside.
 */
final class TimedSlotProvider implements DataProviderInterface
{
    /** @var array<string, int> slot id => how long its data takes, ms */
    public static array $waits = [];

    /** @var list<string> the slots whose data fails */
    public static array $failing = [];

    /** @var array<string, int> slot id => the coroutine its data resolved in */
    public static array $ranIn = [];

    /** @var array<string, array<string, mixed>> slot id => what {@see $look} saw from inside */
    public static array $saw = [];

    /** @var (\Closure(): array<string, mixed>)|null */
    public static ?\Closure $look = null;

    public static int $running = 0;

    public static int $peak = 0;

    public static function reset(): void
    {
        self::$waits = [];
        self::$failing = [];
        self::$ranIn = [];
        self::$saw = [];
        self::$look = null;
        self::$running = 0;
        self::$peak = 0;
    }

    public function resolve(DataProviderContext $context, array $hint = []): array
    {
        $slot = (string) $context->slotId;
        self::$ranIn[$slot] = SseSessionCoroutines::currentCid();
        if (self::$look !== null) {
            self::$saw[$slot] = (self::$look)();
        }
        self::$peak = max(self::$peak, ++self::$running);
        try {
            $seconds = (self::$waits[$slot] ?? 0) / 1000;
            if (Coroutine::getCid() > 0) {
                Coroutine::sleep($seconds);
            } else {
                usleep((int) ($seconds * 1_000_000));
            }
        } finally {
            self::$running--;
        }
        if (in_array($slot, self::$failing, true)) {
            throw new \RuntimeException('the data of ' . $slot . ' is not there');
        }

        return ['label' => 'data of ' . $slot];
    }
}
