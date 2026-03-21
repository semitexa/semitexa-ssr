<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Context;

use Swoole\Coroutine;

final class IsomorphicContextStore
{
    private const KEY_PAGE_HANDLE = '__ssr_iso_page_handle';
    private const KEY_DEFERRED_SLOTS = '__ssr_iso_deferred_slots';
    private const KEY_SESSION_ID = '__ssr_iso_session_id';

    private static string $staticPageHandle = '';
    private static array $staticDeferredSlots = [];
    private static string $staticSessionId = '';

    public static function setPageHandle(string $handle): void
    {
        if (self::inCoroutine()) {
            Coroutine::getContext()[self::KEY_PAGE_HANDLE] = $handle;
            return;
        }
        self::$staticPageHandle = $handle;
    }

    public static function getPageHandle(): string
    {
        if (self::inCoroutine()) {
            return Coroutine::getContext()[self::KEY_PAGE_HANDLE] ?? '';
        }
        return self::$staticPageHandle;
    }

    public static function setDeferredSlots(array $slots): void
    {
        if (self::inCoroutine()) {
            Coroutine::getContext()[self::KEY_DEFERRED_SLOTS] = $slots;
            return;
        }
        self::$staticDeferredSlots = $slots;
    }

    public static function getDeferredSlots(): array
    {
        if (self::inCoroutine()) {
            return Coroutine::getContext()[self::KEY_DEFERRED_SLOTS] ?? [];
        }
        return self::$staticDeferredSlots;
    }

    public static function setSessionId(string $sessionId): void
    {
        if (self::inCoroutine()) {
            Coroutine::getContext()[self::KEY_SESSION_ID] = $sessionId;
            return;
        }
        self::$staticSessionId = $sessionId;
    }

    public static function getSessionId(): string
    {
        if (self::inCoroutine()) {
            return Coroutine::getContext()[self::KEY_SESSION_ID] ?? '';
        }
        return self::$staticSessionId;
    }

    public static function reset(): void
    {
        self::$staticPageHandle = '';
        self::$staticDeferredSlots = [];
        self::$staticSessionId = '';
    }

    private static function inCoroutine(): bool
    {
        return class_exists(Coroutine::class, false) && Coroutine::getCid() > 0;
    }
}
