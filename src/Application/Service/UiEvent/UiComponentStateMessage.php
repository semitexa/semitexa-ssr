<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * `ui.componentState` — whole-state snapshot for a single component
 * instance. Intended for cases where a patch cannot express the change
 * compactly (initial hydration, full reset, future grid envelopes).
 *
 * Same body-safety rule as {@see UiPatchMessage}: only fields the JS
 * client is allowed to see.
 */
final readonly class UiComponentStateMessage implements UiSseMessageInterface
{
    /**
     * @param string               $componentInstanceId Stable id of the target
     *                                                  component instance.
     * @param array<string, mixed> $state               Operator-safe state body.
     * @param string|null          $correlationId       Optional correlation id.
     */
    public function __construct(
        public string $componentInstanceId,
        public array $state,
        public ?string $correlationId = null,
    ) {
        if ($this->componentInstanceId === '') {
            throw new \InvalidArgumentException('UiComponentStateMessage: componentInstanceId must not be empty.');
        }
    }

    public function type(): UiSseEventType
    {
        return UiSseEventType::UiComponentState;
    }

    public function toSsePayload(): array
    {
        $payload = [
            '_type'               => UiSseEventType::UiComponentState->value,
            'componentInstanceId' => $this->componentInstanceId,
            'state'               => $this->state,
        ];

        if ($this->correlationId !== null && $this->correlationId !== '') {
            $payload['correlationId'] = $this->correlationId;
        }

        return $payload;
    }
}
