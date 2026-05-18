<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * `ui.patch` — partial state delta applied to a single component
 * instance. The body is a JSON-serialisable patch the client runtime
 * applies on top of its current component state.
 *
 * The publisher MUST NOT pack server-internal data (FQCNs, signed-
 * context contents, secrets) into `$patch`; only fields the JS client
 * is allowed to see.
 */
final readonly class UiPatchMessage implements UiSseMessageInterface
{
    /**
     * @param string               $componentInstanceId Stable id of the target
     *                                                  component instance — the
     *                                                  same id used in the
     *                                                  rendered DOM / dispatch
     *                                                  payloads.
     * @param array<string, mixed> $patch               Operator-safe patch body.
     * @param string|null          $correlationId       Optional correlation id
     *                                                  for tracing the patch
     *                                                  back to the originating
     *                                                  UI event / dispatch.
     */
    public function __construct(
        public string $componentInstanceId,
        public array $patch,
        public ?string $correlationId = null,
    ) {}

    public function type(): UiSseEventType
    {
        return UiSseEventType::UiPatch;
    }

    public function toSsePayload(): array
    {
        $payload = [
            '_type'               => UiSseEventType::UiPatch->value,
            'componentInstanceId' => $this->componentInstanceId,
            'patch'               => $this->patch,
        ];

        if ($this->correlationId !== null && $this->correlationId !== '') {
            $payload['correlationId'] = $this->correlationId;
        }

        return $payload;
    }
}
