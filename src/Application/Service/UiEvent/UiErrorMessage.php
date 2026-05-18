<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * `ui.error` — operator-safe error surface delivered to the client over
 * the canonical SSE channel.
 *
 * Body-safety rule (matches {@see UiResponseDispatchResult::$message}):
 * `$reason` is a stable machine-readable code; `$message` is operator-
 * safe text. Neither field may carry exception traces, FQCNs, raw
 * tokens, signed-context contents, or secret env values. Publishers are
 * responsible for upstream sanitisation — this VO does not strip; it
 * documents the contract and validates only the shape (non-empty
 * reason).
 */
final readonly class UiErrorMessage implements UiSseMessageInterface
{
    /**
     * @param string      $reason        Stable machine-readable reason code
     *                                   (e.g. `validation_failed`,
     *                                   `dispatcher_unavailable`). MUST NOT
     *                                   be derived from an exception's FQCN
     *                                   or message.
     * @param string      $message       Operator-safe human message. MUST
     *                                   NOT leak internals — see class
     *                                   docblock.
     * @param string|null $correlationId Optional correlation id for tracing.
     */
    public function __construct(
        public string $reason,
        public string $message,
        public ?string $correlationId = null,
    ) {
        if ($this->reason === '') {
            throw new \InvalidArgumentException('UiErrorMessage: reason must not be empty.');
        }
    }

    public function type(): UiSseEventType
    {
        return UiSseEventType::UiError;
    }

    public function toSsePayload(): array
    {
        $payload = [
            '_type'   => UiSseEventType::UiError->value,
            'reason'  => $this->reason,
            'message' => $this->message,
        ];

        if ($this->correlationId !== null && $this->correlationId !== '') {
            $payload['correlationId'] = $this->correlationId;
        }

        return $payload;
    }
}
