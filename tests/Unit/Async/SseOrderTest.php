<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseOrder;
use Swoole\Atomic\Long;

/**
 * The order a reconnect's teardown is decided by: every number unique and
 * increasing, which is what milliseconds were not — two events in one
 * millisecond tied, and the teardown read the old connection's work as the
 * new one's.
 */
final class SseOrderTest extends TestCase
{
    private ?Long $previous = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = (new \ReflectionProperty(SseOrder::class, 'shared'))->getValue();
    }

    protected function tearDown(): void
    {
        // The counter is process-wide: put back whatever the suite was counting on.
        (new \ReflectionProperty(SseOrder::class, 'shared'))->setValue(null, $this->previous);
        parent::tearDown();
    }

    #[Test]
    public function back_to_back_numbers_never_tie(): void
    {
        $numbers = [];
        for ($i = 0; $i < 1000; $i++) {
            $numbers[] = SseOrder::next();
        }

        $sorted = $numbers;
        sort($sorted);
        self::assertSame($sorted, $numbers, 'a later number was smaller');
        self::assertSame($numbers, array_values(array_unique($numbers)), 'two numbers tied');
    }

    #[Test]
    #[RequiresPhpExtension('swoole')]
    public function sharing_the_counter_never_moves_it_backwards(): void
    {
        $before = SseOrder::next();

        // A fresh shared counter starts at 0, below what this process already handed out.
        $counter = new Long(0);
        SseOrder::share($counter);

        self::assertGreaterThan($before, SseOrder::next(), 'a number after sharing came before one handed out earlier');
        self::assertSame(SseOrder::next(), $counter->get(), 'the numbers no longer come from the shared counter');
    }
}
