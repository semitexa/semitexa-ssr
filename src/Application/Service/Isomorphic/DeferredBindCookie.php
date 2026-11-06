<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Isomorphic;

/**
 * The cookie that proves a stream or a fallback belongs to the page a deferred
 * request was issued to.
 *
 * One cookie per origin locked a tab out: a second tab overwrote it, and the first
 * was refused on every reconnect for as long as it stayed open. So the name carries
 * the request. The client removes it when it leaves the page, which keeps the count
 * at the tabs open. The plain name is still read, for a client from before this.
 */
final class DeferredBindCookie
{
    public const NAME = 'semitexa_ssr_bind';

    public static function nameFor(string $requestId): string
    {
        return self::NAME . '_' . $requestId;
    }

    /** @param array<array-key, mixed> $cookies */
    public static function tokenIn(array $cookies, string $requestId): string
    {
        $own = $cookies[self::nameFor($requestId)] ?? null;
        $token = is_string($own) && $own !== '' ? $own : ($cookies[self::NAME] ?? '');

        return is_string($token) ? trim($token) : '';
    }
}
