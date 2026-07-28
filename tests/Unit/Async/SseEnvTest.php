<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\Async;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\Async\SseEnv;

/**
 * The knob-parsing contract every SSE collaborator depends on.
 *
 * Worth its own file because the fallback behaviour is a deliberate operational
 * choice, not an implementation detail: a typo in an SSE tuning variable must
 * substitute the shipped default rather than take the endpoint down. The one
 * value that is NOT a fallback trigger is `0` — `SSE_MAX_CONNECTION_AGE_SECONDS=0`
 * legitimately disables the loop's age cap, so a parser that treated falsy as
 * missing would silently re-enable a cap the operator turned off.
 */
final class SseEnvTest extends TestCase
{
    private const PROBE = 'SEMITEXA_SSE_ENV_PROBE';

    #[Test]
    #[DataProvider('parseCases')]
    public function knobs_parse_or_fall_back(string $raw, int $expected, string $because): void
    {
        putenv(self::PROBE . '=' . $raw);
        try {
            self::assertSame($expected, SseEnv::int(self::PROBE, 42), $because);
        } finally {
            putenv(self::PROBE);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: string}>
     */
    public static function parseCases(): iterable
    {
        yield 'plain integer' => ['7', 7, 'the ordinary case'];
        yield 'surrounding whitespace' => ['  7  ', 7, 'a stray space in .env is not an error'];
        yield 'zero' => ['0', 0, 'zero is a real value — it disables the age cap'];
        yield 'empty' => ['', 42, 'an unset-but-declared variable falls back'];
        yield 'blank' => ['   ', 42, 'so does a whitespace-only value'];
        yield 'non-numeric' => ['soon', 42, 'a typo must not take SSE down'];
        yield 'float' => ['1.5', 42, 'these knobs are whole seconds/counts only'];
        yield 'negative' => ['-1', 42, 'a negative cap is meaningless, so it is refused'];
        yield 'numeric with suffix' => ['30s', 42, 'no unit parsing — reject rather than guess'];
    }

    #[Test]
    public function an_undeclared_variable_returns_the_default(): void
    {
        self::assertSame(42, SseEnv::int('SEMITEXA_SSE_DEFINITELY_UNSET_PROBE', 42));
    }

    #[Test]
    public function the_default_is_returned_verbatim_including_zero(): void
    {
        self::assertSame(0, SseEnv::int('SEMITEXA_SSE_DEFINITELY_UNSET_PROBE', 0));
    }
}
