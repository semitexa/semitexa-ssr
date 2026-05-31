<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\UiEvent;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;

final class UiSseEventTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function allowed_event_names(): iterable
    {
        yield 'ssr.fragment'      => ['ssr.fragment'];
        yield 'ui.patch'          => ['ui.patch'];
        yield 'ui.componentState' => ['ui.componentState'];
        yield 'ui.error'          => ['ui.error'];
        yield 'ui.grid.data'      => ['ui.grid.data'];
        yield 'ui.grid.error'     => ['ui.grid.error'];
    }

    #[Test]
    #[DataProvider('allowed_event_names')]
    public function each_documented_event_name_is_allowed(string $type): void
    {
        self::assertTrue(UiSseEventType::isAllowed($type));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejected_event_names(): iterable
    {
        yield 'empty'                       => [''];
        yield 'unrelated'                   => ['foo.bar'];
        yield 'newline_injection_attempt'   => ["ui.patch\nevent: spoof"];
        yield 'carriage_return_attempt'     => ["ui.patch\revent: spoof"];
        yield 'case_mismatch'               => ['Ui.Patch'];
        yield 'trailing_whitespace'         => ['ui.patch '];
        yield 'arbitrary_string'            => ['notification'];
    }

    #[Test]
    #[DataProvider('rejected_event_names')]
    public function arbitrary_strings_are_not_allowed(string $type): void
    {
        self::assertFalse(UiSseEventType::isAllowed($type));
    }

    #[Test]
    public function allowed_values_lists_exactly_the_documented_types(): void
    {
        self::assertSame(
            ['ssr.fragment', 'ui.patch', 'ui.componentState', 'ui.error', 'ui.grid.data', 'ui.grid.error'],
            UiSseEventType::allowedValues(),
        );
    }
}
