<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Security\AppSecret;

/**
 * Shared APP_SECRET resolution for the signed-context substrate.
 *
 * The one secret rule for every signed component context:
 *   - APP_SECRET is required outside dev/test;
 *   - dev/test fall back to a deterministic derivative of APP_NAME/HOST/PORT.
 *
 * Keeping the resolver in one place avoids divergence between component
 * events (existing) and UI-event envelopes (new) when APP_SECRET rotates.
 */
final class SignedContextSecret
{
    public static function resolve(): string
    {
        // The rule lives in core now, shared with encryption and signed URLs.
        return AppSecret::current();
    }
}
