<?php

declare(strict_types=1);

namespace Semitexa\Ssr\Application\Service\Asset;

/**
 * Coroutine-safe per-request storage for the AssetCollector instance.
 *
 * In Swoole mode, each coroutine gets its own collector via Coroutine::getContext().
 * In CLI/test mode, a static fallback is used.
 */
final class AssetCollectorStore
{
    private const KEY = '__ssr_asset_collector';

    private static ?AssetCollector $staticFallback = null;

    /**
     * Get the AssetCollector for the current request.
     * Creates one lazily if none exists.
     */
    public static function get(): AssetCollector
    {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            if (!isset($ctx[self::KEY])) {
                $ctx[self::KEY] = new AssetCollector();
            }
            return $ctx[self::KEY];
        }

        if (self::$staticFallback === null) {
            self::$staticFallback = new AssetCollector();
        }

        return self::$staticFallback;
    }

    /**
     * Use $collector in this coroutine: for one doing a request's work beside
     * it (a HUG fallback's slots resolving at once), so that what it requires
     * is in the request's own collector when its templates render. Without it
     * a slot's client modules were required in a collector that went with the
     * coroutine. Outside a coroutine there is one collector already.
     */
    public static function share(AssetCollector $collector): void
    {
        $context = self::inCoroutine() ? \Swoole\Coroutine::getContext() : null;
        if ($context instanceof \ArrayObject) {
            $context[self::KEY] = $collector;
        }
    }

    /**
     * Reset the collector for the current request.
     * Should be called at the start of each request in the Swoole handler.
     */
    public static function reset(): void
    {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            if (isset($ctx[self::KEY])) {
                $ctx[self::KEY]->reset();
                unset($ctx[self::KEY]);
            }
            return;
        }

        if (self::$staticFallback !== null) {
            self::$staticFallback->reset();
            self::$staticFallback = null;
        }
    }

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && \Swoole\Coroutine::getCid() > 0;
    }
}
