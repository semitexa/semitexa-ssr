<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\UiEvent;

use Semitexa\Core\Environment;

/**
 * Shared APP_SECRET resolution for the signed-context substrate.
 *
 * Mirrors the existing ComponentEventBridge::resolveSecret() rule so both
 * substrates remain on one security model:
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
        $explicit = Environment::getEnvValue('APP_SECRET');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $appEnv = strtolower((string) Environment::getEnvValue('APP_ENV', 'prod'));
        if (!in_array($appEnv, ['dev', 'test'], true)) {
            throw new \LogicException('APP_SECRET must be configured for signed UI event context outside dev/test environments.');
        }

        return hash(
            'sha256',
            implode('|', [
                (string) Environment::getEnvValue('APP_NAME', 'Semitexa'),
                (string) Environment::getEnvValue('APP_HOST', 'localhost'),
                (string) Environment::getEnvValue('APP_PORT', '8000'),
            ]),
        );
    }
}
