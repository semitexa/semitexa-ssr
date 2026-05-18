<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\UiEvent\UiErrorMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;

final class UiErrorMessageTest extends TestCase
{
    #[Test]
    public function type_is_ui_error(): void
    {
        $message = new UiErrorMessage('validation_failed', 'Field is required.');
        self::assertSame(UiSseEventType::UiError, $message->type());
    }

    #[Test]
    public function payload_carries_typed_discriminator_reason_and_message(): void
    {
        $message = new UiErrorMessage(
            reason: 'dispatcher_unavailable',
            message: 'The UI dispatcher is temporarily unavailable.',
        );

        self::assertSame(
            [
                '_type'   => 'ui.error',
                'reason'  => 'dispatcher_unavailable',
                'message' => 'The UI dispatcher is temporarily unavailable.',
            ],
            $message->toSsePayload(),
        );
    }

    #[Test]
    public function correlation_id_is_included_only_when_provided(): void
    {
        $with = new UiErrorMessage('x', 'y', 'corr-z');
        $without = new UiErrorMessage('x', 'y');

        self::assertSame('corr-z', $with->toSsePayload()['correlationId']);
        self::assertArrayNotHasKey('correlationId', $without->toSsePayload());
    }

    #[Test]
    public function payload_does_not_carry_any_unsanctioned_keys(): void
    {
        $message = new UiErrorMessage('r', 'm', 'c');
        self::assertSame(
            ['_type', 'reason', 'message', 'correlationId'],
            array_keys($message->toSsePayload()),
        );
    }

    #[Test]
    public function empty_reason_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UiErrorMessage('', 'something went wrong');
    }
}
