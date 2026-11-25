<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Context;

use Semitexa\Core\Support\CoroutineLocal;

/**
 * The isomorphic page a request is rendering: its handle and its SSE session,
 * per coroutine under Swoole and process-wide in the CLI, through
 * CoroutineLocal (which owns that choice). Each value is set here as a string
 * and read back as one; anything else under its keys reads as unset.
 *
 * It also held the page's deferred slots, written on every render and read by
 * nothing: that store is gone.
 */
final class IsomorphicContextStore
{
    private const KEY_PAGE_HANDLE = '__ssr_iso_page_handle';
    private const KEY_SESSION_ID = '__ssr_iso_session_id';
    private const KEY_LATE_FITS = '__ssr_iso_late_fits';

    public static function setPageHandle(string $handle): void
    {
        CoroutineLocal::set(self::KEY_PAGE_HANDLE, $handle);
    }

    public static function getPageHandle(): string
    {
        return self::text(self::KEY_PAGE_HANDLE);
    }

    public static function setSessionId(string $sessionId): void
    {
        CoroutineLocal::set(self::KEY_SESSION_ID, $sessionId);
    }

    public static function getSessionId(): string
    {
        return self::text(self::KEY_SESSION_ID);
    }

    /**
     * A page with no deferred slots stores its request late, and only when a
     * deferred component renders. Whether that request fits is asked then, by
     * the first such component, not up front: the demo home page, which
     * renders none, encoded its 172 KB context on every request to learn it.
     *
     * @param (\Closure(): bool)|null $fits asked at most once per request; null when the request is stored up front
     */
    public static function setLateDeferral(?\Closure $fits): void
    {
        if ($fits === null) {
            CoroutineLocal::remove(self::KEY_LATE_FITS);

            return;
        }
        CoroutineLocal::set(self::KEY_LATE_FITS, $fits);
    }

    /** Whether a deferred component may defer: true unless a late request does not fit. */
    public static function lateDeferralFits(): bool
    {
        $fits = CoroutineLocal::get(self::KEY_LATE_FITS);
        if ($fits instanceof \Closure) {
            $fits = $fits() === true;
            CoroutineLocal::set(self::KEY_LATE_FITS, $fits);
        }

        return $fits !== false;
    }

    public static function reset(): void
    {
        CoroutineLocal::remove(self::KEY_PAGE_HANDLE);
        CoroutineLocal::remove(self::KEY_SESSION_ID);
        CoroutineLocal::remove(self::KEY_LATE_FITS);
    }

    private static function text(string $key): string
    {
        $value = CoroutineLocal::get($key);

        return is_string($value) ? $value : '';
    }
}
