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
    private const PURPOSE = 'ssr.signed-context';

    /** The raw APP_SECRET. */
    public static function resolve(): string
    {
        // The rule lives in core now, shared with encryption and signed URLs.
        return AppSecret::current();
    }

    /** The key a new context is signed with: APP_SECRET's own for this purpose. */
    public static function signingKey(): string
    {
        return AppSecret::derive(AppSecret::current(), self::PURPOSE);
    }

    /**
     * Every key a context may carry, the signing key first. Contexts were
     * signed with the raw APP_SECRET and APP_SECRET_PREVIOUS was never read:
     * rotating the secret failed every click on every open page (refresh
     * contexts live a day). The previous secrets verify now, and so does the
     * raw-secret signature of a page opened before this release — drop those
     * once no such page can be open.
     *
     * @return non-empty-list<string>
     */
    public static function verifyingKeys(): array
    {
        $secrets = [AppSecret::current(), ...AppSecret::previous()];

        return [
            ...array_map(static fn (string $secret): string => AppSecret::derive($secret, self::PURPOSE), $secrets),
            ...$secrets,
        ];
    }
}
