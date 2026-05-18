<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\UiEvent\UiComponentStateMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;

final class UiComponentStateMessageTest extends TestCase
{
    #[Test]
    public function type_is_ui_component_state(): void
    {
        $message = new UiComponentStateMessage('cmp-1', ['rows' => []]);
        self::assertSame(UiSseEventType::UiComponentState, $message->type());
    }

    #[Test]
    public function payload_carries_typed_discriminator_and_full_state_body(): void
    {
        $state = ['rows' => [['id' => 1], ['id' => 2]], 'cursor' => 'next'];
        $message = new UiComponentStateMessage(
            componentInstanceId: 'grid-leads',
            state: $state,
        );

        self::assertSame(
            [
                '_type'               => 'ui.componentState',
                'componentInstanceId' => 'grid-leads',
                'state'               => $state,
            ],
            $message->toSsePayload(),
        );
    }

    #[Test]
    public function correlation_id_is_included_only_when_provided(): void
    {
        $with = new UiComponentStateMessage('cmp-1', [], 'corr-abc');
        $without = new UiComponentStateMessage('cmp-1', []);

        self::assertSame('corr-abc', $with->toSsePayload()['correlationId']);
        self::assertArrayNotHasKey('correlationId', $without->toSsePayload());
    }
}
