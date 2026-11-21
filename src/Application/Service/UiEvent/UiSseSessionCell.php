<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

/**
 * The UI SSE session id of one request, held where several coroutines can
 * reach the same one: see {@see UiSseSessionState::shared()}.
 */
final class UiSseSessionCell
{
    public ?string $id = null;
}
