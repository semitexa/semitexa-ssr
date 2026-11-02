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

    public static function reset(): void
    {
        CoroutineLocal::remove(self::KEY_PAGE_HANDLE);
        CoroutineLocal::remove(self::KEY_SESSION_ID);
    }

    private static function text(string $key): string
    {
        $value = CoroutineLocal::get($key);

        return is_string($value) ? $value : '';
    }
}
