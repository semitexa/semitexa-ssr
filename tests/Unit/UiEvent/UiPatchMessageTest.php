<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Tests\Unit\UiEvent;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Ssr\Application\Service\UiEvent\UiPatchMessage;
use Semitexa\Ssr\Application\Service\UiEvent\UiSseEventType;

final class UiPatchMessageTest extends TestCase
{
    #[Test]
    public function type_is_ui_patch(): void
    {
        $message = new UiPatchMessage('cmp-1', ['x' => 1]);
        self::assertSame(UiSseEventType::UiPatch, $message->type());
    }

    #[Test]
    public function payload_carries_typed_discriminator_and_patch_body(): void
    {
        $message = new UiPatchMessage(
            componentInstanceId: 'cmp-1',
            patch: ['value' => 42, 'flag' => true],
        );

        self::assertSame(
            [
                '_type'               => 'ui.patch',
                'componentInstanceId' => 'cmp-1',
                'patch'               => ['value' => 42, 'flag' => true],
            ],
            $message->toSsePayload(),
        );
    }

    #[Test]
    public function correlation_id_is_included_only_when_provided(): void
    {
        $with = new UiPatchMessage('cmp-1', ['v' => 1], 'corr-123');
        $without = new UiPatchMessage('cmp-1', ['v' => 1]);
        $empty = new UiPatchMessage('cmp-1', ['v' => 1], '');

        self::assertArrayHasKey('correlationId', $with->toSsePayload());
        self::assertSame('corr-123', $with->toSsePayload()['correlationId']);

        self::assertArrayNotHasKey('correlationId', $without->toSsePayload());
        self::assertArrayNotHasKey('correlationId', $empty->toSsePayload());
    }

    #[Test]
    public function empty_component_instance_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UiPatchMessage('', ['v' => 1]);
    }
}
